<?php

namespace App\Domain\Marketplace\Support;

final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0088;

    /** Great-circle distance in km. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1, sqrt($a)));
    }

    /** "850 m", "2.4 km", "38 km" */
    public static function format(float $km): string
    {
        return match (true) {
            $km < 1 => (int) (round($km * 20) * 50).' m',
            $km < 10 => number_format($km, 1).' km',
            default => (int) round($km).' km',
        };
    }
}
