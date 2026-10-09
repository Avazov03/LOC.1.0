<?php

namespace App\Services\Attendance;

use App\Enums\AssignmentStatus;
use App\Enums\DayMarkKind;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceDayMark;
use App\Models\InternshipAssignment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff day marks: "Keldi" counts the day as attended without check-in or check-out (e.g. the student worked
 * elsewhere that day), "Sababli" keeps it out of "Kelmadi". Events are never touched; a mark is revoked, not deleted.
 * A supervisor marks only their own students and only the last SUPERVISOR_DAYS days; an admin any past day.
 */
class AttendanceMarkService
{
    public const SUPERVISOR_DAYS = 7;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function mark(User $actor, int $studentId, string $date, DayMarkKind $kind, ?string $note, string $source = 'WEB'): AttendanceDayMark
    {
        $this->scope->requireStaff($actor);
        $student = $this->scope->findStudent($actor, $studentId)->load('university');
        $this->assertDateAllowed($actor, $date, $student->university->today());
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        if ($kind === DayMarkKind::Excused && $note === null) {
            throw ValidationException::withMessages(['note' => 'Sababni yozing.']);
        }
        $assignment = $this->assignmentOn($student, $date);

        try {
            return DB::transaction(function () use ($actor, $student, $date, $kind, $note, $source, $assignment) {
                StudentProfile::query()->lockForUpdate()->find($student->id);
                $current = $this->active($student->id, $date);
                if ($current !== null && $current->kind === $kind && $current->note === $note) {
                    return $current;
                }
                if ($current !== null) {
                    $this->revokeRow($actor, $current, 'Yangi belgi bilan almashtirildi');
                }

                $mark = AttendanceDayMark::query()->create([
                    'student_profile_id' => $student->id,
                    'assignment_id' => $assignment->id,
                    'local_date' => $date,
                    'kind' => $kind,
                    'note' => $note,
                    'source' => $source,
                    'marked_by' => $actor->id,
                ]);
                $this->audit->log($actor, 'attendance.mark', $mark, $current?->auditState(), $mark->auditState(), $note, universityId: $student->university_id);

                return $mark;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel mark for the same day won; the day is marked either way.
            return $this->active($student->id, $date) ?? throw new BusinessRuleException('Belgini saqlab bo‘lmadi. Qayta urinib ko‘ring.');
        }
    }

    public function revoke(User $actor, int $markId, ?string $reason = null): void
    {
        $this->scope->requireStaff($actor);
        $mark = AttendanceDayMark::query()->whereNull('revoked_at')->findOrFail($markId);
        $student = $this->scope->findStudent($actor, $mark->student_profile_id)->load('university');
        $this->assertDateAllowed($actor, (string) $mark->local_date, $student->university->today());

        DB::transaction(function () use ($actor, $mark, $reason) {
            $locked = AttendanceDayMark::query()->lockForUpdate()->find($mark->id);
            if ($locked !== null && $locked->revoked_at === null) {
                $this->revokeRow($actor, $locked, $reason);
            }
        });
    }

    public function canMark(User $actor, string $date, string $today): bool
    {
        if ($date > $today) {
            return false;
        }

        return $actor->isAdmin() || $date >= CarbonImmutable::parse($today)->subDays(self::SUPERVISOR_DAYS)->toDateString();
    }

    private function assertDateAllowed(User $actor, string $date, string $today): void
    {
        if ($date > $today) {
            throw new BusinessRuleException('Kelajakdagi kunni belgilab bo‘lmaydi.');
        }
        if (! $this->canMark($actor, $date, $today)) {
            throw new BusinessRuleException('Rahbar faqat so‘nggi '.self::SUPERVISOR_DAYS.' kunni belgilay oladi. Eskiroq kun uchun administratorga murojaat qiling.');
        }
    }

    /**
     * The assignment in force on that local date; a mark outside every assignment would count a day nobody expected.
     */
    private function assignmentOn(StudentProfile $student, string $date): InternshipAssignment
    {
        $tz = $student->university->timezone;
        $assignment = InternshipAssignment::query()
            ->where('student_profile_id', $student->id)
            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Ended->value])
            ->where('start_at', '<=', CarbonImmutable::parse($date, $tz)->endOfDay()->utc())
            ->whereRaw('COALESCE(ended_at, end_at) >= ?', [CarbonImmutable::parse($date, $tz)->startOfDay()->utc()])
            ->orderByDesc('start_at')
            ->first();
        if ($assignment === null) {
            throw new BusinessRuleException('Bu sanada talabaning amaliyot biriktiruvi yo‘q.');
        }

        return $assignment;
    }

    private function active(int $studentId, string $date): ?AttendanceDayMark
    {
        return AttendanceDayMark::query()
            ->where('student_profile_id', $studentId)
            ->where('local_date', $date)
            ->whereNull('revoked_at')
            ->first();
    }

    private function revokeRow(User $actor, AttendanceDayMark $mark, ?string $reason): void
    {
        $mark->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
        $this->audit->log($actor, 'attendance.mark_revoke', $mark, $mark->auditState(), null, $reason, universityId: $actor->university_id);
    }
}
