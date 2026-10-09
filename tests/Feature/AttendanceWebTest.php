<?php

namespace Tests\Feature;

use App\Enums\AttendanceEventType;
use App\Enums\EventSource;
use App\Enums\SessionStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\ReportExport;
use App\Models\StudentProfile;
use App\Services\Attendance\AttendanceDayQuery;
use App\Services\Attendance\AttendancePolicyService;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\LocationInput;
use App\Services\Reports\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class AttendanceWebTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    private function at(int $hour, int $minute = 0): void
    {
        $this->travelTo(now('Asia/Tashkent')->setTime($hour, $minute)->utc());
    }

    private function inside(): LocationInput
    {
        return new LocationInput(41.3112, 69.2797, 10.0);
    }

    /**
     * Seven students on one day: PRESENT (5 h), PRESENT (1 h), INCOMPLETE, LOCATION_REJECTED, ABSENT (expected), ABSENT (failed), and one with nothing.
     *
     * @return array<string, mixed>
     */
    private function scenarioDay(): array
    {
        $this->at(8);
        $world = $this->world();
        $extra = [];
        foreach (['Sobirov', 'Toshev', 'Umarov', 'Xoliqov', 'Yusupov'] as $i => $name) {
            $extra[] = $this->student($world['university'], $world['group'], 2000 + $i, $name);
        }
        $this->enroll($world['internship'], ...$extra);
        $students = [...$world['students'], ...$extra];
        foreach (array_slice($students, 0, 6) as $student) {
            $this->placement($world['internship'], $student, $world['org']);
        }
        $service = app(AttendanceService::class);
        [$present, $short, $incomplete, $rejected, $absent, $failed, $none] = $students;

        $this->at(9);
        $service->checkIn($present, $this->inside());
        $service->checkIn($short, $this->inside());
        $service->checkIn($incomplete, $this->inside());
        $service->checkIn($rejected, new LocationInput(41.3135, 69.2797, 10.0));
        $service->checkIn($failed, new LocationInput(200.0, 0.0, 10.0));
        $this->at(10);
        $service->checkOut($short, $this->inside());
        $this->at(14);
        $service->checkOut($present, $this->inside());

        return [...$world, 'cast' => compact('present', 'short', 'incomplete', 'rejected', 'absent', 'failed', 'none')];
    }

    public function test_day_status_formula_covers_every_status(): void
    {
        $world = $this->scenarioDay();
        $today = now('Asia/Tashkent')->toDateString();

        $rows = app(AttendanceDayQuery::class)
            ->rows(StudentProfile::query()->where('university_id', $world['university']->id), [$today], 'Asia/Tashkent')
            ->get()
            ->keyBy('student_profile_id');

        $expected = [
            'present' => 'PRESENT',
            'short' => 'PRESENT',
            'incomplete' => 'INCOMPLETE',
            'rejected' => 'LOCATION_REJECTED',
            'absent' => 'ABSENT',
            'failed' => 'ABSENT',
            'none' => null,
        ];
        foreach ($expected as $who => $status) {
            $this->assertSame($status, $rows[$world['cast'][$who]->id]->day_status, $who);
        }
        $this->assertSame(5 * 3600, (int) $rows[$world['cast']['present']->id]->completed_seconds);
        $this->assertSame(3600, (int) $rows[$world['cast']['short']->id]->completed_seconds);

        $totals = app(AttendanceDayQuery::class)->totals(StudentProfile::query()->where('university_id', $world['university']->id), $today, 'Asia/Tashkent');
        $this->assertSame(['PRESENT' => 2, 'INCOMPLETE' => 1, 'LOCATION_REJECTED' => 1, 'EXCUSED' => 0, 'ABSENT' => 2, 'EXPECTED' => 6], $totals);
    }

    public function test_an_old_minimum_duration_no_longer_changes_the_day_status(): void
    {
        $world = $this->scenarioDay();
        $today = now('Asia/Tashkent')->toDateString();
        app(AttendancePolicyService::class)->saveUniversity($world['admin'], ['minimum_duration_minutes' => 240]);

        $row = app(AttendanceDayQuery::class)->rows(StudentProfile::query()->whereKey($world['cast']['short']->id), [$today], 'Asia/Tashkent')->first();

        $this->assertSame('PRESENT', $row->day_status);
        $this->assertSame(3600, (int) $row->completed_seconds);
    }

    public function test_date_range_covers_a_year_and_is_capped(): void
    {
        $this->assertCount(92, AttendanceDayQuery::dateRange('2026-09-01', '2026-12-01'));

        $dates = AttendanceDayQuery::dateRange('2025-01-01', '2026-12-31');
        $this->assertCount(AttendanceDayQuery::MAX_RANGE_DAYS, $dates);
        $this->assertSame('2026-12-31', end($dates));
    }

    // ------------------------------------------------------------ pages and scope

    public function test_admin_attendance_page_lists_statuses_and_filters(): void
    {
        $world = $this->scenarioDay();

        $this->actingAs($world['admin'])->get('/attendance')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Attendance/Index')
            ->where('totals.EXPECTED', 6)
            ->has('rows.data', 6)
            ->has('options.groups')
            ->has('statuses', 5));

        $this->actingAs($world['admin'])->get('/attendance?status=ABSENT')->assertInertia(fn ($page) => $page->has('rows.data', 2));
        $this->actingAs($world['admin'])->get('/attendance?search=Sobirov')->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.status', 'INCOMPLETE'));
    }

    public function test_student_attendance_page_shows_evidence(): void
    {
        $world = $this->scenarioDay();

        $this->actingAs($world['admin'])->get('/attendance/students/'.$world['cast']['rejected']->id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Attendance/Student')
            ->where('days.0.status', 'LOCATION_REJECTED')
            ->where('events.0.verification', 'OUTSIDE_RADIUS')
            ->where('events.0.radius', 100)
            ->where('canCorrect', true));
    }

    public function test_supervisor_sees_only_own_students_and_cannot_correct(): void
    {
        $world = $this->scenarioDay();
        $other = $this->world('B', 7000);
        $this->placement($other['internship'], $other['students'][0], $other['org']);
        $foreignSupervisor = $this->supervisorProfile($world['university'], 'Boshqa');

        $this->actingAs($world['supervisor'])->get('/attendance')->assertOk()->assertInertia(fn ($page) => $page->where('totals.EXPECTED', 6));
        $this->actingAs($world['supervisor'])->get('/attendance/students/'.$world['cast']['present']->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canCorrect', false));

        $this->actingAs($world['supervisor'])->get('/attendance/students/'.$other['students'][0]->id)->assertNotFound();
        $this->actingAs($foreignSupervisor->user)->get('/attendance/students/'.$world['cast']['present']->id)->assertNotFound();
        $this->actingAs($foreignSupervisor->user)->get('/attendance')->assertInertia(fn ($page) => $page->where('totals.EXPECTED', 0));
        $this->actingAs($world['admin'])->get('/attendance/students/'.$other['students'][0]->id)->assertNotFound();

        $session = AttendanceSession::query()->where('student_profile_id', $world['cast']['incomplete']->id)->sole();
        $this->actingAs($world['supervisor'])->post('/attendance/students/'.$world['cast']['absent']->id.'/corrections', [
            'date' => now('Asia/Tashkent')->toDateString(), 'check_in' => '09:00', 'check_out' => '10:00', 'reason' => 'Test',
        ])->assertForbidden();
        $this->actingAs($world['supervisor'])->post("/attendance/sessions/{$session->id}/close", ['check_out' => '10:00', 'reason' => 'Test'])->assertForbidden();
        $this->actingAs($world['supervisor'])->get('/attendance/policies')->assertForbidden();
        $this->actingAs($world['supervisor'])->put('/attendance/policies/university', [])->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/attendance', '/attendance/policies', '/reports'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    // ------------------------------------------------------------ corrections

    public function test_admin_adds_a_missing_session_with_reason_and_audit(): void
    {
        $world = $this->scenarioDay();
        $absent = $world['cast']['absent'];
        $today = now('Asia/Tashkent')->toDateString();
        $this->at(16);

        $this->actingAs($world['admin'])->post("/attendance/students/{$absent->id}/corrections", [
            'date' => $today, 'check_in' => '09:00', 'check_out' => '15:00', 'reason' => 'Telefon buzilgan, rahbar tasdiqladi',
        ])->assertSessionHas('success');

        $session = AttendanceSession::query()->where('student_profile_id', $absent->id)->sole();
        $this->assertSame(SessionStatus::Completed, $session->status);
        $this->assertSame(6 * 3600, $session->duration_seconds);
        $events = AttendanceEvent::query()->where('session_id', $session->id)->orderBy('occurred_at')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame(AttendanceEventType::ManualCorrection, $event->event_type);
            $this->assertSame(EventSource::Manual, $event->source);
            $this->assertSame($world['admin']->id, $event->actor_user_id);
            $this->assertSame('Telefon buzilgan, rahbar tasdiqladi', $event->metadata['reason']);
        }
        $this->assertSame(['CHECK_IN', 'CHECK_OUT'], $events->pluck('metadata.kind')->all());

        $audit = AuditLog::query()->where('action', 'attendance.correction')->sole();
        $this->assertSame('ADD_SESSION', $audit->metadata['kind']);
        $this->assertSame('Telefon buzilgan, rahbar tasdiqladi', $audit->reason);

        $row = app(AttendanceDayQuery::class)->rows(StudentProfile::query()->whereKey($absent->id), [$today], 'Asia/Tashkent')->first();
        $this->assertSame('PRESENT', $row->day_status);

        $this->actingAs($world['admin'])->post("/attendance/students/{$absent->id}/corrections", [
            'date' => $today, 'check_in' => '15:30', 'check_out' => '15:45', 'reason' => 'Ikkinchi',
        ])->assertSessionHas('error');
        $this->assertSame(1, AttendanceSession::query()->where('student_profile_id', $absent->id)->count());
    }

    public function test_admin_closes_an_open_session_and_originals_stay(): void
    {
        $world = $this->scenarioDay();
        $session = AttendanceSession::query()->where('student_profile_id', $world['cast']['incomplete']->id)->sole();
        $original = AttendanceEvent::query()->findOrFail($session->check_in_event_id)->toArray();
        $this->at(18);

        $this->actingAs($world['admin'])->post("/attendance/sessions/{$session->id}/close", ['check_out' => '17:30', 'reason' => 'Ketishni unutgan'])
            ->assertSessionHas('success');

        $session->refresh();
        $this->assertSame(SessionStatus::Completed, $session->status);
        $this->assertSame((int) (8.5 * 3600), $session->duration_seconds);
        $this->assertSame($original, AttendanceEvent::query()->findOrFail($session->check_in_event_id)->toArray());
        $this->assertSame('CLOSE_SESSION', AuditLog::query()->where('action', 'attendance.correction')->sole()->metadata['kind']);

        $this->actingAs($world['admin'])->post("/attendance/sessions/{$session->id}/close", ['check_out' => '17:45', 'reason' => 'Yana'])->assertSessionHas('error');
    }

    public function test_corrections_validate_time_and_reason_and_respect_policy(): void
    {
        $world = $this->scenarioDay();
        $absent = $world['cast']['absent'];
        $today = now('Asia/Tashkent')->toDateString();
        $url = "/attendance/students/{$absent->id}/corrections";

        $this->actingAs($world['admin'])->post($url, ['date' => $today, 'check_in' => '10:00', 'check_out' => '09:00', 'reason' => 'Sabab'])->assertSessionHasErrors('check_out');
        $this->actingAs($world['admin'])->post($url, ['date' => $today, 'check_in' => '23:59', 'reason' => 'Sabab'])->assertSessionHasErrors('check_in');
        $this->actingAs($world['admin'])->post($url, ['date' => $today, 'check_in' => '09:00', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($world['admin'])->post($url, ['date' => now('Asia/Tashkent')->subDays(30)->toDateString(), 'check_in' => '09:00', 'check_out' => '10:00', 'reason' => 'Sabab'])->assertSessionHas('error');

        app(AttendancePolicyService::class)->saveUniversity($world['admin'], ['manual_correction_allowed' => false]);
        $this->actingAs($world['admin'])->post($url, ['date' => $today, 'check_in' => '08:10', 'check_out' => '08:20', 'reason' => 'Sabab'])->assertSessionHas('error');
        $this->assertSame(0, AttendanceSession::query()->where('student_profile_id', $absent->id)->count());
    }

    // ------------------------------------------------------------ reports

    public function test_report_summary_and_scoped_csv_export(): void
    {
        Storage::fake('local');
        $world = $this->scenarioDay();
        $other = $this->world('B', 7000);
        $this->placement($other['internship'], $other['students'][0], $other['org']);
        $other['students'][0]->update(['last_name' => 'Begonayev']);
        $world['cast']['present']->update(['student_code' => '=HYPERLINK("x")']);
        $today = now('Asia/Tashkent')->toDateString();

        $this->actingAs($world['admin'])->get("/reports?from={$today}&to={$today}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('totals.students', 6)
            ->where('totals.present', 2)
            ->where('totals.seconds', 6 * 3600)
            ->where('totals.absent', 2));

        $this->actingAs($world['supervisor'])->post('/reports/export', ['from' => $today, 'to' => $today, 'type' => 'daily'])->assertSessionHas('success');
        $export = ReportExport::query()->sole();
        $this->assertSame('DONE', $export->status);
        $this->assertSame(6, $export->rows);
        $this->assertSame($today, $export->filters['from']);

        $csv = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Joylashuv rad etildi', $csv);
        $this->assertStringContainsString('1 soat 0 daqiqa', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString($other['students'][0]->last_name, $csv);

        $this->actingAs($world['supervisor'])->get("/reports/exports/{$export->id}/download")->assertOk()->assertDownload();
        $this->actingAs($world['admin'])->get("/reports/exports/{$export->id}/download")->assertNotFound();
        $this->actingAs($other['admin'])->get("/reports/exports/{$export->id}/download")->assertNotFound();
    }

    public function test_summary_export_and_formula_guard(): void
    {
        Storage::fake('local');
        $world = $this->scenarioDay();
        $today = now('Asia/Tashkent')->toDateString();

        $this->actingAs($world['admin'])->post('/reports/export', ['from' => $today, 'to' => $today, 'type' => 'summary'])->assertSessionHas('success');
        $export = ReportExport::query()->sole();
        $this->assertSame(6, $export->rows);

        $service = app(AttendanceReportService::class);
        foreach (['=1+1', '+cmd', '-2', '@SUM(A1)'] as $danger) {
            $this->assertSame("'".$danger, $service->cell($danger));
        }
        $this->assertSame('Aliyev', $service->cell('Aliyev'));
        $this->assertSame(5, $service->cell(5));
    }

    public function test_exports_are_rate_limited(): void
    {
        Storage::fake('local');
        $world = $this->world();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($world['admin'])->post('/reports/export', ['type' => 'summary'])->assertRedirect();
        }
        $this->actingAs($world['admin'])->post('/reports/export', ['type' => 'summary'])->assertStatus(429);
    }

    // ------------------------------------------------------------ dashboard

    public function test_dashboard_shows_today_attendance_tiles(): void
    {
        $world = $this->scenarioDay();

        $this->actingAs($world['admin'])->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('attendance.PRESENT', 2)
            ->where('attendance.EXPECTED', 6)
            ->where('unmarked.total', 3)
            ->has('unmarked.rows', 3));
    }
}
