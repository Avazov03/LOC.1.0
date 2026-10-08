<?php

namespace App\Enums;

/**
 * §99 verification results. NOT_APPLICABLE marks SYSTEM_ADJUSTMENT rows, which verify nothing (A68).
 * DUPLICATE_ACTION is a reply only and is never stored (A27).
 */
enum VerificationStatus: string
{
    case Verified = 'VERIFIED';
    case OutsideRadius = 'OUTSIDE_RADIUS';
    case InvalidLocation = 'INVALID_LOCATION';
    case LowAccuracy = 'LOW_ACCURACY';
    case NoAssignment = 'NO_ASSIGNMENT';
    case OutsideInternshipPeriod = 'OUTSIDE_INTERNSHIP_PERIOD';
    case NotApplicable = 'NOT_APPLICABLE';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Tasdiqlangan',
            self::OutsideRadius => 'Radiusdan tashqarida',
            self::InvalidLocation => 'Noto‘g‘ri joylashuv',
            self::LowAccuracy => 'Aniqlik past',
            self::NoAssignment => 'Biriktirish yo‘q',
            self::OutsideInternshipPeriod => 'Amaliyot muddatidan tashqarida',
            self::NotApplicable => '—',
        };
    }
}
