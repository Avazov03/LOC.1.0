<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Optional TOTP second factor for staff (A86). Recovery codes are stored as hashes inside the encrypted column and
 * each works once. A code that was just accepted is refused for the rest of its time step, so it cannot be replayed.
 */
class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Starts (or restarts) set-up: a fresh secret that does nothing until confirm() sees a valid code.
     */
    public function begin(User $user): void
    {
        if ($user->hasTwoFactor()) {
            throw new BusinessRuleException('Ikki bosqichli himoya allaqachon yoqilgan.');
        }
        $user->forceFill(['two_factor_secret' => Totp::secret(), 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    public function cancel(User $user): void
    {
        if (! $user->hasTwoFactor()) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null])->save();
        }
    }

    /**
     * @return list<string> the recovery codes, shown once
     */
    public function confirm(User $user, string $code): array
    {
        if ($user->hasTwoFactor() || $user->two_factor_secret === null) {
            throw new BusinessRuleException('Avval «Yoqish» tugmasini bosing.');
        }
        if (! $this->acceptTotp($user, $code)) {
            throw new BusinessRuleException('Kod noto‘g‘ri. Ilovadagi hozirgi 6 xonali kodni kiriting.');
        }

        return DB::transaction(function () use ($user) {
            $codes = $this->newCodes($user);
            $user->forceFill(['two_factor_confirmed_at' => now()])->save();
            $this->audit->log($user, 'user.two_factor_enable', $user);

            return $codes;
        });
    }

    /**
     * @return list<string>
     */
    public function regenerateCodes(User $user): array
    {
        if (! $user->hasTwoFactor()) {
            throw new BusinessRuleException('Ikki bosqichli himoya yoqilmagan.');
        }

        return DB::transaction(function () use ($user) {
            $codes = $this->newCodes($user);
            $this->audit->log($user, 'user.two_factor_recovery_codes', $user);

            return $codes;
        });
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->clear($user);
            $this->audit->log($user, 'user.two_factor_disable', $user);
        });
    }

    /**
     * Staff who lost the phone and the codes: an admin turns it off, the user sets it up again.
     */
    public function reset(?User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user) {
            $wasEnabled = $user->hasTwoFactor();
            $this->clear($user);
            $this->audit->log($actor, 'user.two_factor_reset', $user, metadata: ['was_enabled' => $wasEnabled], universityId: $user->university_id);
        });
    }

    /**
     * Login check: a current TOTP code, or one unused recovery code (consumed). Returns the method used or null.
     */
    public function verify(User $user, string $code): ?string
    {
        if (! $user->hasTwoFactor()) {
            return null;
        }
        if ($this->acceptTotp($user, $code)) {
            return 'totp';
        }

        return $this->useRecoveryCode($user, $code) ? 'recovery_code' : null;
    }

    public function remainingCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private function acceptTotp(User $user, string $code): bool
    {
        $step = Totp::match((string) $user->two_factor_secret, $code);

        // add() is atomic: a second request with the same code in the same step loses.
        return $step !== null && Cache::add("2fa-used:{$user->id}:{$step}", true, Totp::PERIOD * 3);
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }

        return DB::transaction(function () use ($user, $normalized) {
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            $hashes = $fresh->two_factor_recovery_codes ?? [];
            foreach ($hashes as $index => $hash) {
                if (Hash::check($normalized, $hash)) {
                    unset($hashes[$index]);
                    $fresh->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    $user->setRawAttributes($fresh->getAttributes(), true);
                    $this->audit->log($fresh, 'user.two_factor_recovery_used', $fresh, metadata: ['remaining' => count($hashes)]);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return list<string>
     */
    private function newCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = strtoupper(Str::random(5)).'-'.strtoupper(Str::random(5));
        }
        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make(str_replace('-', '', $code)), $codes),
        ])->save();

        return $codes;
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }
}
