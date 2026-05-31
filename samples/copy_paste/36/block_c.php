<?php

declare(strict_types=1);

namespace App\Geo;

final class CoordinateProcessor
{
    public function latitudeOk(float $lat): bool
    {
        return $lat >= -90.0 && $lat <= 90.0;
    }

    public function longitudeOk(float $lon): bool
    {
        return $lon >= -180.0 && $lon <= 180.0;
    }

    public function assertLatitude(float $lat): float
    {
        if (!$this->latitudeOk($lat)) {
            throw new \InvalidArgumentException("Latitude invalid: {$lat}");
        }

        return $lat;
    }

    public function assertLongitude(float $lon): float
    {
        if (!$this->longitudeOk($lon)) {
            throw new \InvalidArgumentException("Longitude invalid: {$lon}");
        }

        return $lon;
    }

    public function assertPair(float $lat, float $lon): array
    {
        return [
            'latitude' => $this->assertLatitude($lat),
            'longitude' => $this->assertLongitude($lon),
        ];
    }

    public function insideZone(float $lat, float $lon, array $zone): bool
    {
        return $lat >= $zone['min_latitude'] && $lat <= $zone['max_latitude']
            && $lon >= $zone['min_longitude'] && $lon <= $zone['max_longitude'];
    }

    public function correctDecimalPlaces(float $lat, float $lon, int $maxDp): bool
    {
        $latStr = (string) $lat;
        $lonStr = (string) $lon;

        $latParts = explode('.', $latStr);
        $lonParts = explode('.', $lonStr);

        $latDec = isset($latParts[1]) ? strlen($latParts[1]) : 0;
        $lonDec = isset($lonParts[1]) ? strlen($lonParts[1]) : 0;

        return $latDec <= $maxDp && $lonDec <= $maxDp;
    }

    public function snapToGrid(float $lat, float $lon, int $precision): array
    {
        $scale = pow(10, $precision);

        return [
            'lat' => round($lat * $scale) / $scale,
            'lon' => round($lon * $scale) / $scale,
        ];
    }

    public function degToRad(float $degrees): float
    {
        return $degrees * pi() / 180.0;
    }

    public function radToDeg(float $radians): float
    {
        return $radians * 180.0 / pi();
    }

    public function vincentyDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = 6378137.0;
        $f = 1 / 298.257223563;

        $lat1Rad = $this->degToRad($lat1);
        $lat2Rad = $this->degToRad($lat2);
        $lon1Rad = $this->degToRad($lon1);
        $lon2Rad = $this->degToRad($lon2);

        $b = $a * (1 - $f);
        $p = $a * (1 - $f);

        $L = $lon2Rad - $lon1Rad;

        $tanU1 = (1 - $f) * tan($lat1Rad);
        $tanU2 = (1 - $f) * tan($lat2Rad);

        $cosU1 = 1 / sqrt(1 + $tanU1 * $tanU1);
        $sinU1 = $tanU1 * $cosU1;
        $cosU2 = 1 / sqrt(1 + $tanU2 * $tanU2);
        $sinU2 = $tanU2 * $cosU2;

        $l = $L;

        for ($i = 0; $i < 100; $i++) {
            $sinL = sin($l);
            $cosL = cos($l);
            $sinSigma = sqrt(
                ($cosU2 * $sinL) ** 2
                + ($cosU1 * $sinU2 - $sinU1 * $cosU2 * $cosL) ** 2
            );

            if ($sinSigma == 0) {
                return 0.0;
            }

            $cosSigma = $sinU1 * $sinU2 + $cosU1 * $cosU2 * $cosL;
            $sigma = atan2($sinSigma, $cosSigma);

            $sinAlpha = $cosU1 * $cosU2 * $sinL / $sinSigma;
            $cosSqAlpha = 1 - $sinAlpha ** 2;

            if ($cosSqAlpha == 0) {
                $cos2SigmaM = 0;
            } else {
                $cos2SigmaM = $cosSigma - 2 * $sinU1 * $sinU2 / $cosSqAlpha;
            }

            $C = $f / 16 * $cosSqAlpha * (4 + $f * (4 - 3 * $cosSqAlpha));

            $lNext = $L + (1 - $C) * $f * $sinAlpha
                * ($sigma + $C * $sinSigma * ($cos2SigmaM + $C * $cosSigma * (-1 + 2 * $cos2SigmaM ** 2)));

            if (abs($lNext - $l) < 1e-12) {
                break;
            }

            $l = $lNext;
        }

        $uSq = $cosSqAlpha * ($a * $a - $b * $b) / ($b * $b);
        $A = 1 + $uSq / 16384 * (4096 + $uSq * (-768 + $uSq * (320 - 175 * $uSq)));
        $B = $uSq / 1024 * (256 + $uSq * (-128 + $uSq * (74 - 47 * $uSq)));
        $deltaSigma = $B * $sinSigma * ($cos2SigmaM + $B / 4 * ($cosSigma * (-1 + 2 * $cos2SigmaM ** 2)
            - $B / 6 * $cos2SigmaM * (-3 + 4 * $sinSigma ** 2) * (-3 + 4 * $cos2SigmaM ** 2)));

        return $b * $A * ($sigma - $deltaSigma);
    }

    public function compassBearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $φ1 = $this->degToRad($lat1);
        $φ2 = $this->degToRad($lat2);
        $Δλ = $this->degToRad($lon2 - $lon1);

        $y = sin($Δλ) * cos($φ2);
        $x = cos($φ1) * sin($φ2) - sin($φ1) * cos($φ2) * cos($Δλ);

        $θ = atan2($y, $x);

        return fmod(($this->radToDeg($θ) + 360.0), 360.0);
    }

    public function isValidCompassPoint(string $point): bool
    {
        $allowed = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW'];

        return in_array(strtoupper($point), $allowed, true);
    }

    public function resolveCompassDegrees(string $compassPoint): float
    {
        $map = [
            'N' => 0.0,
            'NE' => 45.0,
            'E' => 90.0,
            'SE' => 135.0,
            'S' => 180.0,
            'SW' => 225.0,
            'W' => 270.0,
            'NW' => 315.0,
        ];

        $normalized = strtoupper(trim($compassPoint));

        if (!isset($map[$normalized])) {
            throw new \InvalidArgumentException("Invalid compass point: {$compassPoint}");
        }

        return $map[$normalized];
    }
}
