<?php

declare(strict_types=1);

namespace App\Logistics;

final class DeliveryNumberProcessor
{
    public function confirmFedEx(string $id): bool
    {
        $normalized = $this->normalize($id);

        return (bool) preg_match('/^\d{12,22}$/', $normalized);
    }

    public function confirmUps(string $id): bool
    {
        $normalized = $this->normalize($id);

        return (bool) preg_match('/^1Z[A-Z0-9]{16}$/', $normalized);
    }

    public function confirmUsps(string $id): bool
    {
        $normalized = $this->normalize($id);

        if (preg_match('/^\d{20,22}$/', $normalized)) {
            return true;
        }

        return (bool) preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $normalized);
    }

    public function confirmDhl(string $id): bool
    {
        $normalized = $this->normalize($id);

        return (bool) preg_match('/^\d{10}$/', $normalized)
            || (bool) preg_match('/^\d{14}$/', $normalized)
            || (bool) preg_match('/^[A-Z]{3}\d{7}$/', $normalized);
    }

    public function isKnown(string $id): bool
    {
        $normalized = $this->normalize($id);

        return $this->confirmFedEx($normalized)
            || $this->confirmUps($normalized)
            || $this->confirmUsps($normalized)
            || $this->confirmDhl($normalized);
    }

    public function resolveCarrier(string $id): ?string
    {
        $normalized = $this->normalize($id);

        if ($this->confirmFedEx($normalized)) {
            return 'FedEx';
        }

        if ($this->confirmUps($normalized)) {
            return 'UPS';
        }

        if ($this->confirmUsps($normalized)) {
            return 'USPS';
        }

        if ($this->confirmDhl($normalized)) {
            return 'DHL';
        }

        return null;
    }

    public function createTrackingUrl(string $tracking, string $carrier): string
    {
        $normalized = $this->normalize($tracking);
        $lowercaseCarrier = strtolower($carrier);

        return match ($lowercaseCarrier) {
            'fedex' => "https://www.fedex.com/apps/fedextrack/?tracknumbers={$normalized}",
            'ups' => "https://www.ups.com/track?tracknum={$normalized}",
            'usps' => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$normalized}",
            'dhl' => "https://www.dhl.com/en/express/tracking.html?AWB={$normalized}",
            default => throw new \InvalidArgumentException("Unknown carrier: {$carrier}"),
        };
    }

    public function extractTrackingInfo(string $tracking): array
    {
        $normalized = $this->normalize($tracking);
        $carrier = $this->resolveCarrier($normalized);

        if ($carrier === null) {
            throw new \InvalidArgumentException('Unknown tracking number format');
        }

        return [
            'tracking' => $normalized,
            'carrier' => $carrier,
            'url' => $this->createTrackingUrl($normalized, $carrier),
            'display' => $this->prettify($normalized, $carrier),
        ];
    }

    public function formatForDisplay(string $tracking, string $carrier): string
    {
        $normalized = $this->normalize($tracking);
        $key = strtolower($carrier);

        if ($key === 'ups') {
            return $this->groupUps($normalized);
        }

        if ($key === 'usps' && preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $normalized)) {
            return substr($normalized, 0, 2) . ' ' . substr($normalized, 2, 9) . ' ' . substr($normalized, 11, 2);
        }

        if ($key === 'fedex') {
            return $this->groupFedEx($normalized);
        }

        return $normalized;
    }

    public function authenticate(string $tracking, string $carrier): bool
    {
        $normalized = $this->normalize($tracking);

        return match (strtolower($carrier)) {
            'fedex' => $this->authFedEx($normalized),
            'ups' => $this->authUps($normalized),
            'dhl' => $this->authDhl($normalized),
            default => false,
        };
    }

    private function authFedEx(string $id): bool
    {
        if (!preg_match('/^\d{12,22}$/', $id)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < strlen($id) - 1; $i++) {
            $sum += (int) $id[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $digit = (10 - ($sum % 10)) % 10;

        return (int) $id[strlen($id) - 1] === $digit;
    }

    private function authUps(string $id): bool
    {
        if (!preg_match('/^1Z[A-Z0-9]{16}$/', $id)) {
            return false;
        }

        $sum = 0;

        for ($i = 2; $i < 17; $i++) {
            $c = $id[$i];
            $v = is_numeric($c) ? (int) $c : ord($c) - 55;

            $sum += $v * ($i % 2 === 0 ? 1 : 2);
        }

        $check = (10 - ($sum % 10)) % 10;

        return (int) $id[17] === $check;
    }

    private function authDhl(string $id): bool
    {
        if (strlen($id) === 10) {
            $sum = 0;

            for ($i = 0; $i < 9; $i++) {
                $sum += (int) $id[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            $computed = (10 - ($sum % 10)) % 10;

            return (int) $id[9] === $computed;
        }

        if (strlen($id) === 14) {
            $sum = 0;

            for ($i = 0; $i < 13; $i++) {
                $sum += (int) $id[$i];
            }

            $calculated = (10 - ($sum % 10)) % 10;

            return (int) $id[13] === $calculated;
        }

        return false;
    }

    private function groupUps(string $tracking): string
    {
        return substr($tracking, 0, 3) . ' ' . substr($tracking, 3, 3) . ' '
            . substr($tracking, 6, 3) . ' ' . substr($tracking, 9, 3) . ' '
            . substr($tracking, 12, 3);
    }

    private function groupFedEx(string $tracking): string
    {
        $len = strlen($tracking);

        if ($len === 15) {
            return substr($tracking, 0, 4) . ' ' . substr($tracking, 4, 3) . ' '
                . substr($tracking, 7, 3) . ' ' . substr($tracking, 10, 3) . ' '
                . substr($tracking, 13, 2);
        }

        return $tracking;
    }

    private function normalize(string $tracking): string
    {
        return trim($tracking);
    }
}
