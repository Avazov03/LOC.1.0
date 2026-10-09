<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\InternshipAssignment;
use App\Models\InternshipChangeRequest;
use App\Models\InternshipParticipant;
use App\Models\StudentProfile;
use Carbon\CarbonInterface;

/**
 * Inertia payload shapes. Only fields the caller is allowed to see; no organization coordinates.
 */
class Present
{
    public static function dateTime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('Y-m-d H:i');
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignment(InternshipAssignment $assignment, string $timezone): array
    {
        return [
            'id' => $assignment->id,
            'student_id' => $assignment->student_profile_id,
            'student' => $assignment->relationLoaded('student') ? $assignment->student?->fullName() : null,
            'organization' => $assignment->organization?->name,
            'organization_id' => $assignment->organization_id,
            'group' => $assignment->relationLoaded('internship') ? $assignment->internship?->group?->name : null,
            'supervisor' => $assignment->relationLoaded('supervisor') ? $assignment->supervisor?->user?->name : null,
            'status' => $assignment->status->value,
            'start_at' => self::dateTime($assignment->start_at, $timezone),
            'end_at' => self::dateTime($assignment->end_at, $timezone),
            'ended_at' => self::dateTime($assignment->ended_at, $timezone),
            'cancel_reason' => $assignment->cancel_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function participant(InternshipParticipant $participant, string $timezone): array
    {
        $student = $participant->student;
        $open = $student->openAssignment;

        return [
            'id' => $student->id,
            'name' => $student->fullName(),
            'phone' => $student->phone,
            'student_code' => $student->student_code,
            'status' => $student->status->value,
            'joined_at' => self::dateTime($participant->joined_at, $timezone),
            'work_days' => $participant->work_days === null ? null : WorkDays::toDays($participant->work_days),
            'work_days_label' => $participant->work_days === null ? null : WorkDays::label($participant->work_days),
            'assignment' => $open ? [
                'id' => $open->id,
                'organization' => $open->organization?->name,
                'status' => $open->status->value,
                'start_at' => self::dateTime($open->start_at, $timezone),
                'end_at' => self::dateTime($open->end_at, $timezone),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function auditLog(AuditLog $log, string $timezone): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'actor' => ($log->actor?->name ?? 'Tizim')
                .(isset($log->metadata['impersonator_name']) ? ' (admin '.$log->metadata['impersonator_name'].' orqali)' : ''),
            'before' => $log->before,
            'after' => $log->after,
            'reason' => $log->reason,
            'created_at' => self::dateTime($log->created_at, $timezone),
        ];
    }

    /**
     * Directory row. Expects currentGroup.studyYear.program and openAssignment.organization loaded.
     *
     * @return array<string, mixed>
     */
    public static function studentRow(StudentProfile $student): array
    {
        $open = $student->openAssignment;

        return [
            'id' => $student->id,
            'name' => $student->fullName(),
            'phone' => $student->phone,
            'student_code' => $student->student_code,
            'group' => $student->currentGroup?->name,
            'program' => $student->currentGroup?->studyYear?->program?->name,
            'course' => $student->currentGroup?->studyYear?->name,
            'status' => $student->status->value,
            'assignment' => $open ? ['organization' => $open->organization?->name, 'status' => $open->status->value] : null,
        ];
    }

    /**
     * Profile, academic path and internship participations, as loaded by StudentService::find.
     * The Telegram user id is shown to admins only.
     *
     * @return array<string, mixed>
     */
    public static function studentDetail(StudentProfile $student, string $timezone, bool $admin): array
    {
        $group = $student->currentGroup;

        return [
            'id' => $student->id,
            'name' => $student->fullName(),
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'phone' => $student->phone,
            'student_code' => $student->student_code,
            'status' => $student->status->value,
            'telegram_user_id' => $admin ? (string) $student->telegram_user_id : null,
            'telegram_linked' => $student->telegram_user_id !== null,
            'registered_at' => self::dateTime($student->created_at, $timezone),
            'group' => $group?->name,
            'course' => $group?->studyYear?->name,
            'program' => $group?->studyYear?->program?->name,
            'faculty' => $group?->studyYear?->program?->faculty?->name,
            'academic_year' => $group?->studyYear?->academicYear?->name,
            'memberships' => $student->memberships->map(fn ($membership) => [
                'id' => $membership->id,
                'group' => $membership->group?->name,
                'academic_year' => $membership->academicYear?->name,
                'status' => $membership->status->value,
                'joined_at' => self::dateTime($membership->joined_at, $timezone),
                'ended_at' => self::dateTime($membership->ended_at, $timezone),
            ])->values(),
            'participations' => $student->participations->map(fn ($participation) => [
                'internship_id' => $participation->internship_id,
                'group' => $participation->internship?->group?->name,
                'academic_year' => $participation->internship?->academicYear?->name,
                'period_start' => $participation->internship?->period_start?->toDateString(),
                'period_end' => $participation->internship?->period_end?->toDateString(),
                'supervisor' => $participation->internship?->currentPeriod?->supervisor?->user?->name,
                'joined_at' => self::dateTime($participation->joined_at, $timezone),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function changeRequest(InternshipChangeRequest $request, string $timezone): array
    {
        return [
            'id' => $request->id,
            'student_id' => $request->student_profile_id,
            'student' => $request->student?->fullName(),
            'group' => $request->student?->currentGroup?->name,
            'type' => $request->request_type->value,
            'status' => $request->status->value,
            'current_organization' => $request->currentAssignment?->organization?->name,
            'requested_organization' => $request->requestedOrganization?->name,
            'requested_data' => $request->requested_organization_data,
            'reason' => $request->reason,
            'initiator' => $request->initiator?->name,
            'initiator_role' => $request->initiator?->role->value,
            'reviewer' => $request->reviewer?->name,
            'review_note' => $request->review_note,
            'created_at' => self::dateTime($request->created_at, $timezone),
            'reviewed_at' => self::dateTime($request->reviewed_at, $timezone),
        ];
    }
}
