<?php
declare(strict_types=1);

namespace Acme\Iot\Telemetry;

use Acme\Iot\Domain\Device;

final class SensorReadingAggregator
{
    public function __construct(
        private readonly float $threshold = 500.0,
    ) {
    }

    /**
     * @param iterable<Device> $devices
     */
    public function accumulate(iterable $devices): float
    {
        $running = 0.0;
        // same nested-loop accumulator + conditional break shape
        foreach ($devices as $device) {
            foreach ($device->readings() as $reading) {
                $running += $reading->celsius();
                if ($running > $this->threshold) {
                    break;
                }
            }
        }
        return $running;
    }

    public function isExceeded(iterable $devices): bool
    {
        return $this->accumulate($devices) > $this->threshold;
    }

    public function report(iterable $devices): array
    {
        return [
            'sum' => $this->accumulate($devices),
            'threshold' => $this->threshold,
        ];
    }
}
