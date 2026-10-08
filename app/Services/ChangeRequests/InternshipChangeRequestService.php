<?php

namespace App\Services\ChangeRequests;

use App\Enums\AssignmentStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\InternshipAssignment;
use App\Models\InternshipChangeRequest;
use App\Models\Organization;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Assignments\InternshipAssignmentService;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\StudentNotifier;
use App\Services\Organizations\OrganizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Place change requests (§25–§28, §80, §133, A23, A24).
 */
class InternshipChangeRequestService
{
    /** Keys a new-organization description may carry. Coordinates and radius are never accepted (A23, §28). */
    public const NEW_ORGANIZATION_KEYS = ['name', 'type', 'address', 'contact_name', 'contact_phone', 'website'];

    private const FORBIDDEN_KEYS = ['latitude', 'longitude', 'lat', 'lng', 'lon', 'long', 'location', 'coordinates', 'point', 'radius', 'radius_meters', 'geo'];

    public const MSG_PENDING_EXISTS = 'Talabada ko‘rib chiqilmagan so‘rov allaqachon bor.';

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
        private readonly InternshipAssignmentService $assignments,
        private readonly OrganizationService $organizations,
        private readonly StudentNotifier $notifier,
    ) {}

    /**
     * Opened by the student (Telegram, Phase 3) or an in-scope supervisor (§4.2). Admin does not open requests (A24).
     */
    public function openExisting(User $actor, int $studentId, int $organizationId, string $reason): InternshipChangeRequest
    {
        [$student, $current] = $this->openContext($actor, $studentId);

        $organization = Organization::query()->where('university_id', $student->university_id)->find($organizationId);
        if ($organization === null || ! $organization->isActive()) {
            throw ValidationException::withMessages(['organization_id' => 'Faol tashkilotni tanlang.']);
        }
        if ($organization->id === $current->organization_id) {
            throw ValidationException::withMessages(['organization_id' => 'Talaba allaqachon shu tashkilotda.']);
        }

        return $this->store($actor, $student, $current, [
            'request_type' => ChangeRequestType::ExistingOrganization,
            'requested_organization_id' => $organization->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $organizationData
     */
    public function openNew(User $actor, int $studentId, array $organizationData, string $reason): InternshipChangeRequest
    {
        $data = $this->sanitizeNewOrganization($organizationData);
        [$student, $current] = $this->openContext($actor, $studentId);

        return $this->store($actor, $student, $current, [
            'request_type' => ChangeRequestType::NewOrganization,
            'requested_organization_data' => $data,
            'reason' => $reason,
        ]);
    }

    /**
     * Supervisor of the student's current internship, or admin. One transaction: end old, create new, approve, audit (§102).
     */
    public function approveExisting(User $actor, int $requestId): InternshipChangeRequest
    {
        $this->scope->requireStaff($actor);

        return DB::transaction(function () use ($actor, $requestId) {
            $request = $this->lockPending($actor, $requestId);
            if ($request->request_type !== ChangeRequestType::ExistingOrganization) {
                throw new BusinessRuleException('Yangi tashkilot so‘rovini faqat administrator tashkilotni yaratib tasdiqlaydi.');
            }

            $organization = Organization::query()->where('university_id', $actor->university_id)->lockForUpdate()->findOrFail($request->requested_organization_id);
            $assignment = $this->transferFor($actor, $request, $organization);

            return $this->decide($actor, $request, ChangeRequestStatus::Approved, null, ['new_assignment_id' => $assignment->id]);
        });
    }

    /**
     * Admin only. Creates the ACTIVE organization with the admin-entered point, then the assignment, then approves (§27).
     *
     * @param  array<string, mixed>  $organizationAttributes
     */
    public function approveNew(User $actor, int $requestId, array $organizationAttributes): InternshipChangeRequest
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $requestId, $organizationAttributes) {
            $request = $this->lockPending($actor, $requestId);
            if ($request->request_type !== ChangeRequestType::NewOrganization) {
                throw new BusinessRuleException('Bu so‘rov mavjud tashkilot uchun.');
            }

            $organization = $this->organizations->create($actor, [...$organizationAttributes, 'status' => 'ACTIVE']);
            $assignment = $this->transferFor($actor, $request, $organization);
            $request->requested_organization_id = $organization->id;

            return $this->decide($actor, $request, ChangeRequestStatus::Approved, null, [
                'new_assignment_id' => $assignment->id,
                'organization_id' => $organization->id,
            ]);
        });
    }

    /**
     * Admin: any type. Supervisor: existing-organization requests in scope (A24).
     */
    public function reject(User $actor, int $requestId, ?string $note): InternshipChangeRequest
    {
        $this->scope->requireStaff($actor);

        return DB::transaction(function () use ($actor, $requestId, $note) {
            $request = $this->lockPending($actor, $requestId);
            if ($actor->isSupervisor() && $request->request_type !== ChangeRequestType::ExistingOrganization) {
                throw new AuthorizationException;
            }

            return $this->decide($actor, $request, ChangeRequestStatus::Rejected, $note);
        });
    }

    /**
     * Student withdrawing their own request, or an admin withdrawing it (A24). Supervisors reject instead.
     */
    public function cancel(User $actor, int $requestId): InternshipChangeRequest
    {
        return DB::transaction(function () use ($actor, $requestId) {
            if ($actor->role === UserRole::Student) {
                $request = InternshipChangeRequest::query()
                    ->whereHas('student', fn (Builder $query) => $query->where('user_id', $actor->id))
                    ->lockForUpdate()
                    ->findOrFail($requestId);
            } else {
                $this->scope->requireAdmin($actor);
                $request = $this->visible($actor)->lockForUpdate()->findOrFail($requestId);
            }

            if ($request->status !== ChangeRequestStatus::Pending) {
                throw new BusinessRuleException('So‘rov allaqachon ko‘rib chiqilgan.');
            }

            return $this->decide($actor, $request, ChangeRequestStatus::Cancelled, null);
        });
    }

    public function paginate(User $actor, ?string $status): LengthAwarePaginator
    {
        return $this->visible($actor)
            ->with([
                'student:id,first_name,last_name,current_group_id',
                'student.currentGroup:id,name',
                'currentAssignment.organization:id,name',
                'requestedOrganization:id,name',
                'initiator:id,name,role',
                'reviewer:id,name',
            ])
            ->when($status, fn (Builder $query, string $value) => $query->where('status', $value))
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    public function find(User $actor, int $requestId): InternshipChangeRequest
    {
        return $this->visible($actor)->findOrFail($requestId);
    }

    /**
     * @return Builder<InternshipChangeRequest>
     */
    public function visible(User $actor): Builder
    {
        return InternshipChangeRequest::query()->whereIn(
            'internship_change_requests.student_profile_id',
            $this->scope->students($actor)->select('student_profiles.id'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function sanitizeNewOrganization(array $data): array
    {
        $forbidden = array_intersect(array_map('strtolower', array_keys($data)), self::FORBIDDEN_KEYS);
        if ($forbidden !== []) {
            throw ValidationException::withMessages(['organization' => 'Talaba yoki rahbar tashkilot joylashuvini yubora olmaydi. Joylashuvni administrator belgilaydi.']);
        }

        $unknown = array_diff(array_keys($data), self::NEW_ORGANIZATION_KEYS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['organization' => 'Ruxsat etilmagan maydon: '.implode(', ', $unknown).'.']);
        }

        $clean = [];
        foreach (self::NEW_ORGANIZATION_KEYS as $key) {
            $value = isset($data[$key]) ? trim((string) $data[$key]) : '';
            if ($value !== '') {
                $clean[$key] = mb_substr($value, 0, 255);
            }
        }
        if (! isset($clean['name'])) {
            throw ValidationException::withMessages(['organization.name' => 'Tashkilot nomini yozing.']);
        }

        return $clean;
    }

    /**
     * @return array{StudentProfile, InternshipAssignment}
     */
    private function openContext(User $actor, int $studentId): array
    {
        if ($actor->role === UserRole::Student) {
            $student = StudentProfile::query()->where('user_id', $actor->id)->findOrFail($studentId);
        } elseif ($actor->isSupervisor()) {
            $this->scope->requireStaff($actor);
            $student = $this->scope->findStudent($actor, $studentId);
        } else {
            throw new AuthorizationException;
        }

        $current = InternshipAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', AssignmentStatus::Active->value)
            ->first();
        if ($current === null) {
            throw new BusinessRuleException('Talabaning joriy faol biriktirishi yo‘q. Joy o‘zgartirish so‘rovi yaratilmaydi.');
        }

        return [$student, $current];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(User $actor, StudentProfile $student, InternshipAssignment $current, array $attributes): InternshipChangeRequest
    {
        if (trim((string) $attributes['reason']) === '') {
            throw ValidationException::withMessages(['reason' => 'Sababni yozing.']);
        }

        try {
            return DB::transaction(function () use ($actor, $student, $current, $attributes) {
                if (InternshipChangeRequest::query()->where('student_profile_id', $student->id)->where('status', ChangeRequestStatus::Pending->value)->exists()) {
                    throw new BusinessRuleException(self::MSG_PENDING_EXISTS);
                }

                $request = InternshipChangeRequest::query()->create([
                    ...$attributes,
                    'reason' => trim((string) $attributes['reason']),
                    'student_profile_id' => $student->id,
                    'current_assignment_id' => $current->id,
                    'status' => ChangeRequestStatus::Pending,
                    'initiated_by' => $actor->id,
                ]);

                $this->audit->log($actor, 'change_request.open', $request, null, $request->auditState(), $request->reason, universityId: $student->university_id);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException(self::MSG_PENDING_EXISTS);
        }
    }

    private function lockPending(User $actor, int $requestId): InternshipChangeRequest
    {
        $request = $this->visible($actor)->lockForUpdate()->findOrFail($requestId);
        if ($request->status !== ChangeRequestStatus::Pending) {
            throw new BusinessRuleException('So‘rov allaqachon ko‘rib chiqilgan.');
        }

        return $request;
    }

    private function transferFor(User $actor, InternshipChangeRequest $request, Organization $organization): InternshipAssignment
    {
        $current = InternshipAssignment::query()->lockForUpdate()->find($request->current_assignment_id);
        if ($current === null || $current->student_profile_id !== $request->student_profile_id) {
            throw new BusinessRuleException('Talabaning joriy faol biriktirishi o‘zgargan. So‘rovni qayta ko‘rib chiqing.');
        }

        return $this->assignments->transfer($actor, $current, $organization, "change_request:{$request->id}");
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function decide(User $actor, InternshipChangeRequest $request, ChangeRequestStatus $status, ?string $note, array $metadata = []): InternshipChangeRequest
    {
        $before = [...$request->auditState(), 'requested_organization_id' => $request->getOriginal('requested_organization_id')];

        $request->fill([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ])->save();

        $action = match ($status) {
            ChangeRequestStatus::Approved => 'change_request.approve',
            ChangeRequestStatus::Rejected => 'change_request.reject',
            ChangeRequestStatus::Cancelled => 'change_request.cancel',
            ChangeRequestStatus::Pending => throw new BusinessRuleException('Noto‘g‘ri holat.'),
        };

        $this->audit->log($actor, $action, $request, $before, $request->auditState(), $note, $metadata, $actor->university_id);
        $this->notifier->changeRequestDecided($request);

        return $request;
    }
}
