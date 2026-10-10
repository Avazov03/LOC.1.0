<?php

namespace App\Services\Attendance;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceEventType;
use App\Enums\EventSource;
use App\Enums\SessionStatus;
use App\Enums\StudentStatus;
use App\Enums\VerificationStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\InternshipAssignment;
use App\Models\StudentProfile;
use App\Models\University;
use App\Services\Notifications\SupervisorNotifier;
use App\Support\WorkDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Student check-in and check-out (§30, §31, §98, ATTENDANCE-RULES §1–§3). Web and Telegram call the same methods.
 * Time is always the server clock; the device timestamp is evidence only. A failed location attempt is stored (A26);
 * a duplicate press is a reply only (A27).
 */
class AttendanceService
{
    public const MAX_MESSAGE_AGE_SECONDS = 180;

    public const MAX_ACCURACY_METERS = 300;

    public function __construct(
        private readonly AttendancePolicyService $policies,
        private readonly LocationVerifier $verifier,
        private readonly WorkScheduleService $schedules,
        private readonly SupervisorNotifier $supervisors,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * Checks done when the student presses "start", before a location is requested. Writes no event.
     */
    public function prepareCheckIn(StudentProfile $student): AttendanceOutcome
    {
        $now = CarbonImmutable::now();
        $gate = $this->gate($student, $now);
        if ($gate instanceof AttendanceOutcome) {
            return $gate;
        }
        if (! $gate['policy']['check_in_enabled']) {
            return new AttendanceOutcome(AttendanceOutcome::CHECK_IN_DISABLED);
        }
        if ($notWorkDay = $this->notWorkDay($gate)) {
            return $notWorkDay;
        }

        $this->closeStaleFor($gate['student'], $gate['local_date'], $now);
        if ($this->openSession($student->id) !== null) {
            return new AttendanceOutcome(AttendanceOutcome::DUPLICATE_OPEN);
        }
        if (! $gate['policy']['multiple_sessions_allowed'] && $this->hasSessionOn($student->id, $gate['local_date'])) {
            return new AttendanceOutcome(AttendanceOutcome::SECOND_SESSION_BLOCKED);
        }

        return new AttendanceOutcome(AttendanceOutcome::READY, ['location_required' => $gate['policy']['location_required']]);
    }

    public function prepareCheckOut(StudentProfile $student): AttendanceOutcome
    {
        $now = CarbonImmutable::now();
        $gate = $this->gate($student, $now);
        if ($gate instanceof AttendanceOutcome) {
            return $gate;
        }

        $this->closeStaleFor($gate['student'], $gate['local_date'], $now);
        if ($this->openSession($student->id) === null) {
            return new AttendanceOutcome(AttendanceOutcome::NO_OPEN_SESSION);
        }
        if (! $gate['policy']['check_out_enabled']) {
            return new AttendanceOutcome(AttendanceOutcome::CHECK_OUT_DISABLED);
        }

        return new AttendanceOutcome(AttendanceOutcome::READY, ['location_required' => $gate['policy']['location_required']]);
    }

    public function checkIn(StudentProfile $student, ?LocationInput $location, EventSource $source = EventSource::Telegram, ?int $updateId = null): AttendanceOutcome
    {
        $now = CarbonImmutable::now();
        $gate = $this->gate($student, $now);
        if ($gate instanceof AttendanceOutcome) {
            return $this->recordGateFailure($gate, $student, AttendanceEventType::FailedCheckIn, null, $location, $now, $source, $updateId);
        }
        ['assignment' => $assignment, 'policy' => $policy, 'local_date' => $localDate] = $gate;
        if (! $policy['check_in_enabled']) {
            return new AttendanceOutcome(AttendanceOutcome::CHECK_IN_DISABLED);
        }
        // Not a failed attempt: nothing is expected that day, so no event is written.
        if ($notWorkDay = $this->notWorkDay($gate)) {
            return $notWorkDay;
        }

        $measure = null;
        $evidence = [];
        if ($policy['location_required']) {
            if ($location === null) {
                return new AttendanceOutcome(AttendanceOutcome::LOCATION_MISSING);
            }
            [$failure, $measure, $evidence] = $this->verify($student, $assignment, $location, $policy, $now, $localDate);
            if ($failure !== null) {
                return $this->fail($failure, AttendanceEventType::FailedCheckIn, $student, $assignment, null, $location, $measure, $now, $localDate, $source, $updateId, $policy, $evidence);
            }
        }

        try {
            return DB::transaction(function () use ($student, $assignment, $policy, $localDate, $location, $measure, $evidence, $now, $source, $updateId, $gate) {
                $locked = StudentProfile::query()->lockForUpdate()->find($student->id);
                if ($locked === null || $locked->status !== StudentStatus::Active) {
                    return new AttendanceOutcome(AttendanceOutcome::ACCESS_DENIED);
                }

                $this->closeStaleFor($locked, $localDate, $now);
                if ($this->openSession($student->id) !== null) {
                    return new AttendanceOutcome(AttendanceOutcome::DUPLICATE_OPEN);
                }
                if (! $policy['multiple_sessions_allowed'] && $this->hasSessionOn($student->id, $localDate)) {
                    return new AttendanceOutcome(AttendanceOutcome::SECOND_SESSION_BLOCKED);
                }

                // A71: with check-out disabled a verified check-in is the whole session.
                $closesNow = ! $policy['check_out_enabled'];
                $session = AttendanceSession::query()->create([
                    'student_profile_id' => $student->id,
                    'assignment_id' => $assignment->id,
                    'local_date' => $localDate,
                    'status' => $closesNow ? SessionStatus::Completed : SessionStatus::Open,
                    'opened_at' => $now,
                    'closed_at' => $closesNow ? $now : null,
                    'duration_seconds' => $closesNow ? 0 : null,
                ]);
                $event = $this->event([
                    'student_profile_id' => $student->id,
                    'assignment_id' => $assignment->id,
                    'session_id' => $session->id,
                    'event_type' => AttendanceEventType::CheckIn,
                    'verification_status' => VerificationStatus::Verified,
                ], $location, $measure, $now, $localDate, $source, $updateId, $policy, $evidence);
                $session->forceFill(['check_in_event_id' => $event->id])->save();

                $outcome = new AttendanceOutcome(AttendanceOutcome::CHECKED_IN, [
                    'time' => $now->setTimezone($gate['university']->timezone)->format('H:i'),
                    'organization' => $assignment->organization->name,
                    'distance' => $measure !== null ? (int) round($measure['distance']) : null,
                    'repeated_coordinates' => isset($evidence['repeated_coordinates_event_id']),
                ], $event);
                $this->supervisors->attendance($outcome, $gate['student'], $assignment);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel check-in won the one-open-session index, or this update id was already stored.
            return new AttendanceOutcome(AttendanceOutcome::DUPLICATE_OPEN);
        }
    }

    public function checkOut(StudentProfile $student, ?LocationInput $location, EventSource $source = EventSource::Telegram, ?int $updateId = null): AttendanceOutcome
    {
        $now = CarbonImmutable::now();
        $gate = $this->gate($student, $now);
        if ($gate instanceof AttendanceOutcome) {
            return $this->recordGateFailure($gate, $student, AttendanceEventType::FailedCheckOut, $this->openSession($student->id), $location, $now, $source, $updateId);
        }
        ['assignment' => $assignment, 'policy' => $policy, 'local_date' => $localDate] = $gate;

        $this->closeStaleFor($gate['student'], $localDate, $now);
        $open = $this->openSession($student->id);
        if ($open === null) {
            return new AttendanceOutcome(AttendanceOutcome::NO_OPEN_SESSION);
        }
        if (! $policy['check_out_enabled']) {
            return new AttendanceOutcome(AttendanceOutcome::CHECK_OUT_DISABLED);
        }

        $measure = null;
        $evidence = [];
        if ($policy['location_required']) {
            if ($location === null) {
                return new AttendanceOutcome(AttendanceOutcome::LOCATION_MISSING);
            }
            [$failure, $measure, $evidence] = $this->verify($student, $assignment, $location, $policy, $now, $localDate);
            if ($failure !== null) {
                // The session stays OPEN; no close time is invented (ATTENDANCE-RULES §2.6).
                return $this->fail($failure, AttendanceEventType::FailedCheckOut, $student, $assignment, $open, $location, $measure, $now, $localDate, $source, $updateId, $policy, $evidence);
            }
        }

        try {
            return DB::transaction(function () use ($student, $assignment, $policy, $localDate, $location, $measure, $evidence, $now, $source, $updateId, $gate) {
                StudentProfile::query()->lockForUpdate()->find($student->id);
                $session = AttendanceSession::query()
                    ->where('student_profile_id', $student->id)
                    ->where('status', SessionStatus::Open->value)
                    ->lockForUpdate()
                    ->first();
                if ($session === null) {
                    return new AttendanceOutcome(AttendanceOutcome::NO_OPEN_SESSION);
                }

                $event = $this->event([
                    'student_profile_id' => $student->id,
                    'assignment_id' => $assignment->id,
                    'session_id' => $session->id,
                    'event_type' => AttendanceEventType::CheckOut,
                    'verification_status' => VerificationStatus::Verified,
                ], $location, $measure, $now, $localDate, $source, $updateId, $policy, $evidence);

                $duration = max(0, $now->getTimestamp() - $session->opened_at->getTimestamp());
                $session->forceFill([
                    'check_out_event_id' => $event->id,
                    'status' => SessionStatus::Completed,
                    'closed_at' => $now,
                    'duration_seconds' => $duration,
                ])->save();

                $outcome = new AttendanceOutcome(AttendanceOutcome::CHECKED_OUT, [
                    'time' => $now->setTimezone($gate['university']->timezone)->format('H:i'),
                    'organization' => $assignment->organization->name,
                    'distance' => $measure !== null ? (int) round($measure['distance']) : null,
                    'duration_seconds' => $duration,
                    'repeated_coordinates' => isset($evidence['repeated_coordinates_event_id']),
                ], $event);
                $this->supervisors->attendance($outcome, $gate['student'], $assignment);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException) {
            return new AttendanceOutcome(AttendanceOutcome::NO_OPEN_SESSION);
        }
    }

    /**
     * A30: after local midnight an OPEN session from an earlier date becomes INCOMPLETE with a SYSTEM_ADJUSTMENT event.
     * closed_at stays null. Safe to run repeatedly.
     */
    public function closeStaleSessions(): int
    {
        $closed = 0;
        University::query()->each(function (University $university) use (&$closed) {
            $today = $university->today();
            AttendanceSession::query()
                ->where('status', SessionStatus::Open->value)
                ->where('local_date', '<', $today)
                ->whereIn('student_profile_id', StudentProfile::query()->select('id')->where('university_id', $university->id))
                ->select('id')
                ->chunkById(200, function ($sessions) use (&$closed) {
                    foreach ($sessions as $row) {
                        $closed += DB::transaction(function () use ($row) {
                            $session = AttendanceSession::query()->lockForUpdate()->find($row->id);
                            if ($session === null || $session->status !== SessionStatus::Open) {
                                return 0;
                            }
                            $this->markIncomplete($session, CarbonImmutable::now());

                            return 1;
                        });
                    }
                });
        });

        return $closed;
    }

    public function openSession(int $studentId): ?AttendanceSession
    {
        return AttendanceSession::query()
            ->where('student_profile_id', $studentId)
            ->where('status', SessionStatus::Open->value)
            ->first();
    }

    /**
     * @return array{student: StudentProfile, assignment: InternshipAssignment, policy: array<string, mixed>, university: University, local_date: string}|AttendanceOutcome
     */
    private function gate(StudentProfile $student, CarbonImmutable $now): array|AttendanceOutcome
    {
        $student = StudentProfile::query()->with('university')->find($student->id);
        if ($student === null || $student->status !== StudentStatus::Active) {
            return new AttendanceOutcome(AttendanceOutcome::ACCESS_DENIED);
        }
        $university = $student->university;
        $localDate = $now->setTimezone($university->timezone)->toDateString();

        $assignment = InternshipAssignment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', AssignmentStatus::Active->value)
            ->with(['organization:id,name,status', 'internship:id,period_start,period_end,work_days'])
            ->first();
        if ($assignment === null) {
            return new AttendanceOutcome(AttendanceOutcome::NO_ASSIGNMENT);
        }

        // §65: the period is a hard boundary with no grace time.
        $insideAssignment = $now->greaterThanOrEqualTo($assignment->start_at) && $now->lessThanOrEqualTo($assignment->end_at);
        $insideInternship = $localDate >= $assignment->internship->period_start->toDateString()
            && $localDate <= $assignment->internship->period_end->toDateString();
        if (! $insideAssignment || ! $insideInternship) {
            return new AttendanceOutcome(AttendanceOutcome::OUTSIDE_PERIOD, ['assignment_id' => $assignment->id]);
        }
        if (! $assignment->organization->isActive()) {
            return new AttendanceOutcome(AttendanceOutcome::ORGANIZATION_INACTIVE);
        }

        return [
            'student' => $student,
            'assignment' => $assignment,
            'policy' => $this->policies->resolve($student)['rules'],
            'university' => $university,
            'local_date' => $localDate,
        ];
    }

    /**
     * @param  array{student: StudentProfile, assignment: InternshipAssignment, local_date: string}  $gate
     */
    private function notWorkDay(array $gate): ?AttendanceOutcome
    {
        $holiday = $this->holidays->nameOn((int) $gate['student']->university_id, $gate['local_date']);
        if ($holiday !== null) {
            return new AttendanceOutcome(AttendanceOutcome::HOLIDAY, ['name' => $holiday]);
        }
        $mask = $this->schedules->maskFor($gate['student']->id, $gate['assignment']->internship);
        if (WorkDays::includes($mask, $gate['local_date'])) {
            return null;
        }

        return new AttendanceOutcome(AttendanceOutcome::NOT_WORK_DAY, ['days' => WorkDays::label($mask)]);
    }

    /**
     * Anti-spoofing order: forwarded, coordinates, message age, accuracy, radius, reused point.
     * The third element is evidence metadata stored on the event that is written next.
     *
     * @param  array<string, mixed>  $policy
     * @return array{0: ?string, 1: ?array<string, mixed>, 2: array<string, mixed>}
     */
    private function verify(StudentProfile $student, InternshipAssignment $assignment, LocationInput $location, array $policy, CarbonImmutable $now, string $localDate): array
    {
        if ($location->forwarded) {
            return [AttendanceOutcome::FORWARDED_LOCATION, null, []];
        }
        if (! LocationVerifier::validCoordinates($location->latitude, $location->longitude)) {
            return [AttendanceOutcome::INVALID_LOCATION, null, []];
        }
        // Telegram stamps the message on its own server; a location that reaches us late was not sent "now".
        if ($location->deviceTimestamp !== null && $now->getTimestamp() - $location->deviceTimestamp > self::MAX_MESSAGE_AGE_SECONDS) {
            return [AttendanceOutcome::STALE_LOCATION, null, []];
        }
        // Many Telegram clients send the location button's fix without horizontal_accuracy, so a missing accuracy
        // cannot tell a map-picked point from a real one (A81a). It is recorded for staff, not refused.
        $evidence = $location->accuracy === null && ! $location->live ? ['accuracy_missing' => true] : [];

        $measure = $this->verifier->measure($assignment->organization_id, $location->latitude, $location->longitude);
        if ($measure === null) {
            return [AttendanceOutcome::INVALID_LOCATION, null, []];
        }

        // A29: the policy threshold may be stricter; MAX_ACCURACY_METERS always applies.
        $threshold = min($policy['accuracy_threshold_meters'] ?? self::MAX_ACCURACY_METERS, self::MAX_ACCURACY_METERS);
        if ($location->accuracy !== null && $location->accuracy > $threshold) {
            return [AttendanceOutcome::LOW_ACCURACY, $measure, $evidence];
        }
        if (! $measure['within']) {
            return [AttendanceOutcome::OUTSIDE_RADIUS, $measure, $evidence];
        }

        [$code, $measure, $reuse] = $this->reusedPoint($student, $location, $localDate, $measure);

        return [$code, $measure, $evidence + $reuse];
    }

    /**
     * Two GPS fixes never repeat to 1 cm. The same point from another student, or from this student on another day,
     * is a saved or shared location and is refused. The same point again today (check-out copied from check-in,
     * or a retry with the phone's cached fix) is accepted but flagged for the supervisor.
     *
     * @param  array<string, mixed>  $measure
     * @return array{0: ?string, 1: ?array<string, mixed>, 2: array<string, mixed>}
     */
    private function reusedPoint(StudentProfile $student, LocationInput $location, string $localDate, array $measure): array
    {
        $earlier = AttendanceEvent::query()
            ->where('latitude', round($location->latitude, 7))
            ->where('longitude', round($location->longitude, 7))
            ->orderBy('id')
            ->get(['id', 'student_profile_id', 'local_date']);

        $foreign = $earlier->first(fn (AttendanceEvent $event) => (int) $event->student_profile_id !== $student->id || (string) $event->local_date !== $localDate);
        if ($foreign !== null) {
            return [AttendanceOutcome::REUSED_LOCATION, $measure, ['reused_event_id' => $foreign->id]];
        }

        return [null, $measure, $earlier->isEmpty() ? [] : ['repeated_coordinates_event_id' => $earlier->first()->id]];
    }

    /**
     * @param  array<string, mixed>|null  $measure
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $evidence
     */
    private function fail(string $code, AttendanceEventType $type, StudentProfile $student, ?InternshipAssignment $assignment, ?AttendanceSession $session, ?LocationInput $location, ?array $measure, CarbonImmutable $now, string $localDate, EventSource $source, ?int $updateId, array $policy = [], array $evidence = []): AttendanceOutcome
    {
        $status = match ($code) {
            AttendanceOutcome::OUTSIDE_RADIUS => VerificationStatus::OutsideRadius,
            AttendanceOutcome::LOW_ACCURACY => VerificationStatus::LowAccuracy,
            AttendanceOutcome::NO_ASSIGNMENT => VerificationStatus::NoAssignment,
            AttendanceOutcome::OUTSIDE_PERIOD => VerificationStatus::OutsideInternshipPeriod,
            default => VerificationStatus::InvalidLocation,
        };

        try {
            $event = $this->event([
                'student_profile_id' => $student->id,
                'assignment_id' => $assignment?->id,
                'session_id' => $session?->id,
                'event_type' => $type,
                'verification_status' => $status,
            ], $location, $measure, $now, $localDate, $source, $updateId, $policy, ['reason' => $code, ...$evidence]);
        } catch (UniqueConstraintViolationException) {
            $event = null;
        }

        return new AttendanceOutcome($code, [
            'distance' => $measure !== null ? (int) round($measure['distance']) : null,
            'radius' => $measure['radius'] ?? null,
            'accuracy' => $location?->accuracy !== null ? (int) round($location->accuracy) : null,
            'threshold' => min($policy['accuracy_threshold_meters'] ?? self::MAX_ACCURACY_METERS, self::MAX_ACCURACY_METERS),
        ], $event);
    }

    private function recordGateFailure(AttendanceOutcome $gate, StudentProfile $student, AttendanceEventType $type, ?AttendanceSession $session, ?LocationInput $location, CarbonImmutable $now, EventSource $source, ?int $updateId): AttendanceOutcome
    {
        if (! in_array($gate->code, [AttendanceOutcome::NO_ASSIGNMENT, AttendanceOutcome::OUTSIDE_PERIOD], true)) {
            return $gate;
        }

        $student->loadMissing('university');
        $assignment = isset($gate->data['assignment_id']) ? InternshipAssignment::query()->find($gate->data['assignment_id']) : null;
        $localDate = $now->setTimezone($student->university->timezone)->toDateString();
        $valid = $location !== null && ! $location->forwarded && LocationVerifier::validCoordinates($location->latitude, $location->longitude);

        return $this->fail($gate->code, $type, $student, $assignment, $session, $valid ? $location : null, null, $now, $localDate, $source, $updateId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $measure
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $metadata
     */
    private function event(array $attributes, ?LocationInput $location, ?array $measure, CarbonImmutable $now, string $localDate, EventSource $source, ?int $updateId, array $policy, array $metadata = []): AttendanceEvent
    {
        $storeCoordinates = $location !== null && LocationVerifier::validCoordinates($location->latitude, $location->longitude);

        return AttendanceEvent::query()->create([
            ...$attributes,
            'latitude' => $storeCoordinates ? round($location->latitude, 7) : null,
            'longitude' => $storeCoordinates ? round($location->longitude, 7) : null,
            'accuracy_meters' => $location?->accuracy !== null ? round($location->accuracy, 2) : null,
            'distance_meters' => $measure['distance'] ?? null,
            'organization_latitude_snapshot' => $measure['organization_latitude'] ?? null,
            'organization_longitude_snapshot' => $measure['organization_longitude'] ?? null,
            'radius_snapshot_meters' => $measure['radius'] ?? null,
            'occurred_at' => $now,
            'local_date' => $localDate,
            'source' => $source,
            'telegram_update_id' => $updateId,
            'metadata' => array_filter([
                ...$metadata,
                'location_override' => $policy !== [] && ! ($policy['location_required'] ?? true) ? true : null,
                'live_location' => $location?->live ?: null,
                'forwarded' => $location?->forwarded ?: null,
                'device_timestamp' => $location?->deviceTimestamp,
            ], fn ($value) => $value !== null),
        ]);
    }

    private function hasSessionOn(int $studentId, string $localDate): bool
    {
        return AttendanceSession::query()
            ->where('student_profile_id', $studentId)
            ->where('local_date', $localDate)
            ->exists();
    }

    private function closeStaleFor(StudentProfile $student, string $today, CarbonImmutable $now): void
    {
        $stale = AttendanceSession::query()
            ->where('student_profile_id', $student->id)
            ->where('status', SessionStatus::Open->value)
            ->where('local_date', '<', $today)
            ->first();
        if ($stale !== null) {
            DB::transaction(function () use ($stale, $now) {
                $session = AttendanceSession::query()->lockForUpdate()->find($stale->id);
                if ($session?->status === SessionStatus::Open) {
                    $this->markIncomplete($session, $now);
                }
            });
        }
    }

    private function markIncomplete(AttendanceSession $session, CarbonImmutable $now): void
    {
        AttendanceEvent::query()->create([
            'student_profile_id' => $session->student_profile_id,
            'assignment_id' => $session->assignment_id,
            'session_id' => $session->id,
            'event_type' => AttendanceEventType::SystemAdjustment,
            'verification_status' => VerificationStatus::NotApplicable,
            'occurred_at' => $now,
            'local_date' => $session->local_date,
            'source' => EventSource::System,
            'metadata' => ['reason' => 'NOT_CLOSED_BEFORE_MIDNIGHT'],
        ]);
        $session->forceFill(['status' => SessionStatus::Incomplete])->save();
    }
}
