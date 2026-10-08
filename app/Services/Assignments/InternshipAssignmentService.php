<?php

namespace App\Services\Assignments;

use App\Enums\AssignmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Internship;
use App\Models\InternshipAssignment;
use App\Models\InternshipParticipant;
use App\Models\Organization;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\StudentNotifier;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assignments (§21–§24, §57, D2, D5, A25).
 */
class InternshipAssignmentService
{
    public const MAX_BATCH = 500;

    private const CHUNK = 100;

    public const MSG_CONFLICT = 'Talabada ochiq (kutilayotgan yoki faol) biriktirish bor.';

    public const MSG_NOT_PARTICIPANT = 'Talaba bu amaliyot guruhida emas.';

    public const MSG_ORGANIZATION_INACTIVE = 'Tashkilot faol emas. Nofaol tashkilotga biriktirib bo‘lmaydi.';

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
        private readonly StudentNotifier $notifier,
    ) {}

    /**
     * One row per student (§22). Each student is its own transaction so one conflict does not hide the others
     * (API-CONTRACT §3). Admin: any participant of an internship in the university. Supervisor (D5): only
     * participants of internships they currently supervise, only to an ACTIVE organization.
     *
     * @param  list<int>  $studentIds
     * @return list<array{student_id: int, assignment_id: int|null, status: string|null, error: string|null}>
     */
    public function assign(User $actor, array $studentIds, int $organizationId, int $internshipId, CarbonInterface $startAt, CarbonInterface $endAt): array
    {
        $this->scope->requireStaff($actor);

        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === []) {
            throw ValidationException::withMessages(['student_ids' => 'Kamida bitta talabani tanlang.']);
        }
        if (count($studentIds) > self::MAX_BATCH) {
            throw ValidationException::withMessages(['student_ids' => 'Bir martada '.self::MAX_BATCH.' tadan ortiq talaba tanlab bo‘lmaydi.']);
        }
        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw ValidationException::withMessages(['end_date' => 'Tugash sanasi boshlanish sanasidan keyin bo‘lishi kerak.']);
        }

        $internship = $this->scope->findInternship($actor, $internshipId);
        $organization = $this->organization($actor, $organizationId);
        if (! $organization->isActive()) {
            throw new BusinessRuleException(self::MSG_ORGANIZATION_INACTIVE);
        }
        $supervisorProfileId = $internship->currentPeriod()->value('supervisor_profile_id');
        if ($supervisorProfileId === null) {
            throw new BusinessRuleException('Amaliyot guruhida joriy rahbar yo‘q.');
        }

        $results = [];
        foreach (array_chunk($studentIds, self::CHUNK) as $chunk) {
            $participants = InternshipParticipant::query()
                ->where('internship_id', $internship->id)
                ->whereIn('student_profile_id', $chunk)
                ->pluck('student_profile_id')
                ->flip();
            $open = InternshipAssignment::query()
                ->whereIn('student_profile_id', $chunk)
                ->whereIn('status', AssignmentStatus::openValues())
                ->pluck('student_profile_id')
                ->flip();

            foreach ($chunk as $studentId) {
                if (! $participants->has($studentId)) {
                    $results[] = $this->failure($studentId, self::MSG_NOT_PARTICIPANT);

                    continue;
                }
                if ($open->has($studentId)) {
                    $results[] = $this->failure($studentId, self::MSG_CONFLICT);

                    continue;
                }

                try {
                    $assignment = DB::transaction(fn () => $this->createOne($actor, $studentId, $organization, $internship, $supervisorProfileId, $startAt, $endAt));
                    $results[] = ['student_id' => $studentId, 'assignment_id' => $assignment->id, 'status' => $assignment->status->value, 'error' => null];
                } catch (BusinessRuleException $exception) {
                    $results[] = $this->failure($studentId, $exception->getMessage());
                } catch (UniqueConstraintViolationException) {
                    $results[] = $this->failure($studentId, self::MSG_CONFLICT);
                } catch (QueryException $exception) {
                    if (! $this->isInactiveOrganization($exception)) {
                        throw $exception;
                    }
                    $results[] = $this->failure($studentId, self::MSG_ORGANIZATION_INACTIVE);
                }
            }
        }

        return $results;
    }

    /**
     * Admin: PENDING → ACTIVE once start_at has arrived (A25).
     */
    public function activate(User $actor, int $assignmentId): InternshipAssignment
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $assignmentId) {
            $assignment = $this->query($actor)->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->status !== AssignmentStatus::Pending) {
                throw new BusinessRuleException('Faqat kutilayotgan biriktirishni faollashtirish mumkin.');
            }
            if ($assignment->start_at->isFuture()) {
                throw new BusinessRuleException('Biriktirish boshlanish vaqti hali kelmagan.');
            }

            $assignment = $this->transition($actor, $assignment, ['status' => AssignmentStatus::Active], 'assignment.activate');
            $this->notifier->assignmentStarted($assignment);

            return $assignment;
        });
    }

    /**
     * Admin date correction (§57). PENDING: start and end. ACTIVE: end only, because the start is already in force.
     * ENDED and CANCELLED rows are history and stay as they are. Organization and student never change here;
     * a different organization goes through a change request or end + new assignment (§24).
     */
    public function updateDates(User $actor, int $assignmentId, ?CarbonInterface $startAt, CarbonInterface $endAt): InternshipAssignment
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $assignmentId, $startAt, $endAt) {
            $assignment = $this->query($actor)->lockForUpdate()->findOrFail($assignmentId);
            $attributes = ['end_at' => $endAt->copy()->utc()];

            if ($assignment->status === AssignmentStatus::Pending) {
                if ($startAt !== null) {
                    $attributes['start_at'] = $startAt->copy()->utc();
                }
            } elseif ($assignment->status === AssignmentStatus::Active) {
                if ($startAt !== null && ! $startAt->equalTo($assignment->start_at)) {
                    throw new BusinessRuleException('Faol biriktirishning boshlanish vaqtini o‘zgartirib bo‘lmaydi.');
                }
            } else {
                throw new BusinessRuleException('Yakunlangan yoki bekor qilingan biriktirishni o‘zgartirib bo‘lmaydi.');
            }

            $newStart = $attributes['start_at'] ?? $assignment->start_at;
            if ($attributes['end_at']->lessThanOrEqualTo($newStart)) {
                throw ValidationException::withMessages(['end_date' => 'Tugash sanasi boshlanish sanasidan keyin bo‘lishi kerak.']);
            }
            if ($assignment->status === AssignmentStatus::Active && $attributes['end_at']->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['end_date' => 'Faol biriktirishning tugash vaqti kelajakda bo‘lishi kerak. Hozir yopish uchun “Yakunlash”dan foydalaning.']);
            }

            if ($assignment->status === AssignmentStatus::Pending && $newStart->lessThanOrEqualTo(now())) {
                $organizationActive = Organization::query()->whereKey($assignment->organization_id)->where('status', 'ACTIVE')->exists();
                if ($organizationActive) {
                    $attributes['status'] = AssignmentStatus::Active;
                }
            }

            $before = $assignment->auditState();
            $assignment->fill($attributes);
            if (! $assignment->isDirty()) {
                return $assignment;
            }
            $assignment->save();
            $this->audit->log($actor, 'assignment.update', $assignment, $before, $assignment->auditState());
            if ($before['status'] === AssignmentStatus::Pending->value && $assignment->status === AssignmentStatus::Active) {
                $this->notifier->assignmentStarted($assignment);
            }

            return $assignment;
        });
    }

    /**
     * Admin: ACTIVE → ENDED. The planned end_at is kept; ended_at records the real close.
     */
    public function end(User $actor, int $assignmentId, ?CarbonInterface $endedAt = null): InternshipAssignment
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $assignmentId, $endedAt) {
            $assignment = $this->query($actor)->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->status !== AssignmentStatus::Active) {
                throw new BusinessRuleException('Faqat faol biriktirishni yakunlash mumkin.');
            }

            return $this->transition($actor, $assignment, ['status' => AssignmentStatus::Ended, 'ended_at' => ($endedAt ?? now())->utc()], 'assignment.end');
        });
    }

    /**
     * Admin: PENDING or ACTIVE → CANCELLED, for a placement that should not have been in force.
     */
    public function cancel(User $actor, int $assignmentId, string $reason): InternshipAssignment
    {
        $this->scope->requireAdmin($actor);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Bekor qilish sababini yozing.']);
        }

        return DB::transaction(function () use ($actor, $assignmentId, $reason) {
            $assignment = $this->query($actor)->lockForUpdate()->findOrFail($assignmentId);
            if (! in_array($assignment->status, [AssignmentStatus::Pending, AssignmentStatus::Active], true)) {
                throw new BusinessRuleException('Yakunlangan yoki bekor qilingan biriktirishni o‘zgartirib bo‘lmaydi.');
            }

            $assignment = $this->transition($actor, $assignment, ['status' => AssignmentStatus::Cancelled, 'cancel_reason' => trim($reason)], 'assignment.cancel', $reason);
            $this->notifier->assignmentCancelled($assignment);

            return $assignment;
        });
    }

    /**
     * Scheduler: future PENDING rows whose start_at has arrived become ACTIVE when the organization is still ACTIVE.
     */
    public function activateDue(): int
    {
        $activated = 0;

        InternshipAssignment::query()
            ->where('status', AssignmentStatus::Pending->value)
            ->where('start_at', '<=', now())
            ->whereHas('organization', fn (Builder $query) => $query->where('status', 'ACTIVE'))
            ->with('internship:id,university_id')
            ->chunkById(self::CHUNK, function ($assignments) use (&$activated) {
                foreach ($assignments as $assignment) {
                    $activated += DB::transaction(function () use ($assignment) {
                        $fresh = InternshipAssignment::query()->lockForUpdate()->find($assignment->id);
                        if ($fresh?->status !== AssignmentStatus::Pending) {
                            return 0;
                        }
                        $before = $fresh->auditState();
                        $fresh->update(['status' => AssignmentStatus::Active]);
                        $this->audit->log(null, 'assignment.activate', $fresh, $before, $fresh->auditState(), universityId: $assignment->internship->university_id);
                        $this->notifier->assignmentStarted($fresh);

                        return 1;
                    });
                }
            });

        return $activated;
    }

    /**
     * Ends the current ACTIVE assignment and opens the replacement (§24, §26, STATE-MACHINES §6).
     * Runs inside the caller's transaction; callers are the change-request approval paths, which authorize first.
     */
    public function transfer(User $actor, InternshipAssignment $current, Organization $organization, string $reason): InternshipAssignment
    {
        if ($current->status !== AssignmentStatus::Active) {
            throw new BusinessRuleException('Talabaning joriy faol biriktirishi o‘zgargan. So‘rovni qayta ko‘rib chiqing.');
        }
        if (! $organization->isActive()) {
            throw new BusinessRuleException(self::MSG_ORGANIZATION_INACTIVE);
        }

        $now = now()->utc();
        if ($current->end_at->lessThanOrEqualTo($now)) {
            throw new BusinessRuleException('Joriy biriktirishning rejalashtirilgan muddati tugagan. Yangi biriktirish yarating.');
        }

        $internship = Internship::query()->findOrFail($current->internship_id);
        $supervisorProfileId = $internship->currentPeriod()->value('supervisor_profile_id') ?? $current->supervisor_profile_id;

        $this->transition($actor, $current, ['status' => AssignmentStatus::Ended, 'ended_at' => $now], 'assignment.end', $reason, $internship->university_id);

        return $this->createOne($actor, $current->student_profile_id, $organization, $internship, $supervisorProfileId, $now, $current->end_at, $reason);
    }

    public function paginate(User $actor, ?string $status, ?int $internshipId, ?int $organizationId): LengthAwarePaginator
    {
        return $this->query($actor)
            ->with(['student:id,first_name,last_name', 'organization:id,name', 'internship.group:id,name', 'supervisor.user:id,name'])
            ->when($status, fn (Builder $query, string $value) => $query->where('status', $value))
            ->when($internshipId, fn (Builder $query, int $value) => $query->where('internship_id', $value))
            ->when($organizationId, fn (Builder $query, int $value) => $query->where('organization_id', $value))
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Assignments visible to the actor: admin by university, supervisor by scoped students.
     *
     * @return Builder<InternshipAssignment>
     */
    public function query(User $actor): Builder
    {
        return InternshipAssignment::query()->whereIn(
            'internship_assignments.student_profile_id',
            $this->scope->students($actor)->select('student_profiles.id'),
        );
    }

    private function createOne(User $actor, int $studentId, Organization $organization, Internship $internship, int $supervisorProfileId, CarbonInterface $startAt, CarbonInterface $endAt, ?string $reason = null): InternshipAssignment
    {
        // Row lock serialises concurrent assignment of the same student; the partial unique index is the backstop.
        StudentProfile::query()->whereKey($studentId)->lockForUpdate()->firstOrFail();

        if (InternshipAssignment::query()->where('student_profile_id', $studentId)->whereIn('status', AssignmentStatus::openValues())->exists()) {
            throw new BusinessRuleException(self::MSG_CONFLICT);
        }

        $assignment = InternshipAssignment::query()->create([
            'student_profile_id' => $studentId,
            'organization_id' => $organization->id,
            'supervisor_profile_id' => $supervisorProfileId,
            'internship_id' => $internship->id,
            'start_at' => $startAt->copy()->utc(),
            'end_at' => $endAt->copy()->utc(),
            'status' => AssignmentStatus::Pending,
            'created_by' => $actor->id,
        ]);

        if ($startAt->lessThanOrEqualTo(now())) {
            $assignment->update(['status' => AssignmentStatus::Active]);
        }

        $this->audit->log($actor, 'assignment.create', $assignment, null, $assignment->auditState(), $reason, universityId: $internship->university_id);
        $this->notifier->assignmentCreated($assignment);

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(User $actor, InternshipAssignment $assignment, array $attributes, string $action, ?string $reason = null, ?int $universityId = null): InternshipAssignment
    {
        $before = $assignment->auditState();
        $assignment->update($attributes);
        $this->audit->log($actor, $action, $assignment, $before, $assignment->auditState(), $reason, universityId: $universityId ?? $actor->university_id);

        return $assignment;
    }

    private function organization(User $actor, int $organizationId): Organization
    {
        return Organization::query()->where('university_id', $actor->university_id)->findOrFail($organizationId);
    }

    private function isInactiveOrganization(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'is not ACTIVE');
    }

    /**
     * @return array{student_id: int, assignment_id: null, status: null, error: string}
     */
    private function failure(int $studentId, string $message): array
    {
        return ['student_id' => $studentId, 'assignment_id' => null, 'status' => null, 'error' => $message];
    }
}
