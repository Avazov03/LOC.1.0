<?php

namespace App\Enums;

/**
 * Computed per student and local date (D4, ATTENDANCE-RULES §8). SUSPICIOUS is never produced (A31).
 * There is no minimum duration: a completed day is PRESENT and the worked time is shown next to it.
 */
enum DayStatus: string
{
    case Present = 'PRESENT';
    case Incomplete = 'INCOMPLETE';
    case LocationRejected = 'LOCATION_REJECTED';
    case Excused = 'EXCUSED';
    case Absent = 'ABSENT';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Keldi',
            self::Incomplete => 'Yakunlanmagan',
            self::LocationRejected => 'Joylashuv rad etildi',
            self::Excused => 'Sababli',
            self::Absent => 'Kelmadi',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Present => '✅',
            self::Incomplete => '⏳',
            self::LocationRejected => '📍',
            self::Excused => '📝',
            self::Absent => '❌',
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
