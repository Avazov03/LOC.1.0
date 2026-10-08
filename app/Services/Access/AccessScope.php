<?php

namespace App\Services\Access;

use App\Enums\ActiveStatus;
use App\Models\Internship;
use App\Models\InternshipParticipant;
use App\Models\InternshipSupervisorPeriod;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of what a staff user may see (PERMISSIONS.md §5).
 * Admin: own university. Supervisor: internships with an open supervisor period for them.
 * Anything outside the scope is "not found", so callers return 404.
 */
class AccessScope
{
    /** @var array<int, int|null> */
    private array $supervisorProfiles = [];

    /**
     * @return Builder<Internship>
     */
    public function internships(User $actor): Builder
    {
        $query = Internship::query()->where('internships.university_id', $actor->university_id);

        if ($actor->isAdmin() && $this->isActive($actor)) {
            return $query;
        }

        if ($actor->isSupervisor() && $this->isActive($actor)) {
            return $query->whereIn('internships.id', $this->openInternshipIds($actor));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @return Builder<StudentProfile>
     */
    public function students(User $actor): Builder
    {
        $query = StudentProfile::query()->where('student_profiles.university_id', $actor->university_id);

        if ($actor->isAdmin() && $this->isActive($actor)) {
            return $query;
        }

        if ($actor->isSupervisor() && $this->isActive($actor)) {
            return $query->whereIn(
                'student_profiles.id',
                InternshipParticipant::query()
                    ->select('student_profile_id')
                    ->whereIn('internship_id', $this->openInternshipIds($actor)),
            );
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @throws AuthorizationException
     */
    public function requireAdmin(User $actor): void
    {
        if (! $actor->isAdmin() || ! $this->isActive($actor)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function requireStaff(User $actor): void
    {
        if (! ($actor->isAdmin() || $actor->isSupervisor()) || ! $this->isActive($actor)) {
            throw new AuthorizationException;
        }
    }

    public function findInternship(User $actor, int $id): Internship
    {
        return $this->internships($actor)->findOrFail($id);
    }

    public function findStudent(User $actor, int $id): StudentProfile
    {
        return $this->students($actor)->findOrFail($id);
    }

    public function coversStudent(User $actor, int $studentProfileId): bool
    {
        return $this->students($actor)->whereKey($studentProfileId)->exists();
    }

    public function supervisorProfileId(User $actor): ?int
    {
        if (! array_key_exists($actor->id, $this->supervisorProfiles)) {
            $this->supervisorProfiles[$actor->id] = $actor->supervisorProfile()->value('id');
        }

        return $this->supervisorProfiles[$actor->id];
    }

    /**
     * @return Builder<InternshipSupervisorPeriod>
     */
    private function openInternshipIds(User $actor): Builder
    {
        return InternshipSupervisorPeriod::query()
            ->select('internship_id')
            ->whereNull('ends_on')
            ->where('supervisor_profile_id', $this->supervisorProfileId($actor) ?? 0);
    }

    private function isActive(User $actor): bool
    {
        return $actor->status === ActiveStatus::Active;
    }
}
