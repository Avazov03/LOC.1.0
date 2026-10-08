<?php

namespace App\Services\Attendance;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * §98 steps 6–11. The decision is ST_DWithin against the stored geography, never a comparison of a rounded distance
 * (ATTENDANCE-RULES §5). ST_Distance only provides the stored meter value.
 */
class LocationVerifier
{
    /**
     * @return array{distance: float, within: bool, organization_latitude: float, organization_longitude: float, radius: int}|null
     */
    public function measure(int $organizationId, float $latitude, float $longitude): ?array
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng, radius_meters,
                    ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance,
                    ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, radius_meters) AS within
                 FROM organizations WHERE id = ?',
                [$longitude, $latitude, $longitude, $latitude, $organizationId],
            );
            if ($row === null) {
                return null;
            }

            return [
                'distance' => round((float) $row->distance, 3),
                'within' => (bool) $row->within,
                'organization_latitude' => round((float) $row->lat, 7),
                'organization_longitude' => round((float) $row->lng, 7),
                'radius' => (int) $row->radius_meters,
            ];
        }

        // sqlite test schema only: geography is WKT text, so use a spherical approximation. Boundary tests run on PostGIS.
        $row = DB::table('organizations')->where('id', $organizationId)->first(['location', 'radius_meters']);
        $point = Geo::parse($row?->location);
        if ($point === null) {
            return null;
        }
        $distance = self::haversine($point['latitude'], $point['longitude'], $latitude, $longitude);

        return [
            'distance' => round($distance, 3),
            'within' => $distance <= (int) $row->radius_meters,
            'organization_latitude' => $point['latitude'],
            'organization_longitude' => $point['longitude'],
            'radius' => (int) $row->radius_meters,
        ];
    }

    public static function validCoordinates(?float $latitude, ?float $longitude): bool
    {
        return $latitude !== null && $longitude !== null
            && is_finite($latitude) && is_finite($longitude)
            && $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180;
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371008.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1.0, sqrt($a)));
    }
}
