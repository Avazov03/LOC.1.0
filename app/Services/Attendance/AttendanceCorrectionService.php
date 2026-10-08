<?php

namespace App\Services\Attendance;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceEventType;
use App\Enums\EventSource;
use App\Enums\SessionStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
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
 * A33, ATTENDANCE-RULES §9: admin-only, a new MANUAL_CORRECTION event per corrected fact, originals untouched, audited.
 * occurred_at holds the corrected time; created_at holds when the correction was written.
 */
class AttendanceCorrectionService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
        private readonly AttendancePolicyService $policies,
    ) {}

    /**
     * Adds a session the student did not record. Without a check-out time it is OPEN today and INCOMPLETE for a past date.
     */
    public function addSession(User $actor, int $studentId, string $date, string $checkIn, ?string $checkOut, string $reason): AttendanceSession
    {
        $this->scope->requireAdmin($actor);
        $student = $this->scope->findStudent($actor, $studentId)->load('university');
        $policy = $this->allowed($student);
        $reason = $this->reason($reason);

        $tz = $student->university->timezone;
        $now = CarbonImmutable::now();
        $in = $this->at($date, $checkIn, $tz, 'check_in');
        $out = $checkOut !== null && $checkOut !== '' ? $this->at($date, $checkOut, $tz, 'check_out') : null;
        if ($in->greaterThan($now) || ($out !== null && $out->greaterThan($now))) {
            throw ValidationException::withMessages(['check_in' => 'Kelajakdagi vaqtni kiritib bo‘lmaydi.']);
        }
        if ($out !== null && $out->lessThanOrEqualTo($in)) {
            throw ValidationException::withMessages(['check_out' => 'Ketish vaqti kelish vaqtidan keyin bo‘lishi kerak.']);
        }

        $assignment = InternshipAssignment::query()
            ->where('student_profile_id', $student->id)
            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Ended->value])
            ->where('start_at', '<=', $in)
            ->where(fn ($query) => $query->where(DB::raw('COALESCE(ended_at, end_at)'), '>=', $in))
            ->orderByDesc('start_at')
            ->first();
        if ($assignment === null) {
            throw new BusinessRuleException('Bu sanada talabaning faol biriktirishi bo‘lmagan.');
        }

        $isToday = $date === $student->university->today();
        $status = $out !== null ? SessionStatus::Completed : ($isToday ? SessionStatus::Open : SessionStatus::Incomplete);

        try {
            return DB::transaction(function () use ($actor, $student, $policy, $assignment, $date, $in, $out, $status, $reason) {
                StudentProfile::query()->lockForUpdate()->find($student->id);
                $existing = AttendanceSession::query()->where('student_profile_id', $student->id)->where('local_date', $date)->get();
                if (! $policy['multiple_sessions_allowed'] && $existing->isNotEmpty()) {
                    throw new BusinessRuleException('Bu sanada sessiya allaqachon bor va siyosat bir kunda bir nechta sessiyaga ruxsat bermaydi. Mavjud sessiyani yoping.');
                }
                foreach ($existing as $other) {
                    $otherEnd = $other->closed_at ?? CarbonImmutable::now();
                    if ($in->lessThan($otherEnd) && ($out ?? $in)->greaterThan($other->opened_at)) {
                        throw new BusinessRuleException('Kiritilgan vaqt mavjud sessiya bilan ustma-ust tushadi.');
                    }
                }

                $session = AttendanceSession::query()->create([
                    'student_profile_id' => $student->id,
                    'assignment_id' => $assignment->id,
                    'local_date' => $date,
                    'status' => $status,
                    'opened_at' => $in,
                    'closed_at' => $out,
                    'duration_seconds' => $out !== null ? $out->getTimestamp() - $in->getTimestamp() : null,
                ]);
                $inEvent = $this->event($actor, $session, $in, $date, 'CHECK_IN', $reason);
                $outEvent = $out !== null ? $this->event($actor, $session, $out, $date, 'CHECK_OUT', $reason) : null;
                $session->forceFill(['check_in_event_id' => $inEvent->id, 'check_out_event_id' => $outEvent?->id])->save();

                $this->audit->log($actor, 'attendance.correction', $session, null, $session->auditState(), $reason, [
                    'kind' => 'ADD_SESSION',
                    'original_check_in' => null,
                    'student_profile_id' => $student->id,
                    'event_ids' => array_values(array_filter([$inEvent->id, $outEvent?->id])),
                ], $student->university_id);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('Talabada ochiq sessiya bor. Avval uni yoping.');
        }
    }

    /**
     * Closes an OPEN or INCOMPLETE session with an admin-entered check-out time.
     */
    public function closeSession(User $actor, int $sessionId, string $checkOut, string $reason): AttendanceSession
    {
        $this->scope->requireAdmin($actor);
        $session = AttendanceSession::query()
            ->whereIn('student_profile_id', $this->scope->students($actor)->select('student_profiles.id'))
            ->findOrFail($sessionId);
        $student = StudentProfile::query()->with('university')->findOrFail($session->student_profile_id);
        $this->allowed($student);
        $reason = $this->reason($reason);

        $out = $this->at($session->local_date, $checkOut, $student->university->timezone, 'check_out');
        if ($out->greaterThan(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['check_out' => 'Kelajakdagi vaqtni kiritib bo‘lmaydi.']);
        }

        return DB::transaction(function () use ($actor, $session, $student, $out, $reason) {
            $session = AttendanceSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status === SessionStatus::Completed) {
                throw new BusinessRuleException('Sessiya allaqachon yopilgan.');
            }
            if ($out->lessThanOrEqualTo($session->opened_at)) {
                throw ValidationException::withMessages(['check_out' => 'Ketish vaqti kelish vaqtidan keyin bo‘lishi kerak.']);
            }

            $before = $session->auditState();
            $event = $this->event($actor, $session, $out, $session->local_date, 'CHECK_OUT', $reason);
            $session->forceFill([
                'check_out_event_id' => $event->id,
                'status' => SessionStatus::Completed,
                'closed_at' => $out,
                'duration_seconds' => $out->getTimestamp() - $session->opened_at->getTimestamp(),
            ])->save();

            $this->audit->log($actor, 'attendance.correction', $session, $before, $session->auditState(), $reason, [
                'kind' => 'CLOSE_SESSION',
                'student_profile_id' => $student->id,
                'event_ids' => [$event->id],
            ], $student->university_id);

            return $session;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function allowed(StudentProfile $student): array
    {
        $policy = $this->policies->resolve($student)['rules'];
        if (! $policy['manual_correction_allowed']) {
            throw new BusinessRuleException('Bu guruh siyosati qo‘lda tuzatishga ruxsat bermaydi.');
        }

        return $policy;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Tuzatish sababini yozing.']);
        }

        return mb_substr($reason, 0, 1000);
    }

    private function at(string $date, string $time, string $timezone, string $field): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages([$field => 'Sana yoki vaqt noto‘g‘ri.']);
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone)->utc();
    }

    private function event(User $actor, AttendanceSession $session, CarbonImmutable $at, string $date, string $kind, string $reason): AttendanceEvent
    {
        return AttendanceEvent::query()->create([
            'student_profile_id' => $session->student_profile_id,
            'assignment_id' => $session->assignment_id,
            'session_id' => $session->id,
            'event_type' => AttendanceEventType::ManualCorrection,
            'verification_status' => VerificationStatus::Verified,
            'occurred_at' => $at,
            'local_date' => $date,
            'source' => EventSource::Manual,
            'actor_user_id' => $actor->id,
            'metadata' => ['kind' => $kind, 'reason' => $reason],
        ]);
    }
}
