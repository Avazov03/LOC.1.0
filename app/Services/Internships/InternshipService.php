<?php

namespace App\Services\Internships;

use App\Enums\ActiveStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Internship;
use App\Models\InternshipParticipant;
use App\Models\InternshipSupervisorPeriod;
use App\Models\StudentGroup;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internship groups (A4) and their supervisor history (A15, §58).
 */
class InternshipService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function paginate(User $actor): LengthAwarePaginator
    {
        return $this->scope->internships($actor)
            ->with(['group.studyYear.program', 'academicYear', 'currentPeriod.supervisor.user:id,name'])
            ->withCount([
                'participants',
                'assignments as active_assignments_count' => fn ($query) => $query->where('status', 'ACTIVE'),
            ])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Participants with their open assignment, eager-loaded for the cohort page (no N+1).
     *
     * @return Collection<int, InternshipParticipant>
     */
    public function participants(Internship $internship): Collection
    {
        return $internship->participants()
            ->with(['student.openAssignment.organization:id,name'])
            ->join('student_profiles', 'student_profiles.id', '=', 'internship_participants.student_profile_id')
            ->orderBy('student_profiles.last_name')
            ->orderBy('student_profiles.first_name')
            ->select('internship_participants.*')
            ->get();
    }

    public function create(User $actor, int $groupId, int $supervisorProfileId, string $periodStart, string $periodEnd): Internship
    {
        $this->scope->requireAdmin($actor);

        if ($periodEnd < $periodStart) {
            throw ValidationException::withMessages(['period_end' => 'Tugash sanasi boshlanish sanasidan oldin bo‘lmasligi kerak.']);
        }

        $group = StudentGroup::query()
            ->whereHas('studyYear.program.faculty', fn ($query) => $query->where('university_id', $actor->university_id))
            ->with('studyYear')
            ->findOrFail($groupId);
        $supervisor = $this->activeSupervisor($actor, $supervisorProfileId);

        return DB::transaction(function () use ($actor, $group, $supervisor, $periodStart, $periodEnd) {
            $internship = Internship::query()->create([
                'university_id' => $actor->university_id,
                'academic_year_id' => $group->studyYear->academic_year_id,
                'student_group_id' => $group->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'created_by' => $actor->id,
            ]);

            $period = InternshipSupervisorPeriod::query()->create([
                'internship_id' => $internship->id,
                'supervisor_profile_id' => $supervisor->id,
                'starts_on' => $actor->university->today(),
                'created_by' => $actor->id,
            ]);

            $this->audit->log($actor, 'internship.create', $internship, null, [
                'student_group_id' => $group->id,
                'academic_year_id' => $internship->academic_year_id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
            $this->audit->log($actor, 'internship.supervisor_assign', $internship, null, [
                'supervisor_profile_id' => $supervisor->id,
                'period_id' => $period->id,
            ]);

            return $internship;
        });
    }

    /**
     * Planned internship dates (A4). Group and academic year are the cohort's identity and stay fixed.
     * Assignment dates are not shifted; they are corrected one by one (A60).
     */
    public function updateDates(User $actor, int $internshipId, string $periodStart, string $periodEnd): Internship
    {
        $this->scope->requireAdmin($actor);

        if ($periodEnd < $periodStart) {
            throw ValidationException::withMessages(['period_end' => 'Tugash sanasi boshlanish sanasidan oldin bo‘lmasligi kerak.']);
        }

        return DB::transaction(function () use ($actor, $internshipId, $periodStart, $periodEnd) {
            $internship = $this->scope->internships($actor)->lockForUpdate()->findOrFail($internshipId);
            $before = ['period_start' => $internship->period_start->toDateString(), 'period_end' => $internship->period_end->toDateString()];
            $after = ['period_start' => $periodStart, 'period_end' => $periodEnd];

            if ($before !== $after) {
                $internship->update($after);
                $this->audit->log($actor, 'internship.update', $internship, $before, $after);
            }

            return $internship;
        });
    }

    /**
     * Closes the open period and opens the next one in one transaction (A15, A50).
     */
    public function replaceSupervisor(User $actor, int $internshipId, int $supervisorProfileId): InternshipSupervisorPeriod
    {
        $this->scope->requireAdmin($actor);
        $supervisor = $this->activeSupervisor($actor, $supervisorProfileId);

        return DB::transaction(function () use ($actor, $internshipId, $supervisor) {
            $internship = $this->scope->internships($actor)->lockForUpdate()->findOrFail($internshipId);
            $current = $internship->currentPeriod()->lockForUpdate()->first();

            if ($current?->supervisor_profile_id === $supervisor->id) {
                throw new BusinessRuleException('Bu rahbar allaqachon shu amaliyot guruhiga biriktirilgan.');
            }

            $today = $actor->university->today();
            $current?->update(['ends_on' => $today]);

            $period = InternshipSupervisorPeriod::query()->create([
                'internship_id' => $internship->id,
                'supervisor_profile_id' => $supervisor->id,
                'starts_on' => $today,
                'created_by' => $actor->id,
            ]);

            $this->audit->log(
                $actor,
                'internship.supervisor_replace',
                $internship,
                ['supervisor_profile_id' => $current?->supervisor_profile_id, 'period_id' => $current?->id],
                ['supervisor_profile_id' => $supervisor->id, 'period_id' => $period->id],
            );

            return $period;
        });
    }

    private function activeSupervisor(User $actor, int $supervisorProfileId): SupervisorProfile
    {
        $supervisor = SupervisorProfile::query()
            ->where('university_id', $actor->university_id)
            ->with('user')
            ->find($supervisorProfileId);

        if ($supervisor === null || $supervisor->user->status !== ActiveStatus::Active) {
            throw ValidationException::withMessages(['supervisor_profile_id' => 'Faol rahbarni tanlang.']);
        }

        return $supervisor;
    }
}
