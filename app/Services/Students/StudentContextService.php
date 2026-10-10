<?php

namespace App\Services\Students;

use App\Enums\AssignmentStatus;
use App\Enums\StudentStatus;
use App\Models\InternshipAssignment;
use App\Models\StudentProfile;
use App\Services\Attendance\WorkScheduleService;
use App\Support\WorkDays;

/**
 * What a student channel may read about the caller (§4.3, §64, A10, A48). Keyed by Telegram user id only;
 * username is never an identity. Channel-agnostic: the Phase 3 bot formats these arrays, nothing here knows Telegram.
 * No organization coordinates or radius leave this service.
 */
class StudentContextService
{
    /**
     * The caller's own ACTIVE profile. Unknown, INACTIVE and BLOCKED students get the same denial.
     */
    public function student(int $telegramUserId): StudentProfile
    {
        $student = StudentProfile::query()->where('telegram_user_id', $telegramUserId)->first();
        if ($student === null || $student->status !== StudentStatus::Active || ! $student->user()->where('status', 'ACTIVE')->exists()) {
            throw new StudentAccessException(StudentAccessException::ACCESS_DENIED);
        }

        return $student;
    }

    /**
     * @return array{student_id: int, name: string, phone: string, phone_verified: bool, student_code: ?string, university: string, program: ?string, course: ?string, group: ?string, assignment: ?array<string, mixed>}
     */
    public function profile(int $telegramUserId): array
    {
        $student = $this->student($telegramUserId)->load(['university:id,name,timezone', 'currentGroup.studyYear.program']);
        $assignment = $this->currentAssignment($student);

        return [
            'student_id' => $student->id,
            'name' => $student->fullName(),
            'phone' => $student->phone,
            'phone_verified' => $student->phone_verified_at !== null,
            'student_code' => $student->student_code,
            'university' => $student->university->name,
            'program' => $student->currentGroup?->studyYear?->program?->name,
            'course' => $student->currentGroup?->studyYear?->name,
            'group' => $student->currentGroup?->name,
            'assignment' => $assignment ? $this->presentAssignment($assignment, $student->university->timezone) : null,
        ];
    }

    /**
     * The ACTIVE assignment that later check-in and check-out will use (§30, A25). PENDING does not count.
     */
    public function activeAssignment(int $telegramUserId): InternshipAssignment
    {
        $student = $this->student($telegramUserId);
        $assignment = InternshipAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', AssignmentStatus::Active->value)
            ->with(['organization:id,name,address,status', 'supervisor.user:id,name', 'internship'])
            ->first();

        if ($assignment === null) {
            throw new StudentAccessException(StudentAccessException::NO_ACTIVE_ASSIGNMENT);
        }

        return $assignment;
    }

    private function currentAssignment(StudentProfile $student): ?InternshipAssignment
    {
        return InternshipAssignment::query()
            ->where('student_profile_id', $student->id)
            ->whereIn('status', AssignmentStatus::openValues())
            ->with(['organization:id,name,address', 'supervisor.user:id,name', 'internship:id,work_days'])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAssignment(InternshipAssignment $assignment, string $timezone): array
    {
        return [
            'id' => $assignment->id,
            'status' => $assignment->status->value,
            'organization' => $assignment->organization?->name,
            'address' => $assignment->organization?->address,
            'supervisor' => $assignment->supervisor?->user?->name,
            'start_at' => $assignment->start_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            'end_at' => $assignment->end_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            'work_days' => WorkDays::label(app(WorkScheduleService::class)->maskFor($assignment->student_profile_id, $assignment->internship)),
        ];
    }
}
