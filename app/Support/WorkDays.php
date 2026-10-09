<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Internship work days as an ISO weekday bitmask: bit 0 = Monday … bit 6 = Sunday.
 * "Toq kunlar" are Monday, Wednesday, Friday; "juft kunlar" are Tuesday, Thursday, Saturday.
 */
final class WorkDays
{
    public const ALL = 127;

    public const WEEKDAYS = 31;

    public const MON_SAT = 63;

    public const ODD = 21;

    public const EVEN = 42;

    public const NAMES = [1 => 'Du', 2 => 'Se', 3 => 'Chor', 4 => 'Pay', 5 => 'Ju', 6 => 'Sha', 7 => 'Yak'];

    public static function bit(int $isoDay): int
    {
        return 1 << ($isoDay - 1);
    }

    public static function includes(int $mask, string $date): bool
    {
        return ($mask & self::bit(CarbonImmutable::parse($date)->dayOfWeekIso)) !== 0;
    }

    /**
     * @param  iterable<int>  $isoDays
     */
    public static function fromDays(iterable $isoDays): int
    {
        $mask = 0;
        foreach ($isoDays as $day) {
            $mask |= self::bit((int) $day);
        }

        return $mask;
    }

    /**
     * @return list<int>
     */
    public static function toDays(int $mask): array
    {
        return array_values(array_filter(range(1, 7), fn (int $day) => ($mask & self::bit($day)) !== 0));
    }

    public static function label(int $mask): string
    {
        $days = implode(', ', array_map(fn (int $day) => self::NAMES[$day], self::toDays($mask)));

        return match ($mask) {
            self::ALL => 'Har kuni',
            self::WEEKDAYS => 'Du–Ju',
            self::MON_SAT => 'Du–Sha',
            self::ODD => "Toq kunlar ({$days})",
            self::EVEN => "Juft kunlar ({$days})",
            default => $days,
        };
    }

    public static function valid(int $mask): bool
    {
        return $mask >= 1 && $mask <= self::ALL;
    }
}
