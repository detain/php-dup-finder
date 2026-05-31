<?php

declare(strict_types=1);

namespace App\Location;

final class LatLonChecker
{
    public function checkLatitude(float $lat): bool
    {
        return $lat >= -90.0 && $lat <= 90.0;
    }

    public function checkLongitude(float $lon): bool
    {
        return $lon >= -180.0 && $lon <= 180.0;
    }

    public function verifyLatitude(float $lat): float
    {
        if (!$this->checkLatitude($lat)) {
            throw new \InvalidArgumentException("Latitude out of range: {$lat}");
        }

        return $lat;
    }

    public function verifyLongitude(float $lon): float
    {
        if (!$this->checkLongitude($lon)) {
            throw new \InvalidArgumentException("Longitude out of range: {$lon}");
        }

        return $lon;
    }

    public function verifyPair(float $lat, float $lon): array
    {
        return [
            'lat' => $this->verifyLatitude($lat),
            'lon' => $this->verifyLongitude($lon),
        ];
    }

    public function withinArea(float $lat, float $lon, array $area): bool
    {
        $minLat = $area['min_lat'];
        $maxLat = $area['max_lat'];
        $minLon = $area['min_lon'];
        $maxLon = $area['max_lon'];

        return $lat >= $minLat && $lat <= $maxLat
            && $lon >= $minLon && $lon <= $maxLon;
    }

    public function precisionOk(float $lat, float $lon, int $dp): bool
    {
        $latStr = (string) $lat;
        $lonStr = (string) $lon;

        $latDecimals = strlen(explode('.', $latStr)[1] ?? '');
        $lonDecimals = strlen(explode('.', $lonStr)[1] ?? '');

        return $latDecimals <= $dp && $lonDecimals <= $dp;
    }

    public function quantize(float $lat, float $lon, int $places): array
    {
        $factor = pow(10, $places);

        return [
            'lat' => round($lat * $factor) / $factor,
            'lon' => round($lon * $factor) / $factor,
        ];
    }

    public function toRad(float $deg): float
    {
        return $deg * pi() / 180.0;
    }

    public function toDeg(float $rad): float
    {
        return $rad * 180.0 / pi();
    }

    public function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radius = 6371.0;

        $lat1Rad = $this->toRad($lat1);
        $lat2Rad = $this->toRad($lat2);
        $dLat = $this->toRad($lat2 - $lat1);
        $dLon = $this->toRad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($dLon / 2) ** 2;

        $c = 2 * asin(sqrt($a));

        return $radius * $c;
    }

    public function initialBearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = $this->toRad($lat1);
        $lat2Rad = $this->toRad($lat2);
        $dLon = $this->toRad($lon2 - $lon1);

        $x = sin($dLon) * cos($lat2Rad);
        $y = cos($lat1Rad) * sin($lat2Rad)
            - sin($lat1Rad) * cos($lat2Rad) * cos($dLon);

        $bearingRad = atan2($x, $y);

        return fmod(($this->toDeg($bearingRad) + 360.0), 360.0);
    }

    public function validCompassDirection(string $dir): bool
    {
        $valid = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW', 'NORTH', 'SOUTH', 'EAST', 'WEST'];

        return in_array(strtoupper($dir), $valid, true);
    }

    public function compassToDegrees(string $direction): float
    {
        $map = [
            'N' => 0.0, 'NORTH' => 0.0,
            'NE' => 45.0, 'NORTH-EAST' => 45.0,
            'E' => 90.0, 'EAST' => 90.0,
            'SE' => 135.0, 'SOUTH-EAST' => 135.0,
            'S' => 180.0, 'SOUTH' => 180.0,
            'SW' => 225.0, 'SOUTH-WEST' => 225.0,
            'W' => 270.0, 'WEST' => 270.0,
            'NW' => 315.0, 'NORTH-WEST' => 315.0,
        ];

        $key = strtoupper(str_replace([' ', '-'], '', $direction));

        if (!isset($map[$key])) {
            throw new \InvalidArgumentException("Unknown direction: {$direction}");
        }

        return $map[$key];
    }
}
