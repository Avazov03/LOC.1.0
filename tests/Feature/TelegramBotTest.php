<?php

namespace Tests\Feature;

use App\Enums\AttendanceEventType;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\SessionStatus;
use App\Enums\StudentStatus;
use App\Enums\VerificationStatus;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\InternshipChangeRequest;
use App\Models\InternshipInvite;
use App\Models\StudentProfile;
use App\Models\TelegramConversation;
use App\Services\Onboarding\OnboardingException;
use App\Telegram\BotHandler;
use App\Telegram\BotText;
use App\Telegram\Keyboard;
use App\Telegram\UpdateProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsInternships;
use Tests\Concerns\TalksToBot;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;
    use TalksToBot;

    private const INSIDE = [41.3112, 69.2797];

    private const OUTSIDE = [41.3135, 69.2797];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWebhook();
    }

    private function invite(array $world, ?string $expiresAt = null): string
    {
        $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/invites', ['expires_at' => $expiresAt])
            ->assertSessionHas('invite_link');

        return (string) session('invite_link');
    }

    /**
     * @return array<string, mixed>
     */
    private function placedWorld(): array
    {
        $world = $this->world();
        $this->placement($world['internship'], $world['students'][0], $world['org']);

        return $world;
    }

    // ------------------------------------------------------------ webhook

    public function test_webhook_is_closed_without_a_configured_secret(): void
    {
        config(['services.telegram.webhook_secret' => '']);

        $this->deliver($this->messageUpdate(1, ['text' => '/start']))->assertNotFound();
        $this->assertSame(0, DB::table('telegram_processed_updates')->count());
    }

    public function test_webhook_rejects_a_wrong_or_missing_secret_without_reading_the_body(): void
    {
        $this->deliver($this->messageUpdate(1, ['text' => '/start']), 'wrong')->assertForbidden();
        $this->postJson('/telegram/webhook', $this->messageUpdate(1, ['text' => '/start']))->assertForbidden();

        $this->assertSame(0, DB::table('telegram_processed_updates')->count());
        $this->assertSame([], $this->bot()->sent);
    }

    public function test_a_replayed_update_id_is_processed_once(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;
        $update = $this->messageUpdate($userId, ['text' => Keyboard::HELP], 9001);

        $this->deliver($update)->assertOk()->assertJson(['ok' => true]);
        $this->deliver($update)->assertOk();

        $this->assertCount(1, $this->bot()->sent);
        $this->assertSame(1, DB::table('telegram_processed_updates')->where('update_id', 9001)->count());
    }

    public function test_a_replayed_location_never_creates_a_second_event(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;
        $this->say($userId, Keyboard::START);
        $update = $this->messageUpdate($userId, ['location' => ['latitude' => self::INSIDE[0], 'longitude' => self::INSIDE[1], 'horizontal_accuracy' => 5]], 9100);

        $this->deliver($update)->assertOk();
        $this->deliver($update)->assertOk();

        $this->assertSame(1, AttendanceEvent::query()->where('event_type', AttendanceEventType::CheckIn->value)->count());
        $this->assertSame(9100, (int) AttendanceEvent::query()->sole()->telegram_update_id);
    }

    public function test_group_chats_and_edited_messages_are_ignored(): void
    {
        $this->deliver([
            'update_id' => 77,
            'message' => ['message_id' => 1, 'date' => time(), 'from' => ['id' => 5, 'is_bot' => false], 'chat' => ['id' => -100, 'type' => 'group'], 'text' => '/start'],
        ])->assertOk();
        $this->deliver(['update_id' => 78, 'edited_message' => ['message_id' => 1]])->assertOk();

        $this->assertSame([], $this->bot()->sent);
        $this->assertSame(2, DB::table('telegram_processed_updates')->where('handler', 'ignored')->count());
    }

    // ------------------------------------------------------------ onboarding

    public function test_full_onboarding_through_the_bot(): void
    {
        $world = $this->world();
        $token = $this->invite($world);
        $userId = 880001;

        $greeting = $this->say($userId, '/start '.$token);
        $this->assertStringContainsString($world['group']->name, $greeting);
        $this->assertStringContainsString('Ismingizni yozing', $greeting);

        $this->assertStringContainsString('faqat harflardan', $this->say($userId, '12345'));
        $this->assertStringContainsString('Familiyangizni', $this->say($userId, 'jasur'));
        $this->assertStringContainsString('Telefon raqamingizni', $this->say($userId, 'karimov'));
        $this->assertStringContainsString('Faqat o‘zingizning', $this->sendContact($userId, '+998901112233', 999999));
        $this->assertStringContainsString('Talaba ID', $this->sendContact($userId, '998901112233', $userId));
        $summary = $this->say($userId, 'S-77');
        $this->assertStringContainsString('Ism: Jasur', $summary);
        $this->assertStringContainsString('Telefon: +998901112233', $summary);

        $this->assertNull(StudentProfile::query()->where('telegram_user_id', $userId)->first(), 'Nothing is written before confirmation.');
        $this->assertStringContainsString('Ro‘yxatdan o‘tdingiz', $this->say($userId, Keyboard::CONFIRM));

        $profile = StudentProfile::query()->where('telegram_user_id', $userId)->sole();
        $this->assertSame('Jasur', $profile->first_name);
        $this->assertSame('Karimov', $profile->last_name);
        $this->assertSame('S-77', $profile->student_code);
        $this->assertSame($world['group']->id, $profile->current_group_id);
        $this->assertNull(TelegramConversation::query()->where('telegram_user_id', $userId)->first());

        foreach ($this->bot()->texts() as $text) {
            $this->assertStringNotContainsString($token, $text);
            $this->assertStringNotContainsString(InternshipInvite::query()->sole()->token_hash, $text);
        }
    }

    public function test_student_code_clash_asks_again_and_skip_works(): void
    {
        $world = $this->world();
        $world['students'][1]->update(['student_code' => 'TAKEN-1']);
        $token = $this->invite($world);
        $userId = 880002;

        $this->say($userId, '/start '.$token);
        $this->say($userId, 'Ali');
        $this->say($userId, 'Valiyev');
        $this->sendContact($userId, '+998901234500', $userId);
        $this->say($userId, 'TAKEN-1');
        $this->assertStringContainsString('allaqachon band', $this->say($userId, Keyboard::CONFIRM));
        $this->assertNull(StudentProfile::query()->where('telegram_user_id', $userId)->first());

        $this->say($userId, Keyboard::SKIP);
        $this->say($userId, Keyboard::CONFIRM);
        $this->assertNull(StudentProfile::query()->where('telegram_user_id', $userId)->sole()->student_code);
    }

    public function test_invalid_closed_and_expired_invites_are_refused_in_uzbek(): void
    {
        $world = $this->world();

        $this->assertSame((new OnboardingException(OnboardingException::INVALID_INVITE))->getMessage(), $this->say(880010, '/start '.str_repeat('x', 43)));
        $this->assertSame((new OnboardingException(OnboardingException::INVALID_INVITE))->getMessage(), $this->say(880010, '/start bad token!'));
        $this->assertStringStartsWith(BotText::NEED_INVITE, $this->say(880010, '/start'));

        $closed = $this->invite($world);
        InternshipInvite::query()->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $this->assertSame((new OnboardingException(OnboardingException::INVITE_CLOSED))->getMessage(), $this->say(880011, '/start '.$closed));

        $expiring = $this->invite($world, now('Asia/Tashkent')->addHour()->format('Y-m-d\TH:i'));
        $this->travel(2)->hours();
        $this->assertSame((new OnboardingException(OnboardingException::INVITE_EXPIRED))->getMessage(), $this->say(880012, '/start '.$expiring));

        $this->assertSame(0, StudentProfile::query()->whereIn('telegram_user_id', [880010, 880011, 880012])->count());
    }

    public function test_invite_that_closes_mid_dialog_is_refused_at_confirmation(): void
    {
        $world = $this->world();
        $token = $this->invite($world);
        $userId = 880020;
        $this->say($userId, '/start '.$token);
        $this->say($userId, 'Ali');
        $this->say($userId, 'Valiyev');
        $this->sendContact($userId, '+998901234501', $userId);
        $this->say($userId, Keyboard::SKIP);

        InternshipInvite::query()->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->assertSame((new OnboardingException(OnboardingException::INVITE_CLOSED))->getMessage(), $this->say($userId, Keyboard::CONFIRM));
        $this->assertNull(StudentProfile::query()->where('telegram_user_id', $userId)->first());
    }

    public function test_repeated_start_for_a_registered_student_never_creates_a_second_profile(): void
    {
        $world = $this->world();
        $token = $this->invite($world);
        $student = $world['students'][0];
        $before = StudentProfile::query()->count();

        $reply = $this->say((int) $student->telegram_user_id, '/start '.$token);
        $this->assertStringContainsString(BotText::ALREADY_REGISTERED, $reply);
        $this->say((int) $student->telegram_user_id, '/start');

        $this->assertSame($before, StudentProfile::query()->count());
        $this->assertSame(Keyboard::menu(), $this->lastMarkup());
    }

    public function test_join_attempts_with_a_token_are_rate_limited(): void
    {
        for ($i = 0; $i < BotHandler::JOIN_PER_MINUTE; $i++) {
            $this->say(880030, '/start '.str_repeat('a', 43));
        }

        $this->assertSame(BotText::RATE_LIMITED, $this->say(880030, '/start '.str_repeat('a', 43)));
    }

    public function test_cancel_during_onboarding_clears_the_dialog(): void
    {
        $world = $this->world();
        $this->say(880040, '/start '.$this->invite($world));
        $this->say(880040, 'Ali');

        $this->assertSame(BotText::CANCELLED, $this->say(880040, Keyboard::CANCEL));
        $this->assertNull(TelegramConversation::query()->where('telegram_user_id', 880040)->first());
        $this->assertSame(BotText::NEED_INVITE, $this->say(880040, 'salom'));
    }

    // ------------------------------------------------------------ access

    public function test_unknown_and_blocked_users_get_no_student_data(): void
    {
        $world = $this->placedWorld();
        $student = $world['students'][0];

        $this->assertSame(BotText::NEED_INVITE, $this->say(123456789, Keyboard::INTERNSHIP));
        $this->assertSame(BotText::NEED_INVITE, $this->sendLocation(123456789, ...self::INSIDE));

        $student->update(['status' => StudentStatus::Blocked]);
        foreach ([Keyboard::INTERNSHIP, Keyboard::START, Keyboard::ATTENDANCE, Keyboard::PROFILE, Keyboard::CHANGE] as $button) {
            $this->assertSame(BotText::ACCESS_DENIED, $this->say((int) $student->telegram_user_id, $button));
        }
        $this->assertSame(BotText::ACCESS_DENIED, $this->say((int) $student->telegram_user_id, '/start'));
        $this->assertSame(0, AttendanceEvent::query()->count());
    }

    public function test_menu_screens_show_the_student_their_own_data_without_internal_ids(): void
    {
        $world = $this->placedWorld();
        $student = $world['students'][0];
        $userId = (int) $student->telegram_user_id;

        $internship = $this->say($userId, Keyboard::INTERNSHIP);
        $this->assertStringContainsString($world['org']->name, $internship);
        $this->assertStringContainsString('Toshkent', $internship);
        $this->assertStringNotContainsString('41.31', $internship);

        $profile = $this->say($userId, Keyboard::PROFILE);
        $this->assertStringContainsString($student->phone, $profile);

        $this->assertSame(BotText::HELP, $this->say($userId, Keyboard::HELP));
        $history = $this->say($userId, Keyboard::ATTENDANCE);
        $this->assertStringContainsString(now('Asia/Tashkent')->format('d.m').' — ❌ Kelmadi', $history);
        $this->assertStringNotContainsString(now('Asia/Tashkent')->subDays(2)->format('d.m'), $history, 'Days before the assignment are not listed.');
        $this->assertStringContainsString('davomat yozuvi yo‘q', $this->say((int) $world['students'][1]->telegram_user_id, Keyboard::ATTENDANCE));
        $this->assertSame(BotText::UNKNOWN, $this->say($userId, 'nimadir'));
        $this->assertSame(Keyboard::menu(), $this->lastMarkup());
    }

    public function test_per_user_rate_limit_answers_once_then_stays_silent(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;

        for ($i = 0; $i < UpdateProcessor::USER_ACTIONS_PER_MINUTE; $i++) {
            $this->say($userId, Keyboard::HELP);
        }
        $this->assertSame(BotText::RATE_LIMITED, $this->say($userId, Keyboard::HELP));
        $count = count($this->bot()->sent);
        $this->say($userId, Keyboard::HELP);
        $this->assertCount($count, $this->bot()->sent);
    }

    // ------------------------------------------------------------ attendance through the bot

    public function test_check_in_and_check_out_through_the_bot(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;

        $this->assertSame(BotText::ASK_LOCATION, $this->say($userId, Keyboard::START));
        $this->assertTrue($this->lastMarkup()['keyboard'][0][0]['request_location']);

        $in = $this->sendLocation($userId, ...self::INSIDE);
        $this->assertStringContainsString('boshlanishi qayd etildi', $in);
        $this->assertStringContainsString($world['org']->name, $in);
        $this->assertStringNotContainsString('41.31', $in);
        $this->assertSame(SessionStatus::Open, AttendanceSession::query()->sole()->status);

        $this->assertStringContainsString('allaqachon faol davomat', $this->say($userId, Keyboard::START));

        $this->assertSame(BotText::ASK_LOCATION, $this->say($userId, Keyboard::FINISH));
        $this->assertStringContainsString('tugashi qayd etildi', $this->sendLocation($userId, ...self::INSIDE));
        $this->assertSame(SessionStatus::Completed, AttendanceSession::query()->sole()->status);

        $this->assertStringContainsString('ikkinchi marta', $this->say($userId, Keyboard::START));
        $this->assertSame(1, AttendanceSession::query()->count());
        $this->assertStringContainsString('Keldi', $this->say($userId, Keyboard::ATTENDANCE));
    }

    /**
     * Production 10.10.2026: phones in one office send the same Wi-Fi position, without horizontal_accuracy,
     * every day and for every student. All of these are real presences and must be accepted.
     */
    public function test_wifi_positions_without_accuracy_repeating_across_students_and_days_are_accepted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Tashkent')->utc());
        $world = $this->world();
        [$a, $b] = $world['students'];
        $assignmentA = $this->placement($world['internship'], $a, $world['org']);
        $assignmentB = $this->placement($world['internship'], $b, $world['org']);
        $assignmentA->update(['start_at' => now()->subDays(3)]);
        $assignmentB->update(['start_at' => now()->subDays(3)]);
        $point = [41.311264, 69.279779];
        $idA = (int) $a->telegram_user_id;
        $idB = (int) $b->telegram_user_id;
        // Redis expires rate-limit windows in real time, not in travelled time.
        $later = function (int $hours) use ($a, $idA): void {
            $this->travel($hours)->hours();
            RateLimiter::clear('tg-attendance:'.$a->id);
            RateLimiter::clear('tg-user:'.$idA);
        };

        // Day 1: B checks in at the shared point; A checks in and out at its own identical point.
        $this->say($idB, Keyboard::START);
        $this->assertStringContainsString('boshlanishi qayd etildi', $this->sendLocation($idB, ...$point, accuracy: null, exact: true));
        $this->say($idA, Keyboard::START);
        $this->assertStringContainsString('boshlanishi qayd etildi', $this->sendLocation($idA, 41.311250, 69.279800, accuracy: null, exact: true));
        $later(7);
        $this->say($idA, Keyboard::FINISH);
        $this->assertStringContainsString('tugashi qayd etildi', $this->sendLocation($idA, 41.311250, 69.279800, accuracy: null, exact: true));

        // Day 2: A checks in and out with B's point of yesterday.
        $later(17);
        $this->say($idA, Keyboard::START);
        $this->assertStringContainsString('boshlanishi qayd etildi', $this->sendLocation($idA, ...$point, accuracy: null, exact: true));
        $later(3);
        $this->assertSame(BotText::ASK_LOCATION, $this->say($idA, Keyboard::FINISH));
        $this->assertStringContainsString('tugashi qayd etildi', $this->sendLocation($idA, ...$point, accuracy: null, exact: true));

        $this->assertSame(0, AttendanceEvent::query()->where('event_type', 'like', 'FAILED%')->count());
        $flagged = AttendanceEvent::query()->where('student_profile_id', $a->id)->where('event_type', 'CHECK_IN')->latest('id')->first();
        $this->assertTrue($flagged->metadata['accuracy_missing']);
        $this->assertArrayHasKey('reused_event_id', $flagged->metadata);

        // Far away is still refused.
        $later(21);
        $this->say($idA, Keyboard::START);
        $this->assertStringContainsString('joyidan tashqaridasiz', mb_strtolower($this->sendLocation($idA, 41.3300, 69.2797, accuracy: null, exact: true)));
    }

    public function test_outside_radius_is_stored_and_the_student_may_retry(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;
        $this->say($userId, Keyboard::START);

        $reply = $this->sendLocation($userId, ...self::OUTSIDE);
        $this->assertStringContainsString('tashqaridasiz', $reply);
        $this->assertStringNotContainsString('41.31', $reply);
        $this->assertStringNotContainsString('radius', mb_strtolower($reply));

        $failed = AttendanceEvent::query()->sole();
        $this->assertSame(AttendanceEventType::FailedCheckIn, $failed->event_type);
        $this->assertSame(VerificationStatus::OutsideRadius, $failed->verification_status);
        $this->assertSame(0, AttendanceSession::query()->count());

        $this->assertStringContainsString('boshlanishi qayd etildi', $this->sendLocation($userId, ...self::INSIDE));
    }

    public function test_forwarded_location_and_venue_are_refused_and_stored_as_invalid(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;
        $this->say($userId, Keyboard::START);

        $this->assertStringContainsString('Uzatilgan', $this->sendLocation($userId, ...[...self::INSIDE, 5.0, ['forward_date' => time()]]));
        $this->assertStringContainsString('Uzatilgan', $this->sendLocation($userId, ...[...self::INSIDE, 5.0, ['venue' => ['title' => 'X', 'address' => 'Y']]]));

        $events = AttendanceEvent::query()->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame(VerificationStatus::InvalidLocation, $event->verification_status);
            $this->assertTrue($event->metadata['forwarded']);
        }
        $this->assertSame(0, AttendanceSession::query()->count());
    }

    public function test_a_location_without_a_pending_action_writes_nothing(): void
    {
        $world = $this->placedWorld();

        $this->assertSame(BotText::LOCATION_WITHOUT_ACTION, $this->sendLocation((int) $world['students'][0]->telegram_user_id, ...self::INSIDE));
        $this->assertSame(0, AttendanceEvent::query()->count());
    }

    public function test_text_while_waiting_for_location_asks_for_the_location_again(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;
        $this->say($userId, Keyboard::START);

        $this->assertStringContainsString('Joylashuv yuborish kerak', $this->say($userId, '41.3112, 69.2797'));
        $this->assertSame(0, AttendanceEvent::query()->count());
    }

    public function test_a_menu_button_cancels_the_waiting_location_dialog(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;
        $this->say($userId, Keyboard::START);
        $this->say($userId, Keyboard::HELP);

        $this->assertSame(BotText::LOCATION_WITHOUT_ACTION, $this->sendLocation($userId, ...self::INSIDE));
        $this->assertSame(0, AttendanceEvent::query()->count());
    }

    public function test_student_without_an_active_assignment_is_told_so(): void
    {
        $world = $this->world();

        $this->assertSame(BotText::NO_ACTIVE_ASSIGNMENT, $this->say((int) $world['students'][0]->telegram_user_id, Keyboard::START));
        $this->assertStringContainsString('biriktirilmagan', $this->say((int) $world['students'][0]->telegram_user_id, Keyboard::INTERNSHIP));
    }

    public function test_attendance_attempts_are_rate_limited_per_student(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;

        for ($i = 0; $i < BotHandler::ATTENDANCE_PER_MINUTE; $i++) {
            $this->say($userId, Keyboard::FINISH);
        }
        $this->assertSame(BotText::RATE_LIMITED, $this->say($userId, Keyboard::FINISH));
    }

    // ------------------------------------------------------------ change requests

    public function test_change_request_to_an_existing_organization_and_cancel(): void
    {
        $world = $this->placedWorld();
        $student = $world['students'][0];
        $userId = (int) $student->telegram_user_id;

        $this->assertStringContainsString('Sababni yozing', $this->say($userId, Keyboard::CHANGE));
        $this->assertSame(BotText::NO_COORDINATES, $this->say($userId, 'Men 41.311100, 69.279700 dagi joyga o‘tmoqchiman'));
        $this->assertSame(BotText::NO_COORDINATES, $this->say($userId, 'https://maps.app.goo.gl/abc'));
        $this->assertSame(BotText::NO_COORDINATES, $this->sendLocation($userId, ...self::INSIDE));
        $this->say($userId, 'Uyimga yaqinroq joy kerak');
        $this->say($userId, Keyboard::KIND_EXISTING);

        $buttons = $this->lastMarkup()['inline_keyboard'];
        $this->assertCount(1, $buttons, 'The current organization is not offered.');
        $this->assertSame('org:0', $buttons[0][0]['callback_data']);
        $this->assertSame($world['org2']->name, $buttons[0][0]['text']);

        $this->assertStringContainsString('So‘rovingiz yuborildi', $this->press($userId, 'org:0'));
        $request = InternshipChangeRequest::query()->sole();
        $this->assertSame(ChangeRequestType::ExistingOrganization, $request->request_type);
        $this->assertSame($world['org2']->id, $request->requested_organization_id);
        $this->assertSame(ChangeRequestStatus::Pending, $request->status);
        $this->assertNotEmpty($this->bot()->answered);

        $this->assertStringContainsString('ko‘rib chiqilayotgan so‘rov bor', $this->say($userId, Keyboard::CHANGE));
        $this->assertStringContainsString('bekor qilindi', $this->press($userId, 'cr:cancel'));
        $this->assertSame(ChangeRequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_change_request_for_a_new_organization(): void
    {
        $world = $this->placedWorld();
        $userId = (int) $world['students'][0]->telegram_user_id;

        $this->say($userId, Keyboard::CHANGE);
        $this->say($userId, 'Yangi joyda ishlamoqchiman');
        $this->say($userId, Keyboard::KIND_NEW);
        $this->say($userId, 'Adliya bo‘limi');
        $this->say($userId, 'Toshkent sh., Chilonzor 5');
        $this->assertStringContainsString('universitet tasdiqlamaguncha', $this->say($userId, 'Aliyev Vali +998 90 123 45 67'));

        $request = InternshipChangeRequest::query()->sole();
        $this->assertSame(ChangeRequestType::NewOrganization, $request->request_type);
        $this->assertSame('Adliya bo‘limi', $request->requested_organization_data['name']);
        $this->assertSame('+998901234567', $request->requested_organization_data['contact_phone']);
        $this->assertSame('Aliyev Vali', $request->requested_organization_data['contact_name']);
    }

    public function test_stale_organization_buttons_are_refused(): void
    {
        $world = $this->placedWorld();

        $this->assertStringContainsString('eskirgan', $this->press((int) $world['students'][0]->telegram_user_id, 'org:5'));
        $this->assertSame(0, InternshipChangeRequest::query()->count());
    }

    public function test_a_failing_send_does_not_break_processing(): void
    {
        $world = $this->placedWorld();
        $this->bot()->failSends = true;

        $this->deliver($this->messageUpdate((int) $world['students'][0]->telegram_user_id, ['text' => Keyboard::HELP]))->assertOk();
        $this->assertSame(1, DB::table('telegram_processed_updates')->count());
    }
}
