<?php

namespace App\Services\Geo;

final class CoordinateConfig
{
    public readonly bool $allowNegative;
    public readonly bool $allowZero;
    public readonly int $maxPrecision;

    public function __construct(bool $allowNegative = true, bool $allowZero = true, int $maxPrecision = 8)
    {
        $this->allowNegative = $allowNegative;
        $this->allowZero = $allowZero;
        $this->maxPrecision = $maxPrecision;
    }
}

final class CoordinateService
{
    private CoordinateConfig $config;

    public function __construct(CoordinateConfig $config)
    {
        $this->config = $config;
    }

    public function validate(float $latitude, float $longitude): array
    {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new \InvalidArgumentException("Latitude out of range: {$latitude}");
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new \InvalidArgumentException("Longitude out of range: {$longitude}");
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
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

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }

    public function isWithinBounds(float $lat, float $lon, array $bounds): bool
    {
        return $lat >= $bounds['south'] && $lat <= $bounds['north']
            && $lon >= $bounds['west'] && $lon <= $bounds['east'];
    }
}
