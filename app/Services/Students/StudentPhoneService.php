<?php

namespace App\Services\Students;

use App\Enums\StudentStatus;
use App\Models\StudentProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\SupervisorNotifier;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * A student's phone as proven by Telegram's "share my number" button (the contact's user_id is the sender).
 * Telegram allows one account per number, so a verified number shared from a new account means the student
 * lost or replaced the old account: the profile moves to the new one without staff (A85).
 */
class StudentPhoneService
{
    public const RECOVERED = 'RECOVERED';

    /** No verified ACTIVE student has this number (unknown, typed by staff, or never confirmed). */
    public const NOT_FOUND = 'NOT_FOUND';

    /** This Telegram account already belongs to another student. */
    public const ACCOUNT_TAKEN = 'ACCOUNT_TAKEN';

    public const CONFIRMED = 'CONFIRMED';

    /** Another student of the same university already has this number. */
    public const PHONE_TAKEN = 'PHONE_TAKEN';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupervisorNotifier $supervisors,
    ) {}

    /**
     * @return array{code: string, student?: StudentProfile, previous_telegram_user_id?: int|null}
     */
    public function recover(int $telegramUserId, string $phone): array
    {
        $normalized = Phone::normalize($phone);
        if ($normalized === null) {
            return ['code' => self::NOT_FOUND];
        }

        return DB::transaction(function () use ($telegramUserId, $normalized) {
            $matches = StudentProfile::query()
                ->where('phone', $normalized)
                ->whereNotNull('phone_verified_at')
                ->where('status', StudentStatus::Active->value)
                ->with('user')
                ->lockForUpdate()
                ->get();
            // Two verified profiles with one number cannot be told apart safely; staff decide.
            if ($matches->count() !== 1) {
                return ['code' => self::NOT_FOUND];
            }
            $student = $matches->first();
            if (StudentProfile::query()->where('telegram_user_id', $telegramUserId)->whereKeyNot($student->id)->exists()) {
                return ['code' => self::ACCOUNT_TAKEN];
            }

            $previous = $student->telegram_user_id !== null ? (int) $student->telegram_user_id : null;
            if ($previous === $telegramUserId) {
                return ['code' => self::RECOVERED, 'student' => $student, 'previous_telegram_user_id' => $previous];
            }

            $student->forceFill([
                'telegram_user_id' => $telegramUserId,
                'telegram_rebind_hash' => null,
                'telegram_rebind_expires_at' => null,
                'phone_verified_at' => now(),
            ])->save();
            $this->audit->log(
                $student->user,
                'student.telegram_recover',
                $student,
                ['telegram_user_id' => $previous === null ? null : (string) $previous],
                ['telegram_user_id' => (string) $telegramUserId],
                metadata: ['method' => 'verified_phone'],
            );
            $this->supervisors->studentRecovered($student);

            return ['code' => self::RECOVERED, 'student' => $student, 'previous_telegram_user_id' => $previous];
        });
    }

    /**
     * The registered student shares their own number: store it as verified (it may be a new number).
     */
    public function confirm(StudentProfile $student, string $phone): string
    {
        $normalized = Phone::normalize($phone);
        if ($normalized === null) {
            return self::PHONE_TAKEN;
        }
        $digits = Phone::digits($normalized);

        return DB::transaction(function () use ($student, $normalized, $digits) {
            $taken = StudentProfile::query()
                ->where('university_id', $student->university_id)
                ->whereKeyNot($student->id)
                ->whereNotNull('phone')
                ->pluck('phone')
                ->contains(fn (string $existing) => Phone::digits($existing) === $digits);
            if ($taken) {
                return self::PHONE_TAKEN;
            }

            $student = StudentProfile::query()->lockForUpdate()->findOrFail($student->id);
            $before = $student->phone;
            $student->forceFill(['phone' => $normalized, 'phone_verified_at' => now()])->save();
            if (Phone::digits((string) $before) !== $digits) {
                $this->audit->log($student->user, 'student.phone_change', $student, ['phone' => $before], ['phone' => $normalized], metadata: ['method' => 'telegram_contact']);
            }

            return self::CONFIRMED;
        });
    }
}
