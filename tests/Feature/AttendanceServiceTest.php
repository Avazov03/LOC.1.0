<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\AttendanceEventType;
use App\Enums\EventSource;
use App\Enums\SessionStatus;
use App\Enums\VerificationStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Services\Attendance\AttendanceOutcome;
use App\Services\Attendance\AttendancePolicyService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\LocationInput;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsInternships;
use Tests\Concerns\FreshCoordinates;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use BuildsInternships;
    use FreshCoordinates;
    use RefreshDatabase;

    private function service(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function inside(?float $accuracy = 10.0, bool $live = false): LocationInput
    {
        return new LocationInput($this->freshLatitude(41.3112), 69.2797, $accuracy, false, $live);
    }

    private function outside(): LocationInput
    {
        return new LocationInput(41.3135, 69.2797, 10.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function placed(): array
    {
        $world = $this->world();
        $world['assignment'] = $this->placement($world['internship'], $world['students'][0], $world['org']);
        $world['student'] = $world['students'][0];

        return $world;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function policy(array $world, array $rules): void
    {
        app(AttendancePolicyService::class)->saveUniversity($world['admin'], $rules);
    }

    public function test_verified_check_in_and_check_out_store_evidence_snapshots(): void
    {
        $world = $this->placed();

        $in = $this->service()->checkIn($world['student'], $this->inside(), EventSource::Telegram, 42);
        $this->assertSame(AttendanceOutcome::CHECKED_IN, $in->code);
        $this->assertSame(11, $in->data['distance']);

        $event = AttendanceEvent::query()->sole();
        $this->assertSame(AttendanceEventType::CheckIn, $event->event_type);
        $this->assertSame(VerificationStatus::Verified, $event->verification_status);
        $this->assertEqualsWithDelta(41.3112, $event->latitude, 1e-7);
        $this->assertEqualsWithDelta(41.3111, $event->organization_latitude_snapshot, 1e-6);
        $this->assertEqualsWithDelta(69.2797, $event->organization_longitude_snapshot, 1e-6);
        $this->assertSame(100, (int) $event->radius_snapshot_meters);
        $this->assertEqualsWithDelta(11.1, $event->distance_meters, 0.2);
        $this->assertSame(42, (int) $event->telegram_update_id);
        $this->assertSame(now('Asia/Tashkent')->toDateString(), $event->local_date);

        $world['org']->update(['radius_meters' => 500]);
        $this->travel(3)->hours();
        $out = $this->service()->checkOut($world['student'], $this->inside());
        $this->assertSame(AttendanceOutcome::CHECKED_OUT, $out->code);

        $session = AttendanceSession::query()->sole();
        $this->assertSame(SessionStatus::Completed, $session->status);
        $this->assertSame(3 * 3600, $session->duration_seconds);
        $this->assertSame(500, (int) AttendanceEvent::query()->where('event_type', 'CHECK_OUT')->value('radius_snapshot_meters'));
        $this->assertSame(100, (int) $event->fresh()->radius_snapshot_meters, 'The earlier snapshot is not rewritten.');
    }

    public function test_server_time_is_used_and_device_time_is_evidence_only(): void
    {
        $world = $this->placed();
        $deviceTime = now()->subSeconds(90)->getTimestamp();

        $this->service()->checkIn($world['student'], new LocationInput(41.3112, 69.2797, 5.0, false, false, $deviceTime));

        $event = AttendanceEvent::query()->sole();
        $this->assertSame(now()->getTimestamp(), $event->occurred_at->getTimestamp());
        $this->assertSame($deviceTime, $event->metadata['device_timestamp']);
    }

    public function test_outside_radius_attempt_is_stored_and_opens_no_session(): void
    {
        $world = $this->placed();

        $outcome = $this->service()->checkIn($world['student'], $this->outside());

        $this->assertSame(AttendanceOutcome::OUTSIDE_RADIUS, $outcome->code);
        $this->assertTrue($outcome->retryable());
        $event = AttendanceEvent::query()->sole();
        $this->assertSame(AttendanceEventType::FailedCheckIn, $event->event_type);
        $this->assertSame(VerificationStatus::OutsideRadius, $event->verification_status);
        $this->assertGreaterThan(100, $event->distance_meters);
        $this->assertNotNull($event->latitude);
        $this->assertSame(0, AttendanceSession::query()->count());
    }

    public function test_low_accuracy_is_refused_and_live_location_without_accuracy_passes(): void
    {
        $world = $this->placed();
        $this->policy($world, ['accuracy_threshold_meters' => 50]);

        $this->assertSame(AttendanceOutcome::LOW_ACCURACY, $this->service()->checkIn($world['student'], $this->inside(80.0))->code);
        $this->assertSame(VerificationStatus::LowAccuracy, AttendanceEvent::query()->sole()->verification_status);

        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], $this->inside(null, live: true))->code);
    }

    public function test_policy_cannot_loosen_accuracy_beyond_the_hard_cap(): void
    {
        $world = $this->placed();
        $this->policy($world, ['accuracy_threshold_meters' => 2000]);

        $outcome = $this->service()->checkIn($world['student'], $this->inside(AttendanceService::MAX_ACCURACY_METERS + 1.0));

        $this->assertSame(AttendanceOutcome::LOW_ACCURACY, $outcome->code);
        $this->assertSame(AttendanceService::MAX_ACCURACY_METERS, $outcome->data['threshold']);
    }

    public function test_point_picked_on_the_map_is_refused(): void
    {
        $world = $this->placed();

        $outcome = $this->service()->checkIn($world['student'], $this->inside(null));

        $this->assertSame(AttendanceOutcome::MAP_LOCATION, $outcome->code);
        $this->assertTrue($outcome->retryable());
        $event = AttendanceEvent::query()->sole();
        $this->assertSame(AttendanceEventType::FailedCheckIn, $event->event_type);
        $this->assertSame(AttendanceOutcome::MAP_LOCATION, $event->metadata['reason']);
        $this->assertSame(0, AttendanceSession::query()->count());
    }

    public function test_late_message_is_refused(): void
    {
        $world = $this->placed();
        $sent = now()->subSeconds(AttendanceService::MAX_MESSAGE_AGE_SECONDS + 1)->getTimestamp();

        $outcome = $this->service()->checkIn($world['student'], new LocationInput(41.3112, 69.2797, 5.0, false, false, $sent));

        $this->assertSame(AttendanceOutcome::STALE_LOCATION, $outcome->code);
        $this->assertSame(0, AttendanceSession::query()->count());
        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], new LocationInput(41.3112, 69.2797, 5.0, false, false, now()->getTimestamp()))->code);
    }

    public function test_point_sent_by_another_student_is_refused(): void
    {
        $world = $this->world();
        $first = $world['students'][0];
        $second = $world['students'][1];
        $this->placement($world['internship'], $first, $world['org']);
        $this->placement($world['internship'], $second, $world['org']);
        $point = new LocationInput(41.3112345, 69.2797, 8.0);

        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($first, $point)->code);
        $outcome = $this->service()->checkIn($second, $point);

        $this->assertSame(AttendanceOutcome::REUSED_LOCATION, $outcome->code);
        $failed = AttendanceEvent::query()->where('student_profile_id', $second->id)->sole();
        $this->assertSame(AttendanceEventType::FailedCheckIn, $failed->event_type);
        $this->assertSame(AttendanceEvent::query()->where('student_profile_id', $first->id)->value('id'), $failed->metadata['reused_event_id']);
        $this->assertSame(0, AttendanceSession::query()->where('student_profile_id', $second->id)->count());
    }

    public function test_own_point_from_another_day_is_refused(): void
    {
        $world = $this->placed();
        $point = new LocationInput(41.3112345, 69.2797, 8.0);

        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], $point)->code);
        $this->travel(2)->hours();
        $this->assertSame(AttendanceOutcome::CHECKED_OUT, $this->service()->checkOut($world['student'], $this->inside())->code);

        $this->travel(1)->days();
        $this->assertSame(AttendanceOutcome::REUSED_LOCATION, $this->service()->checkIn($world['student'], $point)->code);
        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], $this->inside())->code);
    }

    public function test_same_point_again_today_is_accepted_and_flagged(): void
    {
        $world = $this->placed();
        $point = new LocationInput(41.3112345, 69.2797, 8.0);

        $in = $this->service()->checkIn($world['student'], $point);
        $this->assertFalse($in->data['repeated_coordinates']);
        $this->travel(2)->hours();
        $out = $this->service()->checkOut($world['student'], $point);

        $this->assertSame(AttendanceOutcome::CHECKED_OUT, $out->code);
        $this->assertTrue($out->data['repeated_coordinates']);
        $checkIn = AttendanceEvent::query()->where('event_type', 'CHECK_IN')->sole();
        $checkOut = AttendanceEvent::query()->where('event_type', 'CHECK_OUT')->sole();
        $this->assertSame(VerificationStatus::Verified, $checkOut->verification_status);
        $this->assertSame($checkIn->id, $checkOut->metadata['repeated_coordinates_event_id']);
    }

    public function test_invalid_coordinates_are_stored_without_coordinates(): void
    {
        $world = $this->placed();

        $this->assertSame(AttendanceOutcome::INVALID_LOCATION, $this->service()->checkIn($world['student'], new LocationInput(200.0, 69.0, 5.0))->code);

        $event = AttendanceEvent::query()->sole();
        $this->assertSame(VerificationStatus::InvalidLocation, $event->verification_status);
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
    }

    public function test_no_assignment_and_outside_period_attempts_are_recorded(): void
    {
        $world = $this->world();
        $student = $world['students'][0];

        $this->assertSame(AttendanceOutcome::NO_ASSIGNMENT, $this->service()->checkIn($student, $this->inside())->code);
        $noAssignment = AttendanceEvent::query()->sole();
        $this->assertSame(VerificationStatus::NoAssignment, $noAssignment->verification_status);
        $this->assertNull($noAssignment->assignment_id);

        $assignment = $this->placement($world['internship'], $student, $world['org']);
        $assignment->update(['end_at' => now()->subMinute()]);
        $this->assertSame(AttendanceOutcome::OUTSIDE_PERIOD, $this->service()->checkIn($student, $this->inside())->code);
        $outside = AttendanceEvent::query()->latest('id')->first();
        $this->assertSame(VerificationStatus::OutsideInternshipPeriod, $outside->verification_status);
        $this->assertSame($assignment->id, $outside->assignment_id);
        $this->assertSame(0, AttendanceSession::query()->count());
    }

    public function test_inactive_organization_and_blocked_student_are_refused(): void
    {
        $world = $this->placed();
        $world['org']->update(['status' => ActiveStatus::Inactive]);
        $this->assertSame(AttendanceOutcome::ORGANIZATION_INACTIVE, $this->service()->checkIn($world['student'], $this->inside())->code);

        $world['org']->update(['status' => ActiveStatus::Active]);
        $world['student']->update(['status' => 'BLOCKED']);
        $this->assertSame(AttendanceOutcome::ACCESS_DENIED, $this->service()->checkIn($world['student'], $this->inside())->code);
        $this->assertSame(0, AttendanceSession::query()->count());
    }

    public function test_duplicate_check_in_creates_no_event_and_d3_blocks_a_second_session(): void
    {
        $world = $this->placed();
        $this->service()->checkIn($world['student'], $this->inside());

        $this->assertSame(AttendanceOutcome::DUPLICATE_OPEN, $this->service()->checkIn($world['student'], $this->inside())->code);
        $this->assertSame(AttendanceOutcome::DUPLICATE_OPEN, $this->service()->prepareCheckIn($world['student'])->code);
        $this->assertSame(1, AttendanceEvent::query()->count());

        $this->travel(1)->hours();
        $this->service()->checkOut($world['student'], $this->inside());
        $this->assertSame(AttendanceOutcome::SECOND_SESSION_BLOCKED, $this->service()->prepareCheckIn($world['student'])->code);
        $this->assertSame(AttendanceOutcome::SECOND_SESSION_BLOCKED, $this->service()->checkIn($world['student'], $this->inside())->code);
        $this->assertSame(1, AttendanceSession::query()->count());
        $this->assertSame(2, AttendanceEvent::query()->count());
    }

    public function test_failed_check_out_keeps_the_session_open(): void
    {
        $world = $this->placed();
        $this->service()->checkIn($world['student'], $this->inside());

        $this->assertSame(AttendanceOutcome::OUTSIDE_RADIUS, $this->service()->checkOut($world['student'], $this->outside())->code);

        $session = AttendanceSession::query()->sole();
        $this->assertSame(SessionStatus::Open, $session->status);
        $this->assertNull($session->closed_at);
        $failed = AttendanceEvent::query()->where('event_type', AttendanceEventType::FailedCheckOut->value)->sole();
        $this->assertSame($session->id, $failed->session_id);
    }

    public function test_check_out_without_an_open_session_is_refused(): void
    {
        $world = $this->placed();

        $this->assertSame(AttendanceOutcome::NO_OPEN_SESSION, $this->service()->prepareCheckOut($world['student'])->code);
        $this->assertSame(AttendanceOutcome::NO_OPEN_SESSION, $this->service()->checkOut($world['student'], $this->inside())->code);
        $this->assertSame(0, AttendanceEvent::query()->count());
    }

    public function test_check_out_disabled_makes_check_in_a_completed_session(): void
    {
        $world = $this->placed();
        $this->policy($world, ['check_out_enabled' => false]);

        $this->service()->checkIn($world['student'], $this->inside());

        $session = AttendanceSession::query()->sole();
        $this->assertSame(SessionStatus::Completed, $session->status);
        $this->assertSame(0, $session->duration_seconds);
        $this->assertSame(AttendanceOutcome::NO_OPEN_SESSION, $this->service()->prepareCheckOut($world['student'])->code);
    }

    public function test_check_in_disabled(): void
    {
        $world = $this->placed();
        $this->policy($world, ['check_in_enabled' => false]);

        $this->assertSame(AttendanceOutcome::CHECK_IN_DISABLED, $this->service()->prepareCheckIn($world['student'])->code);
        $this->assertSame(AttendanceOutcome::CHECK_IN_DISABLED, $this->service()->checkIn($world['student'], $this->inside())->code);
        $this->assertSame(0, AttendanceEvent::query()->count());
    }

    public function test_location_override_is_explicit_audited_and_marked_on_the_event(): void
    {
        $world = $this->placed();
        $this->assertSame(AttendanceOutcome::LOCATION_MISSING, $this->service()->checkIn($world['student'], null)->code);

        $this->policy($world, ['location_required' => false]);
        $audit = AuditLog::query()->where('action', 'attendance_policy.create')->sole();
        $this->assertTrue($audit->metadata['location_override']);

        $this->assertFalse($this->service()->prepareCheckIn($world['student'])->data['location_required']);
        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], null)->code);
        $event = AttendanceEvent::query()->sole();
        $this->assertTrue($event->metadata['location_override']);
        $this->assertNull($event->distance_meters);
        $this->assertNull($event->latitude);
    }

    public function test_stale_open_session_becomes_incomplete_after_midnight(): void
    {
        $world = $this->placed();
        $this->travelTo(now('Asia/Tashkent')->setTime(10, 0)->utc());
        $this->service()->checkIn($world['student'], $this->inside());

        $this->travelTo(now('Asia/Tashkent')->setTime(23, 59)->utc());
        $this->assertSame(0, $this->service()->closeStaleSessions());

        $this->travelTo(now('Asia/Tashkent')->addDay()->setTime(0, 5)->utc());
        $this->artisan('attendance:close-stale')->assertSuccessful();

        $session = AttendanceSession::query()->sole();
        $this->assertSame(SessionStatus::Incomplete, $session->status);
        $this->assertNull($session->closed_at);
        $adjustment = AttendanceEvent::query()->where('event_type', AttendanceEventType::SystemAdjustment->value)->sole();
        $this->assertSame(VerificationStatus::NotApplicable, $adjustment->verification_status);
        $this->assertSame(EventSource::System, $adjustment->source);
        $this->assertSame($session->local_date, $adjustment->local_date);
        $this->assertSame(0, $this->service()->closeStaleSessions(), 'Running again changes nothing.');

        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], $this->inside())->code, 'A new day starts fresh.');
    }

    public function test_check_in_closes_a_forgotten_session_from_yesterday_first(): void
    {
        $world = $this->placed();
        $this->travelTo(now('Asia/Tashkent')->setTime(10, 0)->utc());
        $this->service()->checkIn($world['student'], $this->inside());
        $this->travel(1)->days();

        $this->assertSame(AttendanceOutcome::CHECKED_IN, $this->service()->checkIn($world['student'], $this->inside())->code);
        $this->assertSame(['COMPLETED' => 0, 'INCOMPLETE' => 1, 'OPEN' => 1], [
            'COMPLETED' => AttendanceSession::query()->where('status', 'COMPLETED')->count(),
            'INCOMPLETE' => AttendanceSession::query()->where('status', 'INCOMPLETE')->count(),
            'OPEN' => AttendanceSession::query()->where('status', 'OPEN')->count(),
        ]);
    }

    public function test_attendance_events_cannot_be_updated_or_deleted(): void
    {
        $world = $this->placed();
        $this->service()->checkIn($world['student'], $this->inside());
        $id = AttendanceEvent::query()->value('id');

        try {
            DB::transaction(fn () => DB::table('attendance_events')->where('id', $id)->update(['verification_status' => 'OUTSIDE_RADIUS']));
            $this->fail('Update must be refused.');
        } catch (QueryException) {
        }
        try {
            DB::transaction(fn () => DB::table('attendance_events')->where('id', $id)->delete());
            $this->fail('Delete must be refused.');
        } catch (QueryException) {
        }
        $this->assertSame('VERIFIED', DB::table('attendance_events')->where('id', $id)->value('verification_status'));
    }

    public function test_one_open_session_per_student_is_enforced_by_the_database(): void
    {
        $world = $this->placed();
        $this->service()->checkIn($world['student'], $this->inside());

        $this->expectException(QueryException::class);
        DB::table('attendance_sessions')->insert([
            'student_profile_id' => $world['student']->id,
            'assignment_id' => $world['assignment']->id,
            'local_date' => now('Asia/Tashkent')->addDay()->toDateString(),
            'status' => 'OPEN',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ policy

    public function test_multiple_sessions_cannot_be_switched_on(): void
    {
        $world = $this->placed();

        try {
            $this->policy($world, ['multiple_sessions_allowed' => true]);
            $this->fail('Locked rule must hold.');
        } catch (ValidationException) {
        }

        $this->actingAs($world['admin'])->put('/attendance/policies/university', [
            'check_in_enabled' => true, 'check_out_enabled' => true, 'location_required' => true,
            'manual_correction_allowed' => true, 'multiple_sessions_allowed' => true,
        ])->assertSessionHasErrors('multiple_sessions_allowed');
        $this->assertSame(0, DB::table('attendance_policies')->count());

        if (! $this->isPgsql()) {
            return;
        }
        $this->expectException(QueryException::class);
        DB::table('attendance_policies')->insert([
            'university_id' => $world['university']->id, 'scope_type' => 'UNIVERSITY', 'scope_id' => $world['university']->id,
            'multiple_sessions_allowed' => true, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_group_policy_replaces_the_university_policy_as_a_whole(): void
    {
        $world = $this->placed();
        $service = app(AttendancePolicyService::class);
        $service->saveUniversity($world['admin'], ['minimum_duration_minutes' => 60, 'accuracy_threshold_meters' => 30]);

        $resolved = $service->resolve($world['student']);
        $this->assertSame('UNIVERSITY', $resolved['source']);
        $this->assertSame(60, $resolved['rules']['minimum_duration_minutes']);

        $group = $service->saveGroup($world['admin'], $world['group']->id, ['minimum_duration_minutes' => null]);
        $resolved = $service->resolve(StudentProfile::query()->find($world['student']->id));
        $this->assertSame('GROUP', $resolved['source']);
        $this->assertNull($resolved['rules']['minimum_duration_minutes']);
        $this->assertNull($resolved['rules']['accuracy_threshold_meters'], 'No field-by-field merge with the university policy.');

        $service->deactivateGroup($world['admin'], $group->id);
        $this->assertSame('UNIVERSITY', $service->resolve($world['student'])['source']);
        $this->assertSame(
            ['attendance_policy.create', 'attendance_policy.create', 'attendance_policy.deactivate'],
            AuditLog::query()->where('entity_type', 'attendance_policy')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_group_from_another_university_is_not_found(): void
    {
        $world = $this->placed();
        $other = $this->world('B', 5000);

        $this->actingAs($world['admin'])->post('/attendance/policies/groups', [
            'group_id' => $other['group']->id,
            'check_in_enabled' => true, 'check_out_enabled' => true, 'location_required' => true, 'manual_correction_allowed' => true,
        ])->assertNotFound();
    }

    public function test_defaults_apply_without_a_policy_row(): void
    {
        $world = $this->placed();

        $resolved = app(AttendancePolicyService::class)->resolve($world['student']);

        $this->assertSame('DEFAULT', $resolved['source']);
        $this->assertFalse($resolved['rules']['multiple_sessions_allowed']);
        $this->assertTrue($resolved['rules']['location_required']);
    }
}
