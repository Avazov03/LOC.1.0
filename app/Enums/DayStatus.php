<?php

namespace App\Enums;

/**
 * Computed per student and local date (D4, ATTENDANCE-RULES §8). SUSPICIOUS is never produced (A31).
 */
enum DayStatus: string
{
    case Present = 'PRESENT';
    case Partial = 'PARTIAL';
    case Incomplete = 'INCOMPLETE';
    case LocationRejected = 'LOCATION_REJECTED';
    case Absent = 'ABSENT';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Keldi',
            self::Partial => 'Qisman',
            self::Incomplete => 'Yakunlanmagan',
            self::LocationRejected => 'Joylashuv rad etildi',
            self::Absent => 'Kelmadi',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
