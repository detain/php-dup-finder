<?php
declare(strict_types=1);

namespace Acme\Telemetry;

final class SensorReadingClassifier
{
    public function __construct(private AlertChannel $alerts) {}

    public function classify(float $celsius, string $deviceId): ClassifiedReading
    {
        $severity = match (true) {
            $celsius >= 95.0   => 'critical',
            $celsius >= 80.0   => 'high',
            $celsius >= 60.0   => 'elevated',
            $celsius >= 30.0   => 'nominal',
            $celsius >= 0.0    => 'cool',
            $celsius >= -20.0  => 'cold',
            default            => 'freezing',
        };

        $page = match ($severity) {
            'critical', 'high' => true,
            'elevated', 'cool' => false,
            default            => false,
        };

        $this->alerts->record('sensor.severity.' . $severity, ['device' => $deviceId]);

        return new ClassifiedReading(
            celsius:  $celsius,
            severity: $severity,
            page:     $page,
            device:   $deviceId,
        );
    }
}
