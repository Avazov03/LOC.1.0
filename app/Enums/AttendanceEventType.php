<?php

namespace App\Enums;

/**
 * §39. LOCATION_REJECTED is a day status, not an event type (ATTENDANCE-RULES §3).
 */
enum AttendanceEventType: string
{
    case CheckIn = 'CHECK_IN';
    case CheckOut = 'CHECK_OUT';
    case FailedCheckIn = 'FAILED_CHECK_IN';
    case FailedCheckOut = 'FAILED_CHECK_OUT';
    case ManualCorrection = 'MANUAL_CORRECTION';
    case SystemAdjustment = 'SYSTEM_ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Kelish',
            self::CheckOut => 'Ketish',
            self::FailedCheckIn => 'Rad etilgan kelish',
            self::FailedCheckOut => 'Rad etilgan ketish',
            self::ManualCorrection => 'Qo‘lda tuzatish',
            self::SystemAdjustment => 'Tizim yopishi',
        };
    }
}
