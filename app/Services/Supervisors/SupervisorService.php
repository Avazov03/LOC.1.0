<?php

namespace App\Services\Supervisors;

use App\Enums\ActiveStatus;
use App\Enums\UserRole;
use App\Models\InternshipParticipant;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supervisor accounts (§14). A deactivated supervisor is signed out on the next request and loses all scope;
 * open periods stay as history until the admin replaces them (§58).
 */
class SupervisorService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function paginate(User $actor, ?string $search): LengthAwarePaginator
    {
        return $this->query($actor)
            ->with('user:id,name,login,email,status')
            ->withCount(['periods as open_internships_count' => fn ($query) => $query->whereNull('ends_on')])
            ->join('users', 'users.id', '=', 'supervisor_profiles.user_id')
            ->when($search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $q) => $q->whereLike('users.name', "%{$term}%")->orWhereLike('users.login', "%{$term}%"),
            ))
            ->orderBy('users.name')
            ->select('supervisor_profiles.*')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Active supervisors for the invite / internship picker (§14, §15).
     *
     * @return Collection<int, array{id: int, name: string, position: string}>
     */
    public function activeOptions(User $actor): Collection
    {
        return $this->query($actor)
            ->join('users', 'users.id', '=', 'supervisor_profiles.user_id')
            ->where('users.status', ActiveStatus::Active->value)
            ->orderBy('users.name')
            ->get(['supervisor_profiles.id', 'users.name', 'supervisor_profiles.position'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'position' => $row->position]);
    }

    /**
     * @param  array{name: string, login: string, email: ?string, password: string, phone: string, position: string}  $data
     */
    public function create(User $actor, array $data): SupervisorProfile
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $data) {
            $user = User::query()->create([
                'university_id' => $actor->university_id,
                'name' => $data['name'],
                'login' => $data['login'],
                'email' => $data['email'] ?: null,
                'password' => $data['password'],
                'role' => UserRole::Supervisor,
                'status' => ActiveStatus::Active,
            ]);

            $profile = SupervisorProfile::query()->create([
                'user_id' => $user->id,
                'university_id' => $actor->university_id,
                'phone' => $data['phone'],
                'position' => $data['position'],
            ]);
            $profile->setRelation('user', $user);
            $this->audit->log($actor, 'supervisor.create', $profile, null, $this->state($profile));

            return $profile;
        });
    }

    /**
     * @param  array{name: string, login: string, email: ?string, password: ?string, phone: string, position: string}  $data
     */
    public function update(User $actor, int $id, array $data): SupervisorProfile
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $id, $data) {
            $profile = $this->query($actor)->with('user')->lockForUpdate()->findOrFail($id);
            $before = $this->state($profile);

            $attributes = ['name' => $data['name'], 'login' => $data['login'], 'email' => $data['email'] ?: null];
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $attributes['password'] = $data['password'];
            }
            $profile->user->update($attributes);
            $profile->update(['phone' => $data['phone'], 'position' => $data['position']]);
            $after = $this->state($profile);

            if ($before !== $after || $passwordChanged) {
                $this->audit->log($actor, 'supervisor.update', $profile, $before, $after, metadata: $passwordChanged ? ['password_changed' => true] : []);
            }

            return $profile;
        });
    }

    public function setStatus(User $actor, int $id, ActiveStatus $status): SupervisorProfile
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $id, $status) {
            $profile = $this->query($actor)->with('user')->lockForUpdate()->findOrFail($id);
            $before = $profile->user->status;
            if ($before === $status) {
                return $profile;
            }
            $profile->user->update(['status' => $status]);
            $this->audit->log($actor, 'supervisor.status_change', $profile, ['status' => $before->value], ['status' => $status->value]);

            return $profile;
        });
    }

    public function find(User $actor, int $id): SupervisorProfile
    {
        return $this->query($actor)->with('user')->findOrFail($id);
    }

    /**
     * Profile, every supervisor period with its group, and the number of students under the open periods.
     *
     * @return array{profile: SupervisorProfile, periods: Collection<int, mixed>, open_students: int}
     */
    public function detail(User $actor, int $id): array
    {
        $profile = $this->find($actor, $id);
        $periods = $profile->periods()
            ->with(['internship' => fn ($query) => $query->withCount('participants')->with(['group.studyYear.program', 'academicYear'])])
            ->orderByRaw('ends_on IS NULL DESC')
            ->orderByDesc('starts_on')
            ->get();
        $openInternshipIds = $periods->whereNull('ends_on')->pluck('internship_id');

        return [
            'profile' => $profile,
            'periods' => $periods,
            'open_students' => InternshipParticipant::query()->whereIn('internship_id', $openInternshipIds)->distinct()->count('student_profile_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(SupervisorProfile $profile): array
    {
        return [
            'name' => $profile->user->name,
            'login' => $profile->user->login,
            'email' => $profile->user->email,
            'phone' => $profile->phone,
            'position' => $profile->position,
        ];
    }

    /**
     * @return Builder<SupervisorProfile>
     */
    private function query(User $actor): Builder
    {
        return SupervisorProfile::query()->where('supervisor_profiles.university_id', $actor->university_id);
    }
}
