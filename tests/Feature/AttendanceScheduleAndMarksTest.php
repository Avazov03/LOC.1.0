<?php

namespace Tests\Feature;

use App\Enums\DayMarkKind;
use App\Models\AttendanceDayMark;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\InternshipParticipant;
use App\Models\StudentProfile;
use App\Models\SupervisorNotification;
use App\Services\Attendance\AttendanceDayQuery;
use App\Services\Attendance\AttendanceMarkService;
use App\Services\Attendance\AttendanceOutcome;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\DailyReminderService;
use App\Services\Attendance\LocationInput;
use App\Support\WorkDays;
use App\Telegram\BotText;
use App\Telegram\Keyboard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsInternships;
use Tests\Concerns\TalksToBot;
use Tests\TestCase;

class AttendanceScheduleAndMarksTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;
    use TalksToBot;

    private const TZ = 'Asia/Tashkent';

    private const SUPERVISOR_TG = 990001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWebhook();
    }

    /**
     * 2026-10-12 is a Monday.
     */
    private function on(string $date, int $hour = 9, int $minute = 0): void
    {
        $this->travelTo(CarbonImmutable::parse($date, self::TZ)->setTime($hour, $minute)->utc());
    }

    private function inside(): LocationInput
    {
        return new LocationInput(41.3112, 69.2797, 10.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function placed(): array
    {
        $world = $this->world();
        foreach ($world['students'] as $student) {
            $assignment = $this->placement($world['internship'], $student, $world['org']);
            $assignment->update(['start_at' => now()->subDays(20)]);
        }

        return $world;
    }

    private function day(StudentProfile $student, string $date): object
    {
        return app(AttendanceDayQuery::class)->rows(StudentProfile::query()->whereKey($student->id), [$date], self::TZ)->first();
    }

    private function linkSupervisor(array $world): void
    {
        $this->actingAs($world['supervisor'])->post('/profile/telegram')->assertSessionHas('telegram_link');
        $start = (string) session('telegram_link');
        $this->assertStringStartsWith('s_', $start);
        $this->assertStringContainsString('rahbar profiliga ulandi', $this->say(self::SUPERVISOR_TG, '/start '.$start));
        $this->bot()->reset();
    }

    // ------------------------------------------------------------ work days

    public function test_work_day_masks_and_labels(): void
    {
        $this->assertSame([1, 3, 5], WorkDays::toDays(WorkDays::ODD));
        $this->assertSame([2, 4, 6], WorkDays::toDays(WorkDays::EVEN));
        $this->assertSame('Toq kunlar (Du, Chor, Ju)', WorkDays::label(WorkDays::ODD));
        $this->assertSame('Juft kunlar (Se, Pay, Sha)', WorkDays::label(WorkDays::EVEN));
        $this->assertSame('Du, Ju', WorkDays::label(WorkDays::fromDays([1, 5])));
        $this->assertTrue(WorkDays::includes(WorkDays::ODD, '2026-10-12'));
        $this->assertFalse(WorkDays::includes(WorkDays::ODD, '2026-10-13'));
    }

    public function test_check_in_is_refused_on_a_non_work_day_and_the_day_is_not_absent(): void
    {
        $this->on('2026-10-13');
        $world = $this->placed();
        $world['internship']->update(['work_days' => WorkDays::ODD]);
        $student = $world['students'][0];
        $service = app(AttendanceService::class);

        $this->assertSame(AttendanceOutcome::NOT_WORK_DAY, $service->prepareCheckIn($student)->code);
        $outcome = $service->checkIn($student, $this->inside());
        $this->assertSame(AttendanceOutcome::NOT_WORK_DAY, $outcome->code);
        $this->assertStringContainsString('amaliyot kuningiz emas', BotText::outcome($outcome));
        $this->assertSame(0, AttendanceEvent::query()->count());

        $row = $this->day($student, '2026-10-13');
        $this->assertSame(0, (int) $row->expected);
        $this->assertNull($row->day_status);
        $this->assertSame('ABSENT', $this->day($student, '2026-10-12')->day_status);
    }

    public function test_a_student_override_replaces_the_group_days(): void
    {
        $this->on('2026-10-13');
        $world = $this->placed();
        $world['internship']->update(['work_days' => WorkDays::ODD]);
        [$even, $odd] = $world['students'];

        $this->actingAs($world['supervisor'])->put("/internships/{$world['internship']->id}/students/{$even->id}/work-days", ['work_days' => [2, 4, 6]])
            ->assertSessionHas('success');
        $this->assertSame(WorkDays::EVEN, InternshipParticipant::query()->where('student_profile_id', $even->id)->value('work_days'));
        $this->assertSame(1, AuditLog::query()->where('action', 'internship.student_work_days')->count());

        $this->assertSame(AttendanceOutcome::CHECKED_IN, app(AttendanceService::class)->checkIn($even, $this->inside())->code);
        $this->assertSame(AttendanceOutcome::NOT_WORK_DAY, app(AttendanceService::class)->checkIn($odd, $this->inside())->code);

        $this->actingAs($world['supervisor'])->put("/internships/{$world['internship']->id}/students/{$even->id}/work-days", ['work_days' => []])
            ->assertSessionHas('success');
        $this->assertNull(InternshipParticipant::query()->where('student_profile_id', $even->id)->value('work_days'));
    }

    public function test_internship_days_are_admin_only_and_student_days_are_scoped(): void
    {
        $this->on('2026-10-12');
        $world = $this->placed();
        $other = $this->world('B', 7000);
        $url = "/internships/{$world['internship']->id}/work-days";

        $this->actingAs($world['admin'])->put($url, ['work_days' => []])->assertSessionHasErrors('work_days');
        $this->actingAs($world['admin'])->put($url, ['work_days' => [8]])->assertSessionHasErrors('work_days.0');
        $this->actingAs($world['admin'])->put($url, ['work_days' => [1, 3, 5]])->assertSessionHas('success');
        $this->assertSame(WorkDays::ODD, $world['internship']->fresh()->work_days);
        $this->actingAs($world['supervisor'])->put($url, ['work_days' => [1]])->assertForbidden();

        $student = $world['students'][0];
        $this->actingAs($other['supervisor'])->put("/internships/{$world['internship']->id}/students/{$student->id}/work-days", ['work_days' => [1]])->assertNotFound();
    }

    public function test_a_long_range_counts_only_work_days(): void
    {
        $this->on('2026-10-12');
        $world = $this->placed();
        $world['internship']->update(['work_days' => WorkDays::ODD]);
        $student = $world['students'][0];
        $dates = AttendanceDayQuery::dateRange('2026-09-28', '2026-10-11');

        $rows = app(AttendanceDayQuery::class)->rows(StudentProfile::query()->whereKey($student->id), $dates, self::TZ)->get();
        $this->assertCount(14, $rows);
        // Assignment from 2026-09-22: in two weeks, Mon/Wed/Fri = 6 expected days, all absent.
        $this->assertSame(6, $rows->where('day_status', 'ABSENT')->count());
        foreach ($rows->where('day_status', 'ABSENT') as $row) {
            $this->assertContains(CarbonImmutable::parse((string) $row->local_date)->dayOfWeekIso, [1, 3, 5]);
        }
    }

    // ------------------------------------------------------------ marks

    public function test_supervisor_marks_a_day_present_and_revokes_it_with_audit(): void
    {
        $this->on('2026-10-12', 16);
        $world = $this->placed();
        $student = $world['students'][0];

        $this->actingAs($world['supervisor'])->post("/attendance/students/{$student->id}/marks", ['date' => '2026-10-12', 'kind' => 'PRESENT', 'note' => 'Sudda edi'])
            ->assertSessionHas('success');
        $row = $this->day($student, '2026-10-12');
        $this->assertSame('PRESENT', $row->day_status);
        $this->assertSame('PRESENT', $row->mark_kind);
        $this->assertSame(0, AttendanceEvent::query()->count());
        $this->assertSame('Sudda edi', AuditLog::query()->where('action', 'attendance.mark')->sole()->reason);

        $this->actingAs($world['supervisor'])->get('/attendance?date=2026-10-12')->assertInertia(fn ($page) => $page
            ->where('canMark', true)
            ->where('totals.PRESENT', 1));

        $mark = AttendanceDayMark::query()->sole();
        $this->actingAs($world['supervisor'])->post("/attendance/marks/{$mark->id}/revoke", ['reason' => 'Xato'])->assertSessionHas('success');
        $this->assertNotNull($mark->fresh()->revoked_at);
        $this->assertSame('ABSENT', $this->day($student, '2026-10-12')->day_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance.mark_revoke')->count());
    }

    public function test_excused_needs_a_reason_and_is_not_absent(): void
    {
        $this->on('2026-10-12', 16);
        $world = $this->placed();
        $student = $world['students'][0];
        $url = "/attendance/students/{$student->id}/marks";

        $this->actingAs($world['supervisor'])->post($url, ['date' => '2026-10-12', 'kind' => 'EXCUSED'])->assertSessionHasErrors('note');
        $this->actingAs($world['supervisor'])->post($url, ['date' => '2026-10-12', 'kind' => 'EXCUSED', 'note' => 'Kasal, ma’lumotnoma bor'])->assertSessionHas('success');
        $this->assertSame('EXCUSED', $this->day($student, '2026-10-12')->day_status);

        // A new mark replaces the active one; the old row is kept revoked.
        $this->actingAs($world['supervisor'])->post($url, ['date' => '2026-10-12', 'kind' => 'PRESENT'])->assertSessionHas('success');
        $this->assertSame('PRESENT', $this->day($student, '2026-10-12')->day_status);
        $this->assertSame(2, AttendanceDayMark::query()->count());
        $this->assertSame(1, AttendanceDayMark::query()->whereNull('revoked_at')->count());

        $totals = app(AttendanceDayQuery::class)->totals(StudentProfile::query()->whereKey($student->id), '2026-10-12', self::TZ);
        $this->assertSame(0, $totals['ABSENT']);
    }

    public function test_mark_date_limits_and_scope(): void
    {
        $this->on('2026-10-12', 16);
        $world = $this->placed();
        $other = $this->world('B', 7000);
        $student = $world['students'][0];
        $url = "/attendance/students/{$student->id}/marks";

        $this->actingAs($world['supervisor'])->post($url, ['date' => '2026-10-13', 'kind' => 'PRESENT'])->assertSessionHas('error');
        $old = CarbonImmutable::parse('2026-10-12')->subDays(AttendanceMarkService::SUPERVISOR_DAYS + 1)->toDateString();
        $this->actingAs($world['supervisor'])->post($url, ['date' => $old, 'kind' => 'PRESENT'])->assertSessionHas('error');
        $this->actingAs($world['admin'])->post($url, ['date' => $old, 'kind' => 'PRESENT'])->assertSessionHas('success');
        // Before the assignment started: nothing to mark.
        $this->actingAs($world['admin'])->post($url, ['date' => '2026-09-01', 'kind' => 'PRESENT'])->assertSessionHas('error');
        $this->actingAs($other['supervisor'])->post($url, ['date' => '2026-10-12', 'kind' => 'PRESENT'])->assertNotFound();
        $this->actingAs($other['admin'])->post($url, ['date' => '2026-10-12', 'kind' => 'PRESENT'])->assertNotFound();

        $this->assertSame(1, AttendanceDayMark::query()->count());
        $this->actingAs($world['supervisor'])->post('/attendance/marks/'.AttendanceDayMark::query()->value('id').'/revoke')->assertSessionHas('error');
    }

    // ------------------------------------------------------------ supervisor telegram

    public function test_supervisor_links_telegram_once_and_gets_check_in_and_out_messages(): void
    {
        $this->on('2026-10-12', 9);
        $world = $this->placed();
        $this->linkSupervisor($world);
        $this->assertSame(self::SUPERVISOR_TG, $world['profile']->fresh()->telegram_user_id);

        $student = $world['students'][0];
        $service = app(AttendanceService::class);
        $service->checkIn($student, $this->inside());
        $this->assertSame(self::SUPERVISOR_TG, $this->bot()->sent[0]['chat_id']);
        $this->assertStringContainsString($student->fullName().' amaliyotga keldi', $this->bot()->lastText());

        $this->on('2026-10-12', 12, 30);
        $service->checkOut($student, $this->inside());
        $this->assertStringContainsString('amaliyotdan ketdi', $this->bot()->lastText());
        $this->assertStringContainsString('3 soat 30 daqiqa', $this->bot()->lastText());
        $this->assertSame(2, SupervisorNotification::query()->where('status', 'SENT')->count());

        // Typing to the bot as a supervisor only shows the supervisor help.
        $this->assertStringContainsString('amaliyot rahbari', $this->say(self::SUPERVISOR_TG, 'salom'));
    }

    public function test_a_used_or_expired_link_is_refused_and_notifications_can_be_turned_off(): void
    {
        $this->on('2026-10-12', 9);
        $world = $this->placed();
        $this->actingAs($world['supervisor'])->post('/profile/telegram');
        $start = (string) session('telegram_link');
        $this->say(self::SUPERVISOR_TG, '/start '.$start);

        $this->assertStringContainsString('muddati tugagan', $this->say(990002, '/start '.$start));
        $this->assertSame(self::SUPERVISOR_TG, $world['profile']->fresh()->telegram_user_id);

        $this->actingAs($world['supervisor'])->put('/profile/notifications', ['notify_check_events' => false])->assertSessionHas('success');
        $this->bot()->reset();
        $this->assertSame(AttendanceOutcome::CHECKED_IN, app(AttendanceService::class)->checkIn($world['students'][0], $this->inside())->code);
        $this->assertSame([], $this->bot()->sent);

        $this->actingAs($world['supervisor'])->delete('/profile/telegram')->assertSessionHas('success');
        $this->assertNull($world['profile']->fresh()->telegram_user_id);
    }

    public function test_daily_digest_lists_unmarked_students_and_the_button_marks_present(): void
    {
        $this->on('2026-10-12', 9);
        $world = $this->placed();
        $this->linkSupervisor($world);
        [$checked, $missing] = $world['students'];
        app(AttendanceService::class)->checkIn($checked, $this->inside());
        $this->bot()->reset();

        $this->on('2026-10-12', 17, 55);
        $this->assertSame(['reminders' => 0, 'digests' => 0], app(DailyReminderService::class)->run());

        $this->on('2026-10-12', 18, 5);
        $result = app(DailyReminderService::class)->run();
        $this->assertSame(['reminders' => 1, 'digests' => 1], $result);

        $texts = $this->bot()->texts();
        $this->assertCount(2, $texts);
        $digest = collect($this->bot()->sent)->firstWhere('chat_id', self::SUPERVISOR_TG);
        $this->assertStringContainsString($missing->fullName(), $digest['text']);
        $this->assertStringNotContainsString($checked->fullName(), $digest['text']);
        $reminder = collect($this->bot()->sent)->firstWhere('chat_id', (int) $checked->telegram_user_id);
        $this->assertSame(BotText::CHECKOUT_REMINDER, $reminder['text']);

        $button = $digest['reply_markup']['inline_keyboard'][0][0];
        $digestId = SupervisorNotification::query()->where('key', 'like', 'digest:%')->value('id');
        $this->assertSame("dg:{$digestId}:0", $button['callback_data']);

        // A stranger pressing the button changes nothing.
        $this->assertStringContainsString('eskirgan', $this->press(990099, $button['callback_data']));
        $this->assertSame(0, AttendanceDayMark::query()->count());

        $this->assertStringContainsString('«Keldi» deb belgilandi', $this->press(self::SUPERVISOR_TG, $button['callback_data']));
        $mark = AttendanceDayMark::query()->sole();
        $this->assertSame($missing->id, $mark->student_profile_id);
        $this->assertSame(DayMarkKind::Present, $mark->kind);
        $this->assertSame('TELEGRAM', $mark->source);
        $this->assertSame('PRESENT', $this->day($missing, '2026-10-12')->day_status);

        $this->bot()->reset();
        $this->assertSame(['reminders' => 0, 'digests' => 0], app(DailyReminderService::class)->run());
        $this->assertSame([], $this->bot()->sent);
    }

    public function test_no_digest_on_a_non_work_day(): void
    {
        $this->on('2026-10-13', 18, 30);
        $world = $this->placed();
        $world['internship']->update(['work_days' => WorkDays::ODD]);
        $this->linkSupervisor($world);

        app(DailyReminderService::class)->run();

        $this->assertSame([], $this->bot()->sent);
        $this->assertSame('SKIPPED', SupervisorNotification::query()->sole()->status);
    }

    public function test_admin_can_issue_and_remove_a_supervisor_link(): void
    {
        $this->on('2026-10-12');
        $world = $this->placed();

        $this->actingAs($world['admin'])->post("/supervisors/{$world['profile']->id}/telegram")->assertSessionHas('telegram_link');
        $this->say(self::SUPERVISOR_TG, '/start '.session('telegram_link'));
        $this->actingAs($world['admin'])->get("/supervisors/{$world['profile']->id}")->assertInertia(fn ($page) => $page->where('supervisor.telegram_linked', true));

        $this->actingAs($world['admin'])->delete("/supervisors/{$world['profile']->id}/telegram")->assertSessionHas('success');
        $this->assertNull($world['profile']->fresh()->telegram_user_id);
        $this->actingAs($world['supervisor'])->post("/supervisors/{$world['profile']->id}/telegram")->assertForbidden();
    }

    // ------------------------------------------------------------ profile and settings

    public function test_staff_change_their_own_password(): void
    {
        $world = $this->world();
        $user = $world['supervisor'];
        $user->forceFill(['password' => 'old-password-1'])->save();

        $this->actingAs($user)->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->component('Profile')->where('supervisor.telegram_linked', false));
        $this->actingAs($user)->put('/profile/password', ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/profile/password', ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertSame(1, AuditLog::query()->where('action', 'user.password_change')->count());
    }

    public function test_reminder_time_is_a_university_setting(): void
    {
        $world = $this->world();

        $this->actingAs($world['admin'])->put('/settings', ['name' => 'Universitet', 'timezone' => self::TZ, 'reminder_time' => '25:00'])->assertSessionHasErrors('reminder_time');
        $this->actingAs($world['admin'])->put('/settings', ['name' => 'Universitet', 'timezone' => self::TZ, 'reminder_time' => '17:30'])->assertSessionHas('success');
        $this->assertSame('17:30', $world['university']->fresh()->reminder_time);
    }

    public function test_bot_shows_work_days_and_marked_history_to_the_student(): void
    {
        $this->on('2026-10-12', 16);
        $world = $this->placed();
        $world['internship']->update(['work_days' => WorkDays::ODD]);
        $student = $world['students'][0];
        app(AttendanceMarkService::class)->mark($world['supervisor'], $student->id, '2026-10-12', DayMarkKind::Present, null);

        $this->assertStringContainsString('Toq kunlar (Du, Chor, Ju)', $this->say((int) $student->telegram_user_id, Keyboard::INTERNSHIP));
        $this->assertStringContainsString('Keldi (rahbar belgiladi)', $this->say((int) $student->telegram_user_id, Keyboard::ATTENDANCE));
    }
}
