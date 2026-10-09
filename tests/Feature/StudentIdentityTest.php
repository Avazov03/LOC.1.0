<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Services\Onboarding\OnboardingException;
use App\Services\Students\StudentTelegramRebindService;
use App\Telegram\BotText;
use App\Telegram\Keyboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInternships;
use Tests\Concerns\TalksToBot;
use Tests\TestCase;

/**
 * Who a Telegram account is: phone only from the "share my number" button, one profile per phone,
 * and moving a student to a new Telegram account only through a staff-issued one-time link.
 */
class StudentIdentityTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;
    use TalksToBot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWebhook();
    }

    private function invite(array $world): string
    {
        $this->actingAs($world['admin'])->post('/internships/'.$world['internship']->id.'/invites')->assertSessionHas('invite_link');

        return (string) session('invite_link');
    }

    private function toPhoneStep(array $world, int $userId): void
    {
        $this->say($userId, '/start '.$this->invite($world));
        $this->say($userId, 'Ali');
        $this->say($userId, 'Valiyev');
    }

    private function rebindToken(string $link): string
    {
        $start = str_contains($link, 'start=') ? substr($link, strpos($link, 'start=') + 6) : $link;
        $this->assertStringStartsWith(StudentTelegramRebindService::PREFIX, $start);

        return $start;
    }

    /**
     * @return list<string>
     */
    private function textsTo(int $chatId): array
    {
        return array_values(array_map(fn (array $m) => $m['text'], array_filter($this->bot()->sent, fn (array $m) => $m['chat_id'] === $chatId)));
    }

    // ------------------------------------------------------------ phone

    public function test_typed_phone_is_refused_and_only_own_contact_is_accepted(): void
    {
        $world = $this->world();
        $userId = 881001;
        $this->toPhoneStep($world, $userId);

        $this->assertStringContainsString('Qo‘lda yozilgan raqam qabul qilinmaydi', $this->say($userId, '+998901234567'));
        $this->assertStringContainsString('Faqat o‘zingizning', $this->sendContact($userId, '+998901234567', null));
        $this->assertStringContainsString('Faqat o‘zingizning', $this->sendContact($userId, '+998901234567', 42));
        $this->assertStringContainsString('Talaba ID', $this->sendContact($userId, '+998901234567', $userId));
    }

    public function test_second_account_with_a_registered_phone_is_refused_at_the_phone_step(): void
    {
        $world = $this->world();
        $existing = $world['students'][0];
        $userId = 881002;
        $this->toPhoneStep($world, $userId);

        $reply = $this->sendContact($userId, preg_replace('/\D/', '', $existing->phone), $userId);

        $this->assertSame((new OnboardingException(OnboardingException::PHONE_REGISTERED))->getMessage(), $reply);
        $this->assertStringContainsString('qayta bog‘lash', $reply);
        $this->assertNull(StudentProfile::query()->where('telegram_user_id', $userId)->first());
        $this->assertSame(BotText::NEED_INVITE, $this->say($userId, 'salom'), 'The dialog is closed.');
    }

    public function test_phone_registered_while_the_dialog_was_open_is_refused_at_confirmation(): void
    {
        $world = $this->world();
        $userId = 881003;
        $this->toPhoneStep($world, $userId);
        $this->sendContact($userId, '+998901112299', $userId);
        $this->say($userId, Keyboard::SKIP);

        $world['students'][1]->update(['phone' => '+998 (90) 111-22-99']);

        $this->assertSame((new OnboardingException(OnboardingException::PHONE_REGISTERED))->getMessage(), $this->say($userId, Keyboard::CONFIRM));
        $this->assertNull(StudentProfile::query()->where('telegram_user_id', $userId)->first());
    }

    public function test_same_phone_in_another_university_does_not_block(): void
    {
        $world = $this->world();
        $other = $this->world('B', 2000);
        $userId = 881004;
        $this->toPhoneStep($world, $userId);

        $this->assertStringContainsString('Talaba ID', $this->sendContact($userId, $other['students'][0]->phone, $userId));
    }

    // ------------------------------------------------------------ rebind

    public function test_supervisor_moves_a_student_to_a_new_telegram_account_and_history_stays(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $assignment = $this->placement($world['internship'], $student, $world['org']);
        $oldId = (int) $student->telegram_user_id;
        $newId = 991001;
        $this->say($oldId, Keyboard::START);
        $this->assertStringContainsString('boshlanishi qayd etildi', (string) $this->sendLocation($oldId, 41.3112, 69.2797));
        $this->assertSame(1, AttendanceEvent::query()->where('student_profile_id', $student->id)->count());

        $this->actingAs($world['supervisor'])->post("/students/{$student->id}/telegram-rebind")->assertSessionHas('telegram_link');
        $token = $this->rebindToken((string) session('telegram_link'));
        $this->assertNotNull($student->fresh()->telegram_rebind_hash);
        $this->assertStringNotContainsString(substr($token, 2), (string) $student->fresh()->telegram_rebind_hash);

        $this->bot()->reset();
        $this->say($newId, '/start '.$token);

        $this->assertStringContainsString('talaba profilingizga ulandi', $this->textsTo($newId)[0]);
        $this->assertStringContainsString('boshqa Telegram hisobiga ko‘chirildi', $this->textsTo($oldId)[0]);
        $fresh = $student->fresh();
        $this->assertSame($newId, (int) $fresh->telegram_user_id);
        $this->assertNull($fresh->telegram_rebind_hash);
        $this->assertSame(1, StudentProfile::query()->where('phone', $student->phone)->count());
        $this->assertSame(1, AttendanceEvent::query()->where('student_profile_id', $student->id)->count());
        $this->assertSame($assignment->id, $fresh->openAssignment()->value('id'));

        $this->assertSame(BotText::NEED_INVITE, $this->say($oldId, Keyboard::START), 'The old account lost access.');
        $this->assertNotContains($this->say($newId, Keyboard::INTERNSHIP), [BotText::NEED_INVITE, BotText::ACCESS_DENIED]);
        $this->assertStringContainsString('Joylashuv', (string) $this->say($newId, Keyboard::FINISH));
        $this->assertStringContainsString('Amaliyot tugashi qayd etildi', (string) $this->sendLocation($newId, 41.3112, 69.2797), 'The open session from the old account continues.');

        $audit = AuditLog::query()->where('action', 'student.telegram_rebind')->sole();
        $this->assertSame((string) $oldId, $audit->before['telegram_user_id']);
        $this->assertSame((string) $newId, $audit->after['telegram_user_id']);
        $this->assertSame(1, AuditLog::query()->where('action', 'student.telegram_rebind_link')->where('actor_user_id', $world['supervisor']->id)->count());

        $this->assertStringContainsString('noto‘g‘ri yoki muddati tugagan', $this->say(991002, '/start '.$token), 'The link works once.');
    }

    public function test_rebind_link_expires_and_cannot_take_another_students_account(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $taken = (int) $world['students'][1]->telegram_user_id;

        $this->actingAs($world['admin'])->post("/students/{$student->id}/telegram-rebind")->assertSessionHas('telegram_link');
        $token = $this->rebindToken((string) session('telegram_link'));

        $this->assertStringContainsString('boshqa talabaga bog‘langan', $this->say($taken, '/start '.$token));
        $this->assertSame($taken, (int) $world['students'][1]->fresh()->telegram_user_id);
        $this->assertNotSame($taken, (int) $student->fresh()->telegram_user_id);

        $this->travel(StudentTelegramRebindService::LINK_HOURS + 1)->hours();
        $this->assertStringContainsString('noto‘g‘ri yoki muddati tugagan', $this->say(991003, '/start '.$token));
        $this->assertNull(StudentProfile::query()->where('telegram_user_id', 991003)->first());
    }

    public function test_rebind_link_is_scoped_and_needs_an_active_student(): void
    {
        $world = $this->world();
        $other = $this->world('B', 2000);
        $student = $world['students'][0];

        $this->actingAs($other['supervisor'])->post("/students/{$student->id}/telegram-rebind")->assertNotFound();
        $this->actingAs($other['admin'])->post("/students/{$student->id}/telegram-rebind")->assertNotFound();

        $student->update(['status' => StudentStatus::Blocked]);
        $this->actingAs($world['admin'])->post("/students/{$student->id}/telegram-rebind")->assertSessionHas('error');
        $this->assertNull($student->fresh()->telegram_rebind_hash);
    }

    public function test_blocked_student_cannot_use_an_issued_link(): void
    {
        $world = $this->world();
        $student = $world['students'][0];
        $this->actingAs($world['admin'])->post("/students/{$student->id}/telegram-rebind");
        $token = $this->rebindToken((string) session('telegram_link'));

        $student->update(['status' => StudentStatus::Blocked]);

        $this->assertStringContainsString('noto‘g‘ri yoki muddati tugagan', $this->say(991004, '/start '.$token));
        $this->assertNotSame(991004, (int) $student->fresh()->telegram_user_id);
    }
}
