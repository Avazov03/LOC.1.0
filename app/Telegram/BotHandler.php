<?php

namespace App\Telegram;

use App\Enums\ActiveStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\DayMarkKind;
use App\Enums\DayStatus;
use App\Enums\EventSource;
use App\Exceptions\BusinessRuleException;
use App\Models\InternshipChangeRequest;
use App\Models\Organization;
use App\Models\StudentProfile;
use App\Models\SupervisorNotification;
use App\Models\SupervisorProfile;
use App\Models\TelegramConversation;
use App\Services\Attendance\AttendanceDayQuery;
use App\Services\Attendance\AttendanceMarkService;
use App\Services\Attendance\AttendanceOutcome;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\LocationInput;
use App\Services\ChangeRequests\InternshipChangeRequestService;
use App\Services\Internships\InviteService;
use App\Services\Onboarding\OnboardingException;
use App\Services\Onboarding\StudentOnboardingService;
use App\Services\Students\StudentAccessException;
use App\Services\Students\StudentContextService;
use App\Services\Students\StudentPhoneService;
use App\Services\Students\StudentTelegramRebindService;
use App\Services\Supervisors\SupervisorTelegramService;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Student bot dialogs (TELEGRAM-FLOW). Parses intent, calls application services, formats replies.
 * Holds no attendance, geofence or assignment rule of its own.
 */
class BotHandler
{
    public const JOIN_NAME = 'JOIN_NAME';

    public const JOIN_SURNAME = 'JOIN_SURNAME';

    public const JOIN_PHONE = 'JOIN_PHONE';

    public const JOIN_STUDENT_CODE = 'JOIN_STUDENT_CODE';

    public const JOIN_CONFIRM = 'JOIN_CONFIRM';

    public const AWAIT_CHECKIN_LOCATION = 'AWAIT_CHECKIN_LOCATION';

    public const AWAIT_CHECKOUT_LOCATION = 'AWAIT_CHECKOUT_LOCATION';

    public const CHANGE_REASON = 'CHANGE_REASON';

    public const CHANGE_KIND = 'CHANGE_KIND';

    public const CHANGE_EXISTING_ORG = 'CHANGE_EXISTING_ORG';

    public const CHANGE_NEW_NAME = 'CHANGE_NEW_NAME';

    public const CHANGE_NEW_ADDRESS = 'CHANGE_NEW_ADDRESS';

    public const CHANGE_NEW_CONTACT = 'CHANGE_NEW_CONTACT';

    public const CONFIRM_PHONE = 'CONFIRM_PHONE';

    /** A39: check-in and check-out attempts per student per minute. */
    public const ATTENDANCE_PER_MINUTE = 6;

    /** /start attempts with a token per Telegram user per minute. */
    public const JOIN_PER_MINUTE = 5;

    private const ASK_PHONE = 'Telefon raqamingizni pastdagi «📱 Raqamni yuborish» tugmasi orqali yuboring. Qo‘lda yozilgan raqam qabul qilinmaydi.';

    private const ORGANIZATION_LIST_LIMIT = 30;

    private const HISTORY_DAYS = 7;

    public function __construct(
        private readonly StudentContextService $context,
        private readonly StudentOnboardingService $onboarding,
        private readonly AttendanceService $attendance,
        private readonly AttendanceDayQuery $days,
        private readonly InternshipChangeRequestService $changes,
        private readonly ConversationStore $conversations,
        private readonly SupervisorTelegramService $supervisorTelegram,
        private readonly AttendanceMarkService $marks,
        private readonly StudentTelegramRebindService $rebind,
        private readonly StudentPhoneService $phones,
    ) {}

    /**
     * @return list<Reply>
     */
    public function handle(Update $update): array
    {
        if ($update->callbackId !== null) {
            return $this->callback($update);
        }

        $text = trim((string) $update->text);
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            return $this->start($update, trim(substr($text, 6)));
        }

        $conversation = $this->conversations->get($update->userId);

        // A linked supervisor who is not also a student only receives notifications; anything typed gets the help text.
        $joining = $conversation !== null && str_starts_with($conversation->state, 'JOIN_');
        if (! $joining && ! $this->isRegistered($update->userId) && ($supervisor = $this->supervisorTelegram->linkedProfile($update->userId)) !== null) {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, $this->supervisorHelp($supervisor), Keyboard::remove())];
        }
        $action = Keyboard::action($text);

        if ($action === 'cancel') {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, BotText::CANCELLED, $this->isRegistered($update->userId) ? Keyboard::menu() : Keyboard::remove())];
        }

        if ($conversation !== null && str_starts_with($conversation->state, 'JOIN_')) {
            return $this->join($update, $conversation, $action);
        }

        // A menu button from any other dialog cancels that dialog and writes nothing (TELEGRAM-FLOW §9).
        if ($action !== null && in_array($action, Keyboard::MENU_ACTIONS, true)) {
            if ($conversation !== null) {
                $this->conversations->clear($update->userId);
            }

            return $this->menu($update, $action);
        }

        if ($update->location !== null) {
            return $this->location($update, $conversation);
        }

        if ($update->contact !== null && ($conversation === null || $conversation->state === self::CONFIRM_PHONE)) {
            return $this->contact($update);
        }
        if ($conversation?->state === self::CONFIRM_PHONE) {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, 'Mayli. Raqamni keyinroq «👤 Profilim» bo‘limidan tasdiqlashingiz mumkin.', Keyboard::menu())];
        }

        if ($conversation !== null) {
            return $this->dialog($update, $conversation, $action, $text);
        }

        $student = $this->activeStudent($update->userId);
        if ($student === null) {
            return [$this->reply($update, $this->isRegistered($update->userId) ? BotText::ACCESS_DENIED : BotText::NEED_INVITE, Keyboard::remove())];
        }

        return [$this->reply($update, BotText::UNKNOWN, Keyboard::menu())];
    }

    // ---------------------------------------------------------------- onboarding

    /**
     * @return list<Reply>
     */
    private function start(Update $update, string $token): array
    {
        if (str_starts_with($token, SupervisorTelegramService::PREFIX)) {
            return $this->linkSupervisor($update, substr($token, strlen(SupervisorTelegramService::PREFIX)));
        }
        if (str_starts_with($token, StudentTelegramRebindService::PREFIX)) {
            return $this->rebindStudent($update, substr($token, strlen(StudentTelegramRebindService::PREFIX)));
        }

        $existing = StudentProfile::query()->where('telegram_user_id', $update->userId)->first();
        if ($existing !== null) {
            // Repeated /start, with or without a token, never creates a second student.
            $this->conversations->clear($update->userId);
            if ($this->activeStudent($update->userId) === null) {
                return [$this->reply($update, BotText::ACCESS_DENIED, Keyboard::remove())];
            }

            return [$this->reply($update, BotText::ALREADY_REGISTERED."\n\n".BotText::UNKNOWN, Keyboard::menu())];
        }

        if ($token === '') {
            $supervisor = $this->supervisorTelegram->linkedProfile($update->userId);

            if ($supervisor !== null) {
                return [$this->reply($update, $this->supervisorHelp($supervisor), Keyboard::remove())];
            }

            return [$this->reply($update, BotText::NEED_INVITE."\n\n".BotText::RECOVERY_HINT, Keyboard::contact())];
        }

        $key = 'tg-join:'.$update->userId;
        if (RateLimiter::tooManyAttempts($key, self::JOIN_PER_MINUTE)) {
            return [$this->reply($update, BotText::RATE_LIMITED)];
        }
        RateLimiter::hit($key, 60);

        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $token)) {
            return [$this->reply($update, (new OnboardingException(OnboardingException::INVALID_INVITE))->getMessage(), Keyboard::remove())];
        }

        $hash = InviteService::hash($token);
        try {
            $invite = $this->onboarding->contextByHash($hash);
        } catch (OnboardingException $exception) {
            return [$this->reply($update, $exception->getMessage(), Keyboard::remove())];
        }

        $this->conversations->put($update->userId, self::JOIN_NAME, ['h' => $hash]);

        return [$this->reply($update, BotText::lines([
            'Assalomu alaykum!',
            "Siz {$invite['course']} {$invite['group']} amaliyotiga qo‘shilmoqdasiz.",
            "{$invite['university']}, {$invite['program']}.",
            'Amaliyot muddati: '.$this->date($invite['period_start']).' — '.$this->date($invite['period_end']).'.',
            '',
            'Ismingizni yozing:',
        ]), Keyboard::reply([[Keyboard::CANCEL]]))];
    }

    /**
     * @return list<Reply>
     */
    private function linkSupervisor(Update $update, string $token): array
    {
        $key = 'tg-join:'.$update->userId;
        if (RateLimiter::tooManyAttempts($key, self::JOIN_PER_MINUTE)) {
            return [$this->reply($update, BotText::RATE_LIMITED)];
        }
        RateLimiter::hit($key, 60);

        $profile = $this->supervisorTelegram->link($token, $update->userId);
        if ($profile === null) {
            return [$this->reply($update, 'Havola noto‘g‘ri yoki muddati tugagan. Saytdagi «Profil» sahifasida yangi havola oling.')];
        }
        $this->conversations->clear($update->userId);
        $student = $this->activeStudent($update->userId);

        return [$this->reply($update, BotText::lines([
            '✅ Telegram hisobingiz rahbar profiliga ulandi: '.$profile->user->name.'.',
            '',
            $this->supervisorHelp($profile),
        ]), $student ? Keyboard::menu() : Keyboard::remove())];
    }

    /**
     * @return list<Reply>
     */
    private function rebindStudent(Update $update, string $token): array
    {
        $key = 'tg-join:'.$update->userId;
        if (RateLimiter::tooManyAttempts($key, self::JOIN_PER_MINUTE)) {
            return [$this->reply($update, BotText::RATE_LIMITED)];
        }
        RateLimiter::hit($key, 60);

        try {
            $result = $this->rebind->rebind($token, $update->userId);
        } catch (OnboardingException) {
            return [$this->reply($update, 'Bu Telegram hisobi boshqa talabaga bog‘langan. Shu talaba profilingizga ulangan Telegram hisobidan foydalaning yoki rahbaringizga murojaat qiling.', $this->isRegistered($update->userId) ? Keyboard::menu() : Keyboard::remove())];
        }
        if ($result === null) {
            return [$this->reply($update, 'Havola noto‘g‘ri yoki muddati tugagan. Rahbaringizdan yangi havola so‘rang.', $this->isRegistered($update->userId) ? Keyboard::menu() : Keyboard::remove())];
        }

        // The number may have changed with the new account; one tap confirms it, "skip" keeps the old one.
        $this->conversations->put($update->userId, self::CONFIRM_PHONE);

        return $this->moved($update, $result['student'], $result['previous_telegram_user_id'], BotText::lines([
            '✅ Telegram hisobingiz talaba profilingizga ulandi: '.$result['student']->fullName().'.',
            'Davomat tarixi va amaliyot joyingiz saqlangan.',
            '',
            'Telefon raqamingizni tasdiqlash uchun «📱 Raqamni yuborish» tugmasini bosing. Keyingi safar Telegram hisobingiz almashsa, profilingizni shu raqam orqali o‘zingiz tiklay olasiz.',
        ]), Keyboard::confirmPhone());
    }

    /**
     * A contact sent outside onboarding: a registered student confirms (or updates) their number;
     * an unknown account tries to recover a profile with a verified number (A85).
     *
     * @return list<Reply>
     */
    private function contact(Update $update): array
    {
        $this->conversations->clear($update->userId);
        $own = $update->contact !== null && $update->contact['user_id'] === $update->userId;
        $student = StudentProfile::query()->where('telegram_user_id', $update->userId)->first();

        if ($student !== null) {
            if ($this->activeStudent($update->userId) === null) {
                return [$this->reply($update, BotText::ACCESS_DENIED, Keyboard::remove())];
            }
            if (! $own) {
                return [$this->reply($update, 'Faqat o‘zingizning raqamingizni «📱 Raqamni yuborish» tugmasi orqali yuboring.', Keyboard::menu())];
            }

            return [$this->reply($update, $this->phones->confirm($student, $update->contact['phone']) === StudentPhoneService::CONFIRMED
                ? '✅ Telefon raqamingiz tasdiqlandi: '.$student->fresh()->phone.'.'
                : '❌ Bu raqam boshqa talabaga tegishli. Rahbaringizga murojaat qiling.', Keyboard::menu())];
        }

        $key = 'tg-join:'.$update->userId;
        if (RateLimiter::tooManyAttempts($key, self::JOIN_PER_MINUTE)) {
            return [$this->reply($update, BotText::RATE_LIMITED)];
        }
        RateLimiter::hit($key, 60);
        if (! $own) {
            return [$this->reply($update, 'Faqat o‘zingizning raqamingizni «📱 Raqamni yuborish» tugmasi orqali yuboring.', Keyboard::contact())];
        }

        return $this->recover($update, $update->contact['phone']) ?? [$this->reply($update, BotText::RECOVERY_NOT_FOUND, Keyboard::remove())];
    }

    /**
     * @return list<Reply>|null null when no verified profile has this number
     */
    private function recover(Update $update, string $phone): ?array
    {
        $result = $this->phones->recover($update->userId, $phone);
        if ($result['code'] !== StudentPhoneService::RECOVERED) {
            return null;
        }
        $this->conversations->clear($update->userId);

        return $this->moved($update, $result['student'], $result['previous_telegram_user_id'], BotText::lines([
            '✅ Profilingiz tiklandi: '.$result['student']->fullName().'.',
            'Davomat tarixi va amaliyot joyingiz saqlangan. Davomatni endi shu Telegram hisobidan qayd eting.',
        ]), Keyboard::menu());
    }

    /**
     * Reply to the new account and tell the old one it lost the profile.
     *
     * @param  array<string, mixed>  $markup
     * @return list<Reply>
     */
    private function moved(Update $update, StudentProfile $student, ?int $previous, string $text, array $markup): array
    {
        $replies = [$this->reply($update, $text, $markup)];
        if ($previous !== null && $previous !== $update->userId) {
            $this->conversations->clear($previous);
            $replies[] = new Reply($previous, BotText::lines([
                'ℹ️ Talaba profilingiz ('.$student->fullName().') boshqa Telegram hisobiga ko‘chirildi. Bu hisobdan endi davomat qayd etib bo‘lmaydi.',
                'Agar buni siz qilmagan bo‘lsangiz, darhol rahbaringizga murojaat qiling.',
            ]), Keyboard::remove());
        }

        return $replies;
    }

    private function supervisorHelp(SupervisorProfile $supervisor): string
    {
        $supervisor->loadMissing('university:id,reminder_time');

        return BotText::lines([
            '👨‍🏫 Siz amaliyot rahbari sifatida ulangansiz.',
            '',
            $supervisor->notify_check_events ? '• Talabalaringiz kelgan va ketgan vaqti shu yerga keladi.' : null,
            '• Har kuni soat '.($supervisor->university?->reminder_time ?? '18:00').' da davomati belgilanmagan talabalar ro‘yxati keladi. «✅» tugmasi talabani «Keldi» deb belgilaydi.',
            '',
            'Batafsil ma’lumot va sozlamalar saytdagi «Davomat» va «Profil» sahifalarida.',
        ]);
    }

    /**
     * @return list<Reply>
     */
    private function join(Update $update, TelegramConversation $conversation, ?string $action): array
    {
        $context = $conversation->context ?? [];
        $text = trim((string) $update->text);
        $cancel = Keyboard::reply([[Keyboard::CANCEL]]);

        switch ($conversation->state) {
            case self::JOIN_NAME:
            case self::JOIN_SURNAME:
                if (! $this->validName($text) || $action !== null) {
                    return [$this->reply($update, 'Iltimos, faqat harflardan iborat haqiqiy '.($conversation->state === self::JOIN_NAME ? 'ismingizni' : 'familiyangizni').' yozing (2–60 belgi).', $cancel)];
                }
                if ($conversation->state === self::JOIN_NAME) {
                    $this->conversations->put($update->userId, self::JOIN_SURNAME, [...$context, 'first_name' => $this->cleanName($text)]);

                    return [$this->reply($update, 'Familiyangizni yozing:', $cancel)];
                }
                $this->conversations->put($update->userId, self::JOIN_PHONE, [...$context, 'last_name' => $this->cleanName($text)]);

                return [$this->reply($update, self::ASK_PHONE, Keyboard::contact())];

            case self::JOIN_PHONE:
                // Only the "share my number" button proves the number belongs to this Telegram account.
                if ($update->contact === null) {
                    return [$this->reply($update, self::ASK_PHONE, Keyboard::contact())];
                }
                if ($update->contact['user_id'] !== $update->userId) {
                    return [$this->reply($update, 'Faqat o‘zingizning telefon raqamingizni «📱 Raqamni yuborish» tugmasi orqali yuboring.', Keyboard::contact())];
                }
                $phone = $this->normalizePhone($update->contact['phone']);
                if ($phone === null) {
                    return [$this->reply($update, 'Telefon raqamingiz qabul qilinmadi. Rahbaringizga murojaat qiling.', Keyboard::contact())];
                }
                try {
                    $this->onboarding->assertPhoneAvailable((string) ($context['h'] ?? ''), $phone);
                } catch (OnboardingException $exception) {
                    // Same verified number: this is the registered student on a new account, so restore the profile.
                    if ($exception->reason === OnboardingException::PHONE_REGISTERED && ($recovered = $this->recover($update, $phone)) !== null) {
                        return $recovered;
                    }
                    $this->conversations->clear($update->userId);

                    return [$this->reply($update, $exception->getMessage(), Keyboard::remove())];
                }
                $this->conversations->put($update->userId, self::JOIN_STUDENT_CODE, [...$context, 'phone' => $phone]);

                return [$this->reply($update, 'Talaba ID raqamingizni yozing yoki «⏭ O‘tkazib yuborish» tugmasini bosing:', Keyboard::reply([[Keyboard::SKIP], [Keyboard::CANCEL]]))];

            case self::JOIN_STUDENT_CODE:
                $code = null;
                if ($action !== 'skip') {
                    if (! preg_match('/^[\p{L}\p{N}\/._-]{2,32}$/u', $text)) {
                        return [$this->reply($update, 'Talaba ID 2–32 belgidan iborat bo‘lsin (harf, raqam, - / . _). Yoki «⏭ O‘tkazib yuborish»ni bosing.', Keyboard::reply([[Keyboard::SKIP], [Keyboard::CANCEL]]))];
                    }
                    $code = $text;
                }
                $context = [...$context, 'student_code' => $code];
                $this->conversations->put($update->userId, self::JOIN_CONFIRM, $context);

                return [$this->confirmPrompt($update, $context)];

            case self::JOIN_CONFIRM:
                if ($action === 'restart') {
                    $this->conversations->put($update->userId, self::JOIN_NAME, ['h' => $context['h'] ?? '']);

                    return [$this->reply($update, 'Ismingizni yozing:', $cancel)];
                }
                if ($action !== 'confirm') {
                    return [$this->confirmPrompt($update, $context)];
                }

                return $this->completeJoin($update, $context);
        }

        $this->conversations->clear($update->userId);

        return [$this->reply($update, BotText::NEED_INVITE, Keyboard::remove())];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<Reply>
     */
    private function completeJoin(Update $update, array $context): array
    {
        try {
            $this->onboarding->joinByHash(
                (string) ($context['h'] ?? ''),
                $update->userId,
                (string) $context['first_name'],
                (string) $context['last_name'],
                (string) $context['phone'],
                $context['student_code'] ?? null,
            );
        } catch (OnboardingException $exception) {
            if ($exception->reason === OnboardingException::STUDENT_CODE_TAKEN) {
                $this->conversations->put($update->userId, self::JOIN_STUDENT_CODE, $context);

                return [$this->reply($update, $exception->getMessage().' Boshqa ID yozing yoki «⏭ O‘tkazib yuborish»ni bosing.', Keyboard::reply([[Keyboard::SKIP], [Keyboard::CANCEL]]))];
            }
            $this->conversations->clear($update->userId);
            $registered = $exception->reason === OnboardingException::ALREADY_REGISTERED && $this->activeStudent($update->userId) !== null;

            return [$this->reply($update, $exception->getMessage(), $registered ? Keyboard::menu() : Keyboard::remove())];
        }

        $this->conversations->clear($update->userId);

        return [$this->reply($update, BotText::lines([
            '✅ Ro‘yxatdan o‘tdingiz!',
            '',
            'Amaliyot joyingiz biriktirilgach, sizga xabar beramiz. Shundan so‘ng «🟢 Amaliyotni boshlash» orqali davomatni qayd etasiz.',
        ]), Keyboard::menu())];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function confirmPrompt(Update $update, array $context): Reply
    {
        return $this->reply($update, BotText::lines([
            'Ma’lumotlaringizni tekshiring:',
            '',
            'Ism: '.$context['first_name'],
            'Familiya: '.$context['last_name'],
            'Telefon: '.$context['phone'],
            'Talaba ID: '.($context['student_code'] ?? '—'),
            '',
            'Hammasi to‘g‘ri bo‘lsa «✅ Tasdiqlash»ni bosing.',
        ]), Keyboard::reply([[Keyboard::CONFIRM], [Keyboard::RESTART, Keyboard::CANCEL]]));
    }

    // ---------------------------------------------------------------- menu

    /**
     * @return list<Reply>
     */
    private function menu(Update $update, string $action): array
    {
        try {
            $student = $this->context->student($update->userId);
        } catch (StudentAccessException) {
            return [$this->reply($update, $this->isRegistered($update->userId) ? BotText::ACCESS_DENIED : BotText::NEED_INVITE, Keyboard::remove())];
        }

        return match ($action) {
            'internship' => [$this->internship($update)],
            'start' => $this->beginAttendance($update, $student, true),
            'finish' => $this->beginAttendance($update, $student, false),
            'attendance' => [$this->history($update, $student)],
            'change' => $this->changeStart($update, $student),
            'profile' => [$this->profile($update)],
            'help' => [$this->reply($update, BotText::HELP, Keyboard::menu())],
            default => [$this->reply($update, BotText::UNKNOWN, Keyboard::menu())],
        };
    }

    private function internship(Update $update): Reply
    {
        $assignment = $this->context->profile($update->userId)['assignment'];
        if ($assignment === null) {
            return $this->reply($update, 'Sizga hali amaliyot joyi biriktirilmagan. Biriktirilgach, sizga xabar beramiz.', Keyboard::menu());
        }

        return $this->reply($update, BotText::lines([
            '📋 Mening amaliyotim',
            '',
            '🏢 '.$assignment['organization'],
            $assignment['address'] ? '📍 Manzil: '.$assignment['address'] : null,
            '📅 Muddat: '.$assignment['start_at'].' — '.$assignment['end_at'],
            '🗓 Amaliyot kunlari: '.$assignment['work_days'],
            $assignment['supervisor'] ? '👨‍🏫 Rahbar: '.$assignment['supervisor'] : null,
            'Holat: '.($assignment['status'] === 'ACTIVE' ? 'faol' : 'boshlanishi kutilmoqda'),
        ]), Keyboard::menu());
    }

    private function profile(Update $update): Reply
    {
        $profile = $this->context->profile($update->userId);
        $verified = $profile['phone_verified'];
        if (! $verified) {
            $this->conversations->put($update->userId, self::CONFIRM_PHONE);
        }

        return $this->reply($update, BotText::lines([
            '👤 Profilim',
            '',
            'F.I.Sh.: '.$profile['name'],
            'Telefon: '.$profile['phone'].($verified ? ' ✅' : ' (tasdiqlanmagan)'),
            'Universitet: '.$profile['university'],
            $profile['program'] ? 'Yo‘nalish: '.$profile['program'] : null,
            $profile['course'] || $profile['group'] ? 'Kurs / guruh: '.trim(($profile['course'] ?? '').' '.($profile['group'] ?? '')) : null,
            'Talaba ID: '.($profile['student_code'] ?? '—'),
            'Holat: faol',
            $verified ? null : '',
            $verified ? null : '📱 Raqamingizni tasdiqlang: Telegram hisobingiz almashsa, profilingizni shu raqam orqali o‘zingiz tiklay olasiz. Raqamingiz o‘zgargan bo‘lsa ham shu tugmani bosing.',
        ]), $verified ? Keyboard::menu() : Keyboard::confirmPhone());
    }

    private function history(Update $update, StudentProfile $student): Reply
    {
        $student->loadMissing('university');
        $tz = $student->university->timezone;
        $today = CarbonImmutable::parse($student->university->today());
        $dates = AttendanceDayQuery::dateRange($today->subDays(self::HISTORY_DAYS - 1)->toDateString(), $today->toDateString());

        $rows = $this->days->rows(StudentProfile::query()->whereKey($student->id), $dates, $tz)
            ->whereNotNull('day_status')
            ->orderByDesc('local_date')
            ->get();

        if ($rows->isEmpty()) {
            return $this->reply($update, '📅 So‘nggi '.self::HISTORY_DAYS.' kunda davomat yozuvi yo‘q.', Keyboard::menu());
        }

        $lines = ['📅 Davomatim (so‘nggi '.self::HISTORY_DAYS.' kun)', ''];
        foreach ($rows as $row) {
            $status = DayStatus::from($row->day_status);
            $date = CarbonImmutable::parse((string) $row->local_date)->format('d.m');
            $line = "{$date} — {$status->icon()} {$status->label()}";
            if ($row->mark_kind !== null) {
                $line .= ' (rahbar belgiladi)';
            }
            if ($row->first_check_in !== null) {
                $in = CarbonImmutable::parse($row->first_check_in, 'UTC')->setTimezone($tz)->format('H:i');
                $out = $row->last_check_out !== null ? CarbonImmutable::parse($row->last_check_out, 'UTC')->setTimezone($tz)->format('H:i') : '—';
                $line .= "\n      {$in} – {$out}";
                if ((int) $row->completed_seconds > 0) {
                    $line .= ' ('.BotText::duration((int) $row->completed_seconds).')';
                }
            }
            $lines[] = $line;
        }

        return $this->reply($update, implode("\n", $lines), Keyboard::menu());
    }

    // ---------------------------------------------------------------- attendance

    /**
     * @return list<Reply>
     */
    private function beginAttendance(Update $update, StudentProfile $student, bool $checkIn): array
    {
        if ($limited = $this->attendanceLimited($update, $student)) {
            return [$limited];
        }

        $outcome = $checkIn ? $this->attendance->prepareCheckIn($student) : $this->attendance->prepareCheckOut($student);
        if ($outcome->code !== AttendanceOutcome::READY) {
            return [$this->reply($update, BotText::outcome($outcome), Keyboard::menu())];
        }

        // A49: an admin turned location off for this scope, so the action completes without a location.
        if (! $outcome->data['location_required']) {
            $result = $checkIn
                ? $this->attendance->checkIn($student, null, EventSource::Telegram, $update->updateId)
                : $this->attendance->checkOut($student, null, EventSource::Telegram, $update->updateId);

            return [$this->reply($update, BotText::outcome($result), Keyboard::menu())];
        }

        $this->conversations->put($update->userId, $checkIn ? self::AWAIT_CHECKIN_LOCATION : self::AWAIT_CHECKOUT_LOCATION);

        return [$this->reply($update, BotText::ASK_LOCATION, Keyboard::location())];
    }

    /**
     * @return list<Reply>
     */
    private function location(Update $update, ?TelegramConversation $conversation): array
    {
        $state = $conversation?->state;
        if (in_array($state, [self::CHANGE_REASON, self::CHANGE_KIND, self::CHANGE_EXISTING_ORG, self::CHANGE_NEW_NAME, self::CHANGE_NEW_ADDRESS, self::CHANGE_NEW_CONTACT], true)) {
            return [$this->reply($update, BotText::NO_COORDINATES)];
        }
        if ($state !== self::AWAIT_CHECKIN_LOCATION && $state !== self::AWAIT_CHECKOUT_LOCATION) {
            // A location with no waiting action is ignored and never becomes attendance (TELEGRAM-FLOW §9).
            $student = $this->activeStudent($update->userId);

            return [$this->reply($update, $student ? BotText::LOCATION_WITHOUT_ACTION : ($this->isRegistered($update->userId) ? BotText::ACCESS_DENIED : BotText::NEED_INVITE), $student ? Keyboard::menu() : Keyboard::remove())];
        }

        try {
            $student = $this->context->student($update->userId);
        } catch (StudentAccessException) {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, BotText::ACCESS_DENIED, Keyboard::remove())];
        }
        if ($limited = $this->attendanceLimited($update, $student)) {
            return [$limited];
        }

        $input = new LocationInput(
            $update->location['latitude'],
            $update->location['longitude'],
            $update->location['accuracy'],
            $update->forwarded,
            $update->location['live'],
            $update->messageDate,
        );
        $outcome = $state === self::AWAIT_CHECKIN_LOCATION
            ? $this->attendance->checkIn($student, $input, EventSource::Telegram, $update->updateId)
            : $this->attendance->checkOut($student, $input, EventSource::Telegram, $update->updateId);

        if ($outcome->retryable()) {
            $this->conversations->put($update->userId, $state);

            return [$this->reply($update, BotText::outcome($outcome), Keyboard::location())];
        }
        $this->conversations->clear($update->userId);

        return [$this->reply($update, BotText::outcome($outcome), Keyboard::menu())];
    }

    private function attendanceLimited(Update $update, StudentProfile $student): ?Reply
    {
        $key = 'tg-attendance:'.$student->id;
        if (RateLimiter::tooManyAttempts($key, self::ATTENDANCE_PER_MINUTE)) {
            return $this->reply($update, BotText::RATE_LIMITED, Keyboard::menu());
        }
        RateLimiter::hit($key, 60);

        return null;
    }

    // ---------------------------------------------------------------- change request

    /**
     * @return list<Reply>
     */
    private function changeStart(Update $update, StudentProfile $student): array
    {
        try {
            $this->context->activeAssignment($update->userId);
        } catch (StudentAccessException) {
            return [$this->reply($update, 'Sizda o‘zgartiriladigan faol amaliyot joyi yo‘q.', Keyboard::menu())];
        }

        $pending = $this->pendingRequest($student);
        if ($pending !== null) {
            $target = $pending->request_type === ChangeRequestType::ExistingOrganization
                ? $pending->requestedOrganization?->name
                : ($pending->requested_organization_data['name'] ?? null);

            return [$this->reply($update, BotText::lines([
                '🔄 Sizda ko‘rib chiqilayotgan so‘rov bor.',
                '',
                $target ? 'So‘ralgan joy: '.$target : null,
                'Yuborilgan: '.$pending->created_at->setTimezone($student->university->timezone)->format('d.m.Y H:i'),
                'Holat: ko‘rib chiqilmoqda',
                '',
                'Ikkinchi so‘rov ochilmaydi. Kerak bo‘lsa, bu so‘rovni bekor qiling.',
            ]), Keyboard::inline([[['text' => '❌ So‘rovni bekor qilish', 'callback_data' => 'cr:cancel']]]))];
        }

        $this->conversations->put($update->userId, self::CHANGE_REASON);

        return [$this->reply($update, 'Nima uchun amaliyot joyini o‘zgartirmoqchisiz? Sababni yozing:', Keyboard::reply([[Keyboard::CANCEL]]))];
    }

    /**
     * @return list<Reply>
     */
    private function dialog(Update $update, TelegramConversation $conversation, ?string $action, string $text): array
    {
        $state = $conversation->state;
        $context = $conversation->context ?? [];
        $cancel = Keyboard::reply([[Keyboard::CANCEL]]);

        if ($state === self::AWAIT_CHECKIN_LOCATION || $state === self::AWAIT_CHECKOUT_LOCATION) {
            return [$this->reply($update, BotText::outcome(new AttendanceOutcome(AttendanceOutcome::LOCATION_MISSING)), Keyboard::location())];
        }

        $student = $this->activeStudent($update->userId);
        if ($student === null) {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, BotText::ACCESS_DENIED, Keyboard::remove())];
        }

        if ($text !== '' && $this->containsCoordinates($text)) {
            return [$this->reply($update, BotText::NO_COORDINATES, $cancel)];
        }

        switch ($state) {
            case self::CHANGE_REASON:
                if (mb_strlen($text) < 5) {
                    return [$this->reply($update, 'Sababni batafsilroq yozing (kamida 5 belgi).', $cancel)];
                }
                $this->conversations->put($update->userId, self::CHANGE_KIND, ['reason' => mb_substr($text, 0, 1000)]);

                return [$this->reply($update, 'Qaysi tashkilotga o‘tmoqchisiz?', Keyboard::reply([[Keyboard::KIND_EXISTING, Keyboard::KIND_NEW], [Keyboard::CANCEL]]))];

            case self::CHANGE_KIND:
                if ($action === 'kind_existing') {
                    return $this->organizationList($update, $student, $context, null);
                }
                if ($action === 'kind_new') {
                    $this->conversations->put($update->userId, self::CHANGE_NEW_NAME, $context);

                    return [$this->reply($update, 'Yangi tashkilot nomini yozing:', $cancel)];
                }

                return [$this->reply($update, 'Variantlardan birini tanlang.', Keyboard::reply([[Keyboard::KIND_EXISTING, Keyboard::KIND_NEW], [Keyboard::CANCEL]]))];

            case self::CHANGE_EXISTING_ORG:
                return $this->organizationList($update, $student, $context, $text);

            case self::CHANGE_NEW_NAME:
                if (mb_strlen($text) < 2) {
                    return [$this->reply($update, 'Tashkilot nomini yozing.', $cancel)];
                }
                $this->conversations->put($update->userId, self::CHANGE_NEW_ADDRESS, [...$context, 'name' => mb_substr($text, 0, 255)]);

                return [$this->reply($update, 'Tashkilot manzilini yozing (shahar, tuman, ko‘cha):', $cancel)];

            case self::CHANGE_NEW_ADDRESS:
                if (mb_strlen($text) < 5) {
                    return [$this->reply($update, 'Manzilni to‘liqroq yozing.', $cancel)];
                }
                $this->conversations->put($update->userId, self::CHANGE_NEW_CONTACT, [...$context, 'address' => mb_substr($text, 0, 255)]);

                return [$this->reply($update, 'Mas’ul shaxs ismi va telefon raqamini yozing (masalan: Aliyev Vali +998901234567) yoki «⏭ O‘tkazib yuborish»ni bosing:', Keyboard::reply([[Keyboard::SKIP], [Keyboard::CANCEL]]))];

            case self::CHANGE_NEW_CONTACT:
                $data = ['name' => $context['name'] ?? '', 'address' => $context['address'] ?? ''];
                if ($action !== 'skip' && $text !== '') {
                    if (preg_match('/\+?\d[\d\s()-]{7,}\d/', $text, $match)) {
                        $data['contact_phone'] = $this->normalizePhone($match[0]) ?? trim($match[0]);
                        $text = trim(str_replace($match[0], '', $text), " ,;-\t");
                    }
                    if ($text !== '') {
                        $data['contact_name'] = mb_substr($text, 0, 255);
                    }
                }

                return $this->submitRequest($update, fn () => $this->changes->openNew($student->user, $student->id, $data, (string) ($context['reason'] ?? '')), true);
        }

        $this->conversations->clear($update->userId);

        return [$this->reply($update, BotText::UNKNOWN, Keyboard::menu())];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<Reply>
     */
    private function organizationList(Update $update, StudentProfile $student, array $context, ?string $search): array
    {
        $current = $student->openAssignment()->value('organization_id');
        $query = Organization::query()
            ->where('university_id', $student->university_id)
            ->where('status', ActiveStatus::Active->value)
            ->when($current, fn ($q) => $q->where('id', '<>', $current))
            ->orderBy('name');

        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->conversations->put($update->userId, self::CHANGE_EXISTING_ORG, [...$context, 'ids' => []]);

            return [$this->reply($update, $search === null
                ? 'Tanlash mumkin bo‘lgan faol tashkilot yo‘q. «✖️ Bekor qilish»ni bosib, «➕ Yangi tashkilot» variantini tanlang.'
                : 'Bu nom bo‘yicha tashkilot topilmadi. Boshqa so‘z bilan qidiring.', Keyboard::reply([[Keyboard::CANCEL]]))];
        }
        if ($search === null && $total > self::ORGANIZATION_LIST_LIMIT) {
            $this->conversations->put($update->userId, self::CHANGE_EXISTING_ORG, [...$context, 'ids' => []]);

            return [$this->reply($update, 'Tashkilotlar ko‘p. Tashkilot nomidan bir qismini yozing:', Keyboard::reply([[Keyboard::CANCEL]]))];
        }

        $organizations = $query->limit(self::ORGANIZATION_LIST_LIMIT)->get(['id', 'name']);
        // Buttons carry a list position, not a database id (no internal ids leave the server).
        $this->conversations->put($update->userId, self::CHANGE_EXISTING_ORG, [...$context, 'ids' => $organizations->pluck('id')->all()]);
        $rows = $organizations->values()->map(fn (Organization $organization, int $index) => [['text' => mb_substr($organization->name, 0, 60), 'callback_data' => 'org:'.$index]])->all();

        return [$this->reply($update, 'Tashkilotni tanlang:', Keyboard::inline($rows))];
    }

    // ---------------------------------------------------------------- callbacks

    /**
     * @return list<Reply>
     */
    private function callback(Update $update): array
    {
        if (preg_match('/^dg:(\d{1,12}):(\d{1,2}|all)$/', (string) $update->callbackData, $match)) {
            return [$this->digestMark($update, (int) $match[1], $match[2])];
        }

        $student = $this->activeStudent($update->userId);
        if ($student === null) {
            return [$this->reply($update, BotText::ACCESS_DENIED, Keyboard::remove())];
        }
        $data = (string) $update->callbackData;

        if ($data === 'cr:cancel') {
            $pending = $this->pendingRequest($student);
            if ($pending === null) {
                return [$this->reply($update, 'Bekor qilinadigan so‘rov yo‘q.', Keyboard::menu())];
            }
            try {
                $this->changes->cancel($student->user, $pending->id);
            } catch (BusinessRuleException $exception) {
                return [$this->reply($update, $exception->getMessage(), Keyboard::menu())];
            }

            return [$this->reply($update, 'So‘rov bekor qilindi.', Keyboard::menu())];
        }

        if (preg_match('/^org:(\d{1,3})$/', $data, $match)) {
            $conversation = $this->conversations->get($update->userId);
            $ids = $conversation?->state === self::CHANGE_EXISTING_ORG ? ($conversation->context['ids'] ?? []) : [];
            $organizationId = $ids[(int) $match[1]] ?? null;
            if ($organizationId === null) {
                return [$this->reply($update, 'Bu ro‘yxat eskirgan. «🔄 Amaliyot joyini o‘zgartirish»ni qayta bosing.', Keyboard::menu())];
            }
            $reason = (string) ($conversation->context['reason'] ?? '');

            return $this->submitRequest($update, fn () => $this->changes->openExisting($student->user, $student->id, (int) $organizationId, $reason), false);
        }

        return [$this->reply($update, BotText::UNKNOWN, Keyboard::menu())];
    }

    /**
     * "✅" under a supervisor digest: marks the listed student (or all of them) PRESENT for the digest date.
     * The same AttendanceMarkService checks apply as on the web: scope, date window, assignment on that day.
     */
    private function digestMark(Update $update, int $notificationId, string $position): Reply
    {
        $supervisor = $this->supervisorTelegram->linkedProfile($update->userId);
        $notification = $supervisor === null ? null : SupervisorNotification::query()
            ->whereKey($notificationId)
            ->where('supervisor_profile_id', $supervisor->id)
            ->first();
        $students = $notification?->payload['students'] ?? [];
        $date = (string) ($notification?->payload['date'] ?? '');
        $targets = $position === 'all' ? $students : array_filter([$students[(int) $position] ?? null]);
        if ($supervisor === null || $targets === [] || $date === '') {
            return $this->reply($update, 'Bu ro‘yxat eskirgan yoki sizga tegishli emas.');
        }

        $done = [];
        $failed = [];
        foreach ($targets as [$studentId, $name]) {
            try {
                $this->marks->mark($supervisor->user, (int) $studentId, $date, DayMarkKind::Present, null, 'TELEGRAM');
                $done[] = $name;
            } catch (BusinessRuleException|ValidationException $exception) {
                $failed[] = $name.': '.($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : $exception->getMessage());
            } catch (ModelNotFoundException|AuthorizationException) {
                $failed[] = $name.': talaba endi sizning guruhingizda emas.';
            }
        }

        $day = CarbonImmutable::parse($date)->format('d.m.Y');

        return $this->reply($update, BotText::lines([
            $done !== [] ? (count($done) === 1 ? "✅ {$done[0]} — {$day} kuni «Keldi» deb belgilandi." : '✅ '.count($done)." ta talaba {$day} kuni «Keldi» deb belgilandi.") : null,
            $failed !== [] ? "❌ Belgilanmadi:\n".implode("\n", $failed) : null,
        ]));
    }

    /**
     * @param  callable(): InternshipChangeRequest  $open
     * @return list<Reply>
     */
    private function submitRequest(Update $update, callable $open, bool $newOrganization): array
    {
        try {
            $open();
        } catch (ValidationException $exception) {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, collect($exception->errors())->flatten()->first() ?? BotText::GENERIC_FAILURE, Keyboard::menu())];
        } catch (BusinessRuleException $exception) {
            $this->conversations->clear($update->userId);

            return [$this->reply($update, $exception->getMessage(), Keyboard::menu())];
        }
        $this->conversations->clear($update->userId);

        return [$this->reply($update, BotText::lines([
            '✅ So‘rovingiz yuborildi.',
            $newOrganization ? 'Yangi tashkilot universitet tasdiqlamaguncha faol bo‘lmaydi.' : null,
            'Qaror qabul qilingach, sizga xabar beramiz.',
        ]), Keyboard::menu())];
    }

    // ---------------------------------------------------------------- helpers

    private function pendingRequest(StudentProfile $student): ?InternshipChangeRequest
    {
        return InternshipChangeRequest::query()
            ->where('student_profile_id', $student->id)
            ->where('status', ChangeRequestStatus::Pending->value)
            ->with('requestedOrganization:id,name')
            ->first();
    }

    private function activeStudent(int $telegramUserId): ?StudentProfile
    {
        try {
            return $this->context->student($telegramUserId)->load(['user', 'university']);
        } catch (StudentAccessException) {
            return null;
        }
    }

    private function isRegistered(int $telegramUserId): bool
    {
        return StudentProfile::query()->where('telegram_user_id', $telegramUserId)->exists();
    }

    private function validName(string $text): bool
    {
        return (bool) preg_match('/^[\p{L}][\p{L}\s\'’‘ʻʼ`-]{1,59}$/u', $text);
    }

    private function cleanName(string $text): string
    {
        return mb_convert_case(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), MB_CASE_TITLE);
    }

    private function normalizePhone(string $raw): ?string
    {
        return Phone::normalize($raw);
    }

    private function containsCoordinates(string $text): bool
    {
        return (bool) preg_match('/-?\d{1,3}[.,]\d{4,}\s*[,; ]\s*-?\d{1,3}[.,]\d{4,}/', $text)
            || (bool) preg_match('~(maps\.google|goo\.gl/maps|maps\.app\.goo\.gl|yandex\.[a-z]+/maps|2gis\.)~i', $text);
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d.m.Y');
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function reply(Update $update, string $text, ?array $markup = null): Reply
    {
        return new Reply($update->chatId, $text, $markup);
    }
}
