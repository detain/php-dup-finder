<?php

namespace App\Services\Shipping;

final class TrackingConfig
{
    public readonly bool $validateChecksum;
    public readonly bool $autoDetectCarrier;

    public function __construct(bool $validateChecksum = true, bool $autoDetectCarrier = true)
    {
        $this->validateChecksum = $validateChecksum;
        $this->autoDetectCarrier = $autoDetectCarrier;
    }
}

final class TrackingService
{
    private TrackingConfig $config;

    private const CARRIERS = [
        'fedex' => ['pattern' => '/^\d{12,22}$/', 'url' => 'https://www.fedex.com/apps/fedextrack/?tracknumbers=%s'],
        'ups' => ['pattern' => '/^1Z[A-Z0-9]{16}$/', 'url' => 'https://www.ups.com/track?tracknum=%s'],
        'usps' => ['pattern' => '/^(\d{20,22}|[A-Z]{2}\d{9}[A-Z]{2})$/', 'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s'],
        'dhl' => ['pattern' => '/^(\d{10}|\d{14}|[A-Z]{3}\d{7})$/', 'url' => 'https://www.dhl.com/en/express/tracking.html?AWB=%s'],
    ];

    public function __construct(TrackingConfig $config)
    {
        $this->config = $config;
    }

    public function validate(string $tracking): array
    {
        $clean = trim($tracking);

        foreach (self::CARRIERS as $carrier => $info) {
            if (preg_match($info['pattern'], $clean)) {
                $valid = !$this->config->validateChecksum || $this->verifyChecksum($clean, $carrier);

                return [
                    'valid' => $valid,
                    'carrier' => ucfirst($carrier),
                    'tracking' => $clean,
                    'url' => sprintf($info['url'], urlencode($clean)),
                ];
            }
        }

        return ['valid' => false, 'carrier' => null, 'tracking' => $clean];
    }

    private function verifyChecksum(string $tracking, string $carrier): bool
    {
        return match ($carrier) {
            'fedex' => $this->fedexChecksum($tracking),
            'ups' => $this->upsChecksum($tracking),
            'dhl' => $this->dhlChecksum($tracking),
            default => true,
        };
    }

    private function fedexChecksum(string $tracking): bool
    {
        $sum = 0;

        for ($i = 0; $i < strlen($tracking) - 1; $i++) {
            $sum += (int) $tracking[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (int) $tracking[strlen($tracking) - 1] === (10 - ($sum % 10)) % 10;
    }

    private function upsChecksum(string $tracking): bool
    {
        $sum = 0;

        for ($i = 2; $i < 17; $i++) {
            $c = $tracking[$i];
            $val = is_numeric($c) ? (int) $c : ord($c) - 55;
            $sum += $val * ($i % 2 === 0 ? 1 : 2);
        }

        return (int) $tracking[17] === (10 - ($sum % 10)) % 10;
    }

    private function dhlChecksum(string $tracking): bool
    {
        if (strlen($tracking) === 10) {
            $sum = 0;

            for ($i = 0; $i < 9; $i++) {
                $sum += (int) $tracking[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            return (int) $tracking[9] === (10 - ($sum % 10)) % 10;
        }

        return true;
    }
}
