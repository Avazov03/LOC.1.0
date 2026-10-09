<?php

namespace App\Enums;

/**
 * A staff decision about a whole day: PRESENT counts the day as attended without check-in or check-out,
 * EXCUSED records a valid reason and keeps the day out of "Kelmadi".
 */
enum DayMarkKind: string
{
    case Present = 'PRESENT';
    case Excused = 'EXCUSED';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Keldi',
            self::Excused => 'Sababli',
        };
    }
}
