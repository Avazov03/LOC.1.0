<?php

namespace App\Services\Supervisors;

use App\Enums\ActiveStatus;
use App\Models\SupervisorProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links a supervisor to the same Telegram bot the students use, through a one-time /start link.
 * Only the token hash is stored; the link is shown once and expires.
 */
class SupervisorTelegramService
{
    public const PREFIX = 's_';

    public const LINK_HOURS = 24;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return string the Telegram deep link, or the raw /start parameter when no bot username is configured
     */
    public function createLink(User $actor, SupervisorProfile $profile): string
    {
        $this->authorize($actor, $profile);
        $token = Str::random(32);
        $profile->forceFill([
            'telegram_link_hash' => self::hash($token),
            'telegram_link_expires_at' => now()->addHours(self::LINK_HOURS),
        ])->save();

        $start = self::PREFIX.$token;
        $bot = config('services.telegram.bot_username');

        return $bot ? "https://t.me/{$bot}?start={$start}" : $start;
    }

    /**
     * Called by the bot for "/start s_<token>". A Telegram account belongs to one supervisor at a time.
     */
    public function link(string $token, int $telegramUserId): ?SupervisorProfile
    {
        if (! preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            return null;
        }

        return DB::transaction(function () use ($token, $telegramUserId) {
            $profile = SupervisorProfile::query()
                ->where('telegram_link_hash', self::hash($token))
                ->where('telegram_link_expires_at', '>', now())
                ->with('user')
                ->lockForUpdate()
                ->first();
            if ($profile === null || $profile->user?->status !== ActiveStatus::Active) {
                return null;
            }

            SupervisorProfile::query()
                ->where('telegram_user_id', $telegramUserId)
                ->whereKeyNot($profile->id)
                ->update(['telegram_user_id' => null, 'telegram_linked_at' => null]);
            $before = ['telegram_linked' => $profile->telegram_user_id !== null];
            $profile->forceFill([
                'telegram_user_id' => $telegramUserId,
                'telegram_linked_at' => now(),
                'telegram_link_hash' => null,
                'telegram_link_expires_at' => null,
            ])->save();
            $this->audit->log($profile->user, 'supervisor.telegram_link', $profile, $before, ['telegram_linked' => true]);

            return $profile;
        });
    }

    public function unlink(User $actor, SupervisorProfile $profile): void
    {
        $this->authorize($actor, $profile);
        if ($profile->telegram_user_id === null && $profile->telegram_link_hash === null) {
            return;
        }

        DB::transaction(function () use ($actor, $profile) {
            $profile->forceFill([
                'telegram_user_id' => null,
                'telegram_linked_at' => null,
                'telegram_link_hash' => null,
                'telegram_link_expires_at' => null,
            ])->save();
            $this->audit->log($actor, 'supervisor.telegram_unlink', $profile, ['telegram_linked' => true], ['telegram_linked' => false]);
        });
    }

    public function setNotifyCheckEvents(User $actor, SupervisorProfile $profile, bool $enabled): void
    {
        $this->authorize($actor, $profile);
        $profile->forceFill(['notify_check_events' => $enabled])->save();
    }

    public function linkedProfile(int $telegramUserId): ?SupervisorProfile
    {
        return SupervisorProfile::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereHas('user', fn ($query) => $query->where('status', ActiveStatus::Active->value))
            ->with('user')
            ->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function authorize(User $actor, SupervisorProfile $profile): void
    {
        $self = $actor->id === $profile->user_id;
        $admin = $actor->isAdmin() && $actor->university_id === $profile->university_id;
        if (! ($self || $admin) || $actor->status !== ActiveStatus::Active) {
            throw new AuthorizationException;
        }
    }
}
