<?php
declare(strict_types=1);

namespace Acme\Geo;

final class Coordinate
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly float $altitudeMeters,
        public readonly string $datum,
    ) {
        if ($this->latitude < -90.0 || $this->latitude > 90.0) {
            throw new \InvalidArgumentException(
                sprintf('latitude %.4f out of range [-90, 90]', $this->latitude),
            );
        }

        if ($this->longitude < -180.0 || $this->longitude > 180.0) {
            throw new \InvalidArgumentException(
                sprintf('longitude %.4f out of range [-180, 180]', $this->longitude),
            );
        }

        if ($this->altitudeMeters < -500.0 || $this->altitudeMeters > 10000.0) {
            throw new \InvalidArgumentException(
                sprintf('altitude %.1fm out of range [-500, 10000]', $this->altitudeMeters),
            );
        }

        if ($this->datum === '') {
            throw new \InvalidArgumentException('datum must not be empty');
        }
    }

    public function asArray(): array
    {
        return [
            'lat'   => $this->latitude,
            'lon'   => $this->longitude,
            'alt'   => $this->altitudeMeters,
            'datum' => $this->datum,
        ];
    }
}
