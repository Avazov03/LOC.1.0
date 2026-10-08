<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Ended = 'ENDED';
    case Cancelled = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Active->value];
    }
}
