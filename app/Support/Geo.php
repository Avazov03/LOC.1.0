<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * geography(Point, 4326) values. PostgreSQL stores real geography; the sqlite test schema stores WKT text.
 */
class Geo
{
    public static function point(float $latitude, float $longitude): Expression|string
    {
        $wkt = sprintf('POINT(%.7F %.7F)', $longitude, $latitude);

        if (DB::getDriverName() === 'pgsql') {
            return DB::raw("ST_GeogFromText('SRID=4326;{$wkt}')");
        }

        return $wkt;
    }

    public static function wktSelect(string $column = 'location', string $alias = 'location_wkt'): Expression
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::raw("ST_AsText({$column}) as {$alias}");
        }

        return DB::raw("{$column} as {$alias}");
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public static function parse(?string $wkt): ?array
    {
        if ($wkt === null || ! preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', $wkt, $match)) {
            return null;
        }

        return ['latitude' => round((float) $match[2], 7), 'longitude' => round((float) $match[1], 7)];
    }
}
