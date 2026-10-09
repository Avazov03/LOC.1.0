<?php

namespace Tests\Concerns;

/**
 * Real GPS fixes never repeat to 1e-7°, and the attendance service refuses an exact repeat from another day
 * or another student. Each call shifts the latitude by one more 1e-7° (about 1 cm); the first call is exact.
 */
trait FreshCoordinates
{
    private int $coordinateSequence = 0;

    protected function freshLatitude(float $latitude): float
    {
        return round($latitude + ($this->coordinateSequence++) * 1e-7, 7);
    }
}
