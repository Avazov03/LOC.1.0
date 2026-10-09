<?php

namespace App\Services\Attendance;

use App\Models\AttendanceEvent;

/**
 * Result of a student attendance action. Channel-agnostic: the bot turns the code into a sentence.
 */
final class AttendanceOutcome
{
    public const READY = 'READY';

    public const CHECKED_IN = 'CHECKED_IN';

    public const CHECKED_OUT = 'CHECKED_OUT';

    public const OUTSIDE_RADIUS = 'OUTSIDE_RADIUS';

    public const INVALID_LOCATION = 'INVALID_LOCATION';

    public const FORWARDED_LOCATION = 'FORWARDED_LOCATION';

    public const LOW_ACCURACY = 'LOW_ACCURACY';

    public const MAP_LOCATION = 'MAP_LOCATION';

    public const STALE_LOCATION = 'STALE_LOCATION';

    public const REUSED_LOCATION = 'REUSED_LOCATION';

    public const LOCATION_MISSING = 'LOCATION_MISSING';

    public const NO_ASSIGNMENT = 'NO_ASSIGNMENT';

    public const OUTSIDE_PERIOD = 'OUTSIDE_PERIOD';

    public const NOT_WORK_DAY = 'NOT_WORK_DAY';

    public const ORGANIZATION_INACTIVE = 'ORGANIZATION_INACTIVE';

    public const DUPLICATE_OPEN = 'DUPLICATE_OPEN';

    public const SECOND_SESSION_BLOCKED = 'SECOND_SESSION_BLOCKED';

    public const CHECK_IN_DISABLED = 'CHECK_IN_DISABLED';

    public const CHECK_OUT_DISABLED = 'CHECK_OUT_DISABLED';

    public const NO_OPEN_SESSION = 'NO_OPEN_SESSION';

    public const ACCESS_DENIED = 'ACCESS_DENIED';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $code,
        public readonly array $data = [],
        public readonly ?AttendanceEvent $event = null,
    ) {}

    public function ok(): bool
    {
        return in_array($this->code, [self::READY, self::CHECKED_IN, self::CHECKED_OUT], true);
    }

    /**
     * Failures where sending another location may help, so the bot keeps waiting for one.
     */
    public function retryable(): bool
    {
        return in_array($this->code, [
            self::OUTSIDE_RADIUS, self::INVALID_LOCATION, self::LOW_ACCURACY, self::FORWARDED_LOCATION, self::LOCATION_MISSING,
            self::MAP_LOCATION, self::STALE_LOCATION, self::REUSED_LOCATION,
        ], true);
    }
}
