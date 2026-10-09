<?php

namespace App\Services\Attendance;

use App\Models\Internship;
use App\Models\InternshipParticipant;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use App\Support\WorkDays;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which weekdays a student is expected at the internship: the internship's days, or the student's own override.
 * A day outside them is never "Kelmadi" and the bot does not accept a check-in on it.
 */
class WorkScheduleService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function maskFor(int $studentId, Internship $internship): int
    {
        $override = InternshipParticipant::query()
            ->where('internship_id', $internship->id)
            ->where('student_profile_id', $studentId)
            ->value('work_days');

        return (int) ($override ?? $internship->work_days ?? WorkDays::ALL);
    }

    /**
     * Admin only, like the internship dates.
     *
     * @param  list<int>  $isoDays
     */
    public function setInternship(User $actor, int $internshipId, array $isoDays): Internship
    {
        $this->scope->requireAdmin($actor);
        $mask = $this->mask($isoDays);

        return DB::transaction(function () use ($actor, $internshipId, $mask) {
            $internship = $this->scope->internships($actor)->lockForUpdate()->findOrFail($internshipId);
            if ($internship->work_days !== $mask) {
                $before = ['work_days' => WorkDays::label($internship->work_days)];
                $internship->update(['work_days' => $mask]);
                $this->audit->log($actor, 'internship.work_days', $internship, $before, ['work_days' => WorkDays::label($mask)]);
            }

            return $internship;
        });
    }

    /**
     * Admin, or the supervisor running the internship. Null days remove the override.
     *
     * @param  list<int>|null  $isoDays
     */
    public function setStudent(User $actor, int $internshipId, int $studentId, ?array $isoDays): InternshipParticipant
    {
        $this->scope->requireStaff($actor);
        $mask = $isoDays === null ? null : $this->mask($isoDays);

        return DB::transaction(function () use ($actor, $internshipId, $studentId, $mask) {
            $internship = $this->scope->findInternship($actor, $internshipId);
            $participant = InternshipParticipant::query()
                ->where('internship_id', $internship->id)
                ->where('student_profile_id', $studentId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($mask === $internship->work_days) {
                $mask = null;
            }

            if ($participant->work_days !== $mask) {
                $label = fn (?int $value) => $value === null ? 'guruh jadvali' : WorkDays::label($value);
                $before = ['work_days' => $label($participant->work_days)];
                $participant->update(['work_days' => $mask]);
                $this->audit->log($actor, 'internship.student_work_days', $internship, $before, ['work_days' => $label($mask)], metadata: ['student_profile_id' => $studentId]);
            }

            return $participant;
        });
    }

    /**
     * @param  list<int>  $isoDays
     */
    private function mask(array $isoDays): int
    {
        $days = array_unique(array_map('intval', $isoDays));
        if ($days === [] || array_diff($days, range(1, 7)) !== []) {
            throw ValidationException::withMessages(['work_days' => 'Kamida bitta hafta kunini tanlang.']);
        }

        return WorkDays::fromDays($days);
    }
}
