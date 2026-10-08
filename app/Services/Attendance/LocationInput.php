<?php

namespace App\Services\Attendance;

/**
 * One location the student sent for this action (A28). The device clock is kept only as evidence metadata.
 */
final class LocationInput
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?float $accuracy = null,
        public readonly bool $forwarded = false,
        public readonly bool $live = false,
        public readonly ?int $deviceTimestamp = null,
    ) {}
}
