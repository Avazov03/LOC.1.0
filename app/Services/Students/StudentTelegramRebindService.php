<?php

namespace App\Services\Students;

use App\Enums\StudentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\OnboardingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves a student to a new Telegram account (lost phone, new number) through a one-time "/start r_<token>" link
 * created by an admin or the student's current supervisor. The profile, history and assignment stay the same.
 * Only the token hash is stored; the link is shown once, expires and works once.
 */
class StudentTelegramRebindService
{
    public const PREFIX = 'r_';

    public const LINK_HOURS = 24;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the Telegram deep link, or the raw /start parameter when no bot username is configured
     */
    public function createLink(User $actor, int $studentId): string
    {
        $this->scope->requireStaff($actor);
        $student = $this->scope->findStudent($actor, $studentId);
        if ($student->status !== StudentStatus::Active) {
            throw new BusinessRuleException('Talaba faol emas. Avval talabani faollashtiring.');
        }

        $token = Str::random(32);
        $student->forceFill([
            'telegram_rebind_hash' => self::hash($token),
            'telegram_rebind_expires_at' => now()->addHours(self::LINK_HOURS),
        ])->save();
        $this->audit->log($actor, 'student.telegram_rebind_link', $student, null, ['expires_in_hours' => self::LINK_HOURS]);

        $start = self::PREFIX.$token;
        $bot = config('services.telegram.bot_username');

        return $bot ? "https://t.me/{$bot}?start={$start}" : $start;
    }

    /**
     * Called by the bot for "/start r_<token>".
     *
     * @return array{student: StudentProfile, previous_telegram_user_id: int|null}|null null when the link is invalid or expired
     *
     * @throws OnboardingException when this Telegram account already belongs to another student
     */
    public function rebind(string $token, int $telegramUserId): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }

        return DB::transaction(function () use ($token, $telegramUserId) {
            $student = StudentProfile::query()
                ->where('telegram_rebind_hash', self::hash($token))
                ->where('telegram_rebind_expires_at', '>', now())
                ->with('user')
                ->lockForUpdate()
                ->first();
            if ($student === null || $student->status !== StudentStatus::Active) {
                return null;
            }
            if (StudentProfile::query()->where('telegram_user_id', $telegramUserId)->whereKeyNot($student->id)->exists()) {
                throw new OnboardingException(OnboardingException::ALREADY_REGISTERED);
            }

            $previous = $student->telegram_user_id !== null ? (int) $student->telegram_user_id : null;
            $student->forceFill([
                'telegram_user_id' => $telegramUserId,
                'telegram_rebind_hash' => null,
                'telegram_rebind_expires_at' => null,
            ])->save();
            if ($previous !== $telegramUserId) {
                $this->audit->log(
                    $student->user,
                    'student.telegram_rebind',
                    $student,
                    ['telegram_user_id' => $previous === null ? null : (string) $previous],
                    ['telegram_user_id' => (string) $telegramUserId],
                );
            }

            return ['student' => $student, 'previous_telegram_user_id' => $previous];
        });
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
