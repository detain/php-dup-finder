<?php

declare(strict_types=1);

namespace App\Shipping;

final class TrackingNumberValidator
{
    public function isValidFedEx(string $tracking): bool
    {
        $clean = $this->sanitize($tracking);

        return (bool) preg_match('/^\d{12,22}$/', $clean);
    }

    public function isValidUps(string $tracking): bool
    {
        $clean = $this->sanitize($tracking);

        return (bool) preg_match('/^1Z[A-Z0-9]{16}$/', $clean);
    }

    public function isValidUsps(string $tracking): bool
    {
        $clean = $this->sanitize($tracking);

        if (preg_match('/^\d{20,22}$/', $clean)) {
            return true;
        }

        if (preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $clean)) {
            return true;
        }

        return false;
    }

    public function isValidDhl(string $tracking): bool
    {
        $clean = $this->sanitize($tracking);

        return (bool) preg_match('/^\d{10}$/', $clean)
            || (bool) preg_match('/^\d{14}$/', $clean)
            || (bool) preg_match('/^[A-Z]{3}\d{7}$/', $clean);
    }

    public function isValidTrackingNumber(string $tracking): bool
    {
        $clean = $this->sanitize($tracking);

        return $this->isValidFedEx($clean)
            || $this->isValidUps($clean)
            || $this->isValidUsps($clean)
            || $this->isValidDhl($clean);
    }

    public function detectCarrier(string $tracking): ?string
    {
        $clean = $this->sanitize($tracking);

        if ($this->isValidFedEx($clean)) {
            return 'FedEx';
        }

        if ($this->isValidUps($clean)) {
            return 'UPS';
        }

        if ($this->isValidUsps($clean)) {
            return 'USPS';
        }

        if ($this->isValidDhl($clean)) {
            return 'DHL';
        }

        return null;
    }

    public function formatTrackingUrl(string $tracking, string $carrier): string
    {
        $clean = $this->sanitize($tracking);
        $carrier = strtolower($carrier);

        return match ($carrier) {
            'fedex' => "https://www.fedex.com/apps/fedextrack/?tracknumbers={$clean}",
            'ups' => "https://www.ups.com/track?tracknum={$clean}",
            'usps' => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$clean}",
            'dhl' => "https://www.dhl.com/en/express/tracking.html?AWB={$clean}",
            default => throw new \InvalidArgumentException("Unknown carrier: {$carrier}"),
        };
    }

    public function parseTrackingInfo(string $tracking): array
    {
        $clean = $this->sanitize($tracking);
        $carrier = $this->detectCarrier($clean);

        if ($carrier === null) {
            throw new \InvalidArgumentException('Invalid tracking number');
        }

        return [
            'tracking' => $clean,
            'carrier' => $carrier,
            'url' => $this->formatTrackingUrl($clean, $carrier),
            'format' => $this->getDisplayFormat($clean, $carrier),
        ];
    }

    public function getDisplayFormat(string $tracking, string $carrier): string
    {
        $clean = $this->sanitize($tracking);
        $carrier = strtolower($carrier);

        if ($carrier === 'ups') {
            return $this->formatUpsDisplay($clean);
        }

        if ($carrier === 'usps' && preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $clean)) {
            return substr($clean, 0, 2) . ' ' . substr($clean, 2, 9) . ' ' . substr($clean, 11, 2);
        }

        if ($carrier === 'fedex') {
            return $this->formatFedExDisplay($clean);
        }

        return $clean;
    }

    public function validateChecksum(string $tracking, string $carrier): bool
    {
        $clean = $this->sanitize($tracking);

        return match (strtolower($carrier)) {
            'fedex' => $this->validateFedExChecksum($clean),
            'ups' => $this->validateUpsChecksum($clean),
            'dhl' => $this->validateDhlChecksum($clean),
            default => false,
        };
    }

    private function validateFedExChecksum(string $tracking): bool
    {
        if (!preg_match('/^\d{12,22}$/', $tracking)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < strlen($tracking) - 1; $i++) {
            $digit = (int) $tracking[$i];
            $sum += $digit * ($i % 2 === 0 ? 1 : 3);
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return (int) $tracking[strlen($tracking) - 1] === $checkDigit;
    }

    private function validateUpsChecksum(string $tracking): bool
    {
        if (!preg_match('/^1Z[A-Z0-9]{16}$/', $tracking)) {
            return false;
        }

        $sum = 0;

        for ($i = 2; $i < 17; $i++) {
            $char = $tracking[$i];
            $value = is_numeric($char) ? (int) $char : ord($char) - 55;

            $sum += $value * ($i % 2 === 0 ? 1 : 2);
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return (int) $tracking[17] === $checkDigit;
    }

    private function validateDhlChecksum(string $tracking): bool
    {
        if (strlen($tracking) === 10) {
            $sum = 0;

            for ($i = 0; $i < 9; $i++) {
                $digit = (int) $tracking[$i];
                $sum += $digit * ($i % 2 === 0 ? 1 : 3);
            }

            $checkDigit = (10 - ($sum % 10)) % 10;

            return (int) $tracking[9] === $checkDigit;
        }

        if (strlen($tracking) === 14) {
            $sum = 0;

            for ($i = 0; $i < 13; $i++) {
                $sum += (int) $tracking[$i];
            }

            return (int) $tracking[13] === (10 - ($sum % 10)) % 10;
        }

        return false;
    }

    private function formatUpsDisplay(string $tracking): string
    {
        return substr($tracking, 0, 3) . ' ' . substr($tracking, 3, 3) . ' '
            . substr($tracking, 6, 3) . ' ' . substr($tracking, 9, 3) . ' '
            . substr($tracking, 12, 3);
    }

    private function formatFedExDisplay(string $tracking): string
    {
        $len = strlen($tracking);

        if ($len === 15) {
            return substr($tracking, 0, 4) . ' ' . substr($tracking, 4, 3) . ' '
                . substr($tracking, 7, 3) . ' ' . substr($tracking, 10, 3) . ' '
                . substr($tracking, 13, 2);
        }

        return $tracking;
    }

    private function sanitize(string $tracking): string
    {
        return trim($tracking);
    }
}
