<?php

namespace App\Services\Attendance;

use App\Enums\ActiveStatus;
use App\Enums\PolicyScope;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendancePolicy;
use App\Models\StudentGroup;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §79, A32: a GROUP policy replaces the UNIVERSITY policy as a whole record. No field-by-field merge.
 */
class AttendancePolicyService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{rules: array<string, mixed>, source: string, policy_id: ?int}
     */
    public function resolve(StudentProfile $student): array
    {
        if ($student->current_group_id !== null) {
            $group = $this->active(PolicyScope::Group, $student->current_group_id);
            if ($group !== null) {
                return ['rules' => $group->rules(), 'source' => PolicyScope::Group->value, 'policy_id' => $group->id];
            }
        }

        $university = $this->active(PolicyScope::University, $student->university_id);
        if ($university !== null) {
            return ['rules' => $university->rules(), 'source' => PolicyScope::University->value, 'policy_id' => $university->id];
        }

        return ['rules' => AttendancePolicy::DEFAULTS, 'source' => 'DEFAULT', 'policy_id' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function universityRules(User $actor): array
    {
        $this->scope->requireAdmin($actor);

        return $this->active(PolicyScope::University, $actor->university_id)?->rules() ?? AttendancePolicy::DEFAULTS;
    }

    /**
     * @return Collection<int, AttendancePolicy>
     */
    public function groupPolicies(User $actor): Collection
    {
        $this->scope->requireAdmin($actor);

        return AttendancePolicy::query()
            ->where('university_id', $actor->university_id)
            ->where('scope_type', PolicyScope::Group->value)
            ->where('status', ActiveStatus::Active->value)
            ->with('group.studyYear.program')
            ->orderBy('scope_id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function saveUniversity(User $actor, array $rules): AttendancePolicy
    {
        $this->scope->requireAdmin($actor);

        return $this->save($actor, PolicyScope::University, $actor->university_id, $rules);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function saveGroup(User $actor, int $groupId, array $rules): AttendancePolicy
    {
        $this->scope->requireAdmin($actor);
        $group = StudentGroup::query()
            ->whereHas('studyYear.program.faculty', fn ($query) => $query->where('university_id', $actor->university_id))
            ->findOrFail($groupId);

        return $this->save($actor, PolicyScope::Group, $group->id, $rules);
    }

    public function deactivateGroup(User $actor, int $policyId): AttendancePolicy
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $policyId) {
            $policy = AttendancePolicy::query()
                ->where('university_id', $actor->university_id)
                ->where('scope_type', PolicyScope::Group->value)
                ->lockForUpdate()
                ->findOrFail($policyId);
            if ($policy->status !== ActiveStatus::Active) {
                throw new BusinessRuleException('Siyosat allaqachon o‘chirilgan.');
            }

            $before = $policy->auditState();
            $policy->fill(['status' => ActiveStatus::Inactive, 'updated_by' => $actor->id])->save();
            $this->audit->log($actor, 'attendance_policy.deactivate', $policy, $before, $policy->auditState());

            return $policy;
        });
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function save(User $actor, PolicyScope $scope, int $scopeId, array $rules): AttendancePolicy
    {
        $rules = $this->normalize($rules);

        try {
            return DB::transaction(function () use ($actor, $scope, $scopeId, $rules) {
                $policy = AttendancePolicy::query()
                    ->where('scope_type', $scope->value)
                    ->where('scope_id', $scopeId)
                    ->where('status', ActiveStatus::Active->value)
                    ->lockForUpdate()
                    ->first();

                $before = $policy?->auditState();
                $policy ??= new AttendancePolicy([
                    'university_id' => $actor->university_id,
                    'scope_type' => $scope,
                    'scope_id' => $scopeId,
                    'status' => ActiveStatus::Active,
                ]);
                $policy->fill([...$rules, 'updated_by' => $actor->id])->save();

                // A49: switching location off is an explicit override and is called out in the audit row.
                $metadata = ($before['location_required'] ?? true) && ! $rules['location_required'] ? ['location_override' => true] : [];
                $this->audit->log($actor, $before === null ? 'attendance_policy.create' : 'attendance_policy.update', $policy, $before, $policy->auditState(), metadata: $metadata);

                return $policy;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Siyosat bir vaqtda o‘zgartirildi. Sahifani yangilab qayta urinib ko‘ring.');
        }
    }

    private function active(PolicyScope $scope, int $scopeId): ?AttendancePolicy
    {
        return AttendancePolicy::query()
            ->where('scope_type', $scope->value)
            ->where('scope_id', $scopeId)
            ->where('status', ActiveStatus::Active->value)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function normalize(array $rules): array
    {
        // D3 + locked rule: multiple sessions per day stay OFF in every scope.
        if (! empty($rules['multiple_sessions_allowed'])) {
            throw ValidationException::withMessages(['multiple_sessions_allowed' => 'Bir kunda bir nechta sessiya qulflangan qoida bo‘yicha o‘chiq.']);
        }

        $clean = [];
        foreach (AttendancePolicy::RULE_FIELDS as $field) {
            $value = $rules[$field] ?? AttendancePolicy::DEFAULTS[$field];
            $clean[$field] = in_array($field, ['minimum_duration_minutes', 'accuracy_threshold_meters'], true)
                ? ($value === null || $value === '' || (int) $value === 0 ? null : (int) $value)
                : (bool) $value;
        }

        return $clean;
    }
}
