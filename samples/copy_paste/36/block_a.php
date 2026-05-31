<?php

declare(strict_types=1);

namespace App\Mapping;

final class GeoCoordinateValidator
{
    public function isValidLatitude(float $lat): bool
    {
        return $lat >= -90.0 && $lat <= 90.0;
    }

    public function isValidLongitude(float $lon): bool
    {
        return $lon >= -180.0 && $lon <= 180.0;
    }

    public function validateLatitude(float $lat): float
    {
        if (!$this->isValidLatitude($lat)) {
            throw new \InvalidArgumentException("Invalid latitude: {$lat}");
        }

        return $lat;
    }

    public function validateLongitude(float $lon): float
    {
        if (!$this->isValidLongitude($lon)) {
            throw new \InvalidArgumentException("Invalid longitude: {$lon}");
        }

        return $lon;
    }

    public function validateCoordinates(float $lat, float $lon): array
    {
        return [
            'latitude' => $this->validateLatitude($lat),
            'longitude' => $this->validateLongitude($lon),
        ];
    }

    public function isWithinBounds(float $lat, float $lon, array $bounds): bool
    {
        $latMin = $bounds['south'];
        $latMax = $bounds['north'];
        $lonMin = $bounds['west'];
        $lonMax = $bounds['east'];

        return $lat >= $latMin && $lat <= $latMax
            && $lon >= $lonMin && $lon <= $lonMax;
    }

    public function isValidPrecision(float $lat, float $lon, int $decimals): bool
    {
        $latStr = (string) $lat;
        $lonStr = (string) $lon;

        $latParts = explode('.', $latStr);
        $lonParts = explode('.', $lonStr);

        $latPrecision = isset($latParts[1]) ? strlen($latParts[1]) : 0;
        $lonPrecision = isset($lonParts[1]) ? strlen($lonParts[1]) : 0;

        return $latPrecision <= $decimals && $lonPrecision <= $decimals;
    }

    public function roundToPrecision(float $lat, float $lon, int $decimals): array
    {
        $multiplier = pow(10, $decimals);

        return [
            'latitude' => round($lat * $multiplier) / $multiplier,
            'longitude' => round($lon * $multiplier) / $multiplier,
        ];
    }

    public function toRadians(float $degrees): float
    {
        return $degrees * M_PI / 180.0;
    }

    public function toDegrees(float $radians): float
    {
        return $radians * 180.0 / M_PI;
    }

    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;

        $lat1Rad = $this->toRadians($lat1);
        $lat2Rad = $this->toRadians($lat2);
        $deltaLat = $this->toRadians($lat2 - $lat1);
        $deltaLon = $this->toRadians($lon2 - $lon1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
            + cos($lat1Rad) * cos($lat2Rad)
            * sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function calculateBearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = $this->toRadians($lat1);
        $lat2Rad = $this->toRadians($lat2);
        $deltaLon = $this->toRadians($lon2 - $lon1);

        $x = sin($deltaLon) * cos($lat2Rad);
        $y = cos($lat1Rad) * sin($lat2Rad)
            - sin($lat1Rad) * cos($lat2Rad) * cos($deltaLon);

        $bearing = atan2($x, $y);

        return fmod(($this->toDegrees($bearing) + 360.0), 360.0);
    }

    public function isCardinalDirection(string $direction): bool
    {
        $valid = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW'];

        return in_array(strtoupper($direction), $valid, true);
    }

    public function parseCardinalDirection(string $direction): float
    {
        $directions = [
            'N' => 0.0,
            'NE' => 45.0,
            'E' => 90.0,
            'SE' => 135.0,
            'S' => 180.0,
            'SW' => 225.0,
            'W' => 270.0,
            'NW' => 315.0,
        ];

        $key = strtoupper(trim($direction));

        if (!isset($directions[$key])) {
            throw new \InvalidArgumentException("Invalid direction: {$direction}");
        }

        return $directions[$key];
    }
}
