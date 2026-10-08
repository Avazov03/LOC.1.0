<?php

namespace App\Services\Students;

use App\Enums\AssignmentStatus;
use App\Enums\StudentStatus;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Student directory and admin corrections (§12, §13, §123, A48). Lists go through AccessScope,
 * so the same query serves the admin directory and the supervisor's "students" page.
 */
class StudentService
{
    public const PLACEMENT_FILTERS = ['assigned', 'unassigned'];

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{search?: ?string, group?: ?int, internship?: ?int, status?: ?string, placement?: ?string}  $filters
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return $this->scope->students($actor)
            ->with(['currentGroup.studyYear.program', 'openAssignment.organization:id,name'])
            ->when($search, fn (Builder $query, string $term) => $query->where(fn (Builder $q) => $q
                ->whereLike('student_profiles.first_name', "%{$term}%")
                ->orWhereLike('student_profiles.last_name', "%{$term}%")
                ->orWhereLike('student_profiles.phone', "%{$term}%")
                ->orWhereLike('student_profiles.student_code', "%{$term}%")))
            ->when($filters['group'] ?? null, fn (Builder $query, int $group) => $query->where('student_profiles.current_group_id', $group))
            ->when($filters['internship'] ?? null, fn (Builder $query, int $internship) => $query->whereHas('participations', fn (Builder $q) => $q->where('internship_id', $internship)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('student_profiles.status', $status))
            ->when(($filters['placement'] ?? null) === 'assigned', fn (Builder $query) => $query->whereHas('assignments', fn (Builder $q) => $q->whereIn('status', AssignmentStatus::openValues())))
            ->when(($filters['placement'] ?? null) === 'unassigned', fn (Builder $query) => $query->whereDoesntHave('assignments', fn (Builder $q) => $q->whereIn('status', AssignmentStatus::openValues())))
            ->orderBy('student_profiles.last_name')
            ->orderBy('student_profiles.first_name')
            ->orderBy('student_profiles.id')
            ->paginate(25)
            ->withQueryString();
    }

    public function find(User $actor, int $id): StudentProfile
    {
        return $this->scope->findStudent($actor, $id)->load([
            'currentGroup.studyYear.program.faculty',
            'currentGroup.studyYear.academicYear',
            'memberships' => fn ($query) => $query->with(['group:id,name', 'academicYear:id,name'])->orderByDesc('joined_at'),
            'participations' => fn ($query) => $query->with(['internship.group:id,name', 'internship.academicYear:id,name', 'internship.currentPeriod.supervisor.user:id,name'])->orderByDesc('joined_at'),
        ]);
    }

    /**
     * Identity correction (§12, A48). The Telegram id is the identity anchor and is not editable here.
     *
     * @param  array{first_name: string, last_name: string, phone: string, student_code: ?string}  $data
     */
    public function update(User $actor, int $id, array $data, ?string $reason): StudentProfile
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $id, $data, $reason) {
            $student = $this->scope->students($actor)->lockForUpdate()->findOrFail($id);
            $code = isset($data['student_code']) && trim($data['student_code']) !== '' ? trim($data['student_code']) : null;
            if ($code !== null && StudentProfile::query()->where('university_id', $student->university_id)->where('student_code', $code)->whereKeyNot($student->id)->exists()) {
                throw ValidationException::withMessages(['student_code' => 'Bu talaba ID raqami allaqachon band.']);
            }

            $before = $this->identity($student);
            $student->update([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'phone' => trim($data['phone']),
                'student_code' => $code,
            ]);
            $student->user()->update(['name' => $student->fullName()]);
            $after = $this->identity($student);

            if ($before !== $after) {
                $this->audit->log($actor, 'student.update', $student, $before, $after, $reason);
            }

            return $student;
        });
    }

    /**
     * ACTIVE, INACTIVE, BLOCKED (§13). The profile and its history stay; nothing is deleted (A48).
     */
    public function setStatus(User $actor, int $id, StudentStatus $status, string $reason): StudentProfile
    {
        $this->scope->requireAdmin($actor);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Sababni yozing.']);
        }

        return DB::transaction(function () use ($actor, $id, $status, $reason) {
            $student = $this->scope->students($actor)->lockForUpdate()->findOrFail($id);
            if ($student->status === $status) {
                return $student;
            }

            $before = ['status' => $student->status->value];
            $student->update(['status' => $status]);
            $this->audit->log($actor, 'student.status_change', $student, $before, ['status' => $status->value], trim($reason));

            return $student;
        });
    }

    /**
     * @return array{first_name: string, last_name: string, phone: string, student_code: ?string}
     */
    private function identity(StudentProfile $student): array
    {
        return [
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'phone' => $student->phone,
            'student_code' => $student->student_code,
        ];
    }
}
