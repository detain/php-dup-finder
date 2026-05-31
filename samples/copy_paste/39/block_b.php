<?php

declare(strict_types=1);

namespace App\Fulfillment;

final class ShipmentTracker
{
    public function checkFedEx(string $id): bool
    {
        $scrubbed = $this->stripWhitespace($id);

        return (bool) preg_match('/^\d{12,22}$/', $scrubbed);
    }

    public function checkUps(string $id): bool
    {
        $scrubbed = $this->stripWhitespace($id);

        return (bool) preg_match('/^1Z[A-Z0-9]{16}$/', $scrubbed);
    }

    public function checkUsps(string $id): bool
    {
        $scrubbed = $this->stripWhitespace($id);

        if (preg_match('/^\d{20,22}$/', $scrubbed)) {
            return true;
        }

        return (bool) preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $scrubbed);
    }

    public function checkDhl(string $id): bool
    {
        $scrubbed = $this->stripWhitespace($id);

        return (bool) preg_match('/^\d{10}$/', $scrubbed)
            || (bool) preg_match('/^\d{14}$/', $scrubbed)
            || (bool) preg_match('/^[A-Z]{3}\d{7}$/', $scrubbed);
    }

    public function isRecognized(string $id): bool
    {
        $scrubbed = $this->stripWhitespace($id);

        return $this->checkFedEx($scrubbed)
            || $this->checkUps($scrubbed)
            || $this->checkUsps($scrubbed)
            || $this->checkDhl($scrubbed);
    }

    public function identifyCarrier(string $id): ?string
    {
        $scrubbed = $this->stripWhitespace($id);

        if ($this->checkFedEx($scrubbed)) {
            return 'FedEx';
        }

        if ($this->checkUps($scrubbed)) {
            return 'UPS';
        }

        if ($this->checkUsps($scrubbed)) {
            return 'USPS';
        }

        if ($this->checkDhl($scrubbed)) {
            return 'DHL';
        }

        return null;
    }

    public function buildTrackingLink(string $tracking, string $provider): string
    {
        $clean = $this->stripWhitespace($tracking);
        $normalizedProvider = strtolower($provider);

        return match ($normalizedProvider) {
            'fedex' => "https://www.fedex.com/apps/fedextrack/?tracknumbers={$clean}",
            'ups' => "https://www.ups.com/track?tracknum={$clean}",
            'usps' => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$clean}",
            'dhl' => "https://www.dhl.com/en/express/tracking.html?AWB={$clean}",
            default => throw new \InvalidArgumentException("Unrecognized carrier: {$provider}"),
        };
    }

    public function decodeTracking(string $tracking): array
    {
        $clean = $this->stripWhitespace($tracking);
        $carrier = $this->identifyCarrier($clean);

        if ($carrier === null) {
            throw new \InvalidArgumentException('Unrecognized tracking number');
        }

        return [
            'number' => $clean,
            'carrier' => $carrier,
            'link' => $this->buildTrackingLink($clean, $carrier),
            'formatted' => $this->prettify($clean, $carrier),
        ];
    }

    public function prettifyTracking(string $tracking, string $carrier): string
    {
        $clean = $this->stripWhitespace($tracking);
        $provider = strtolower($carrier);

        if ($provider === 'ups') {
            return $this->makeUpsPretty($clean);
        }

        if ($provider === 'usps' && preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $clean)) {
            return substr($clean, 0, 2) . ' ' . substr($clean, 2, 9) . ' ' . substr($clean, 11, 2);
        }

        if ($provider === 'fedex') {
            return $this->makeFedExPretty($clean);
        }

        return $clean;
    }

    public function verifyCheckDigit(string $tracking, string $provider): bool
    {
        $clean = $this->stripWhitespace($tracking);

        return match (strtolower($provider)) {
            'fedex' => $this->checkFedExDigit($clean),
            'ups' => $this->checkUpsDigit($clean),
            'dhl' => $this->checkDhlDigit($clean),
            default => false,
        };
    }

    private function checkFedExDigit(string $id): bool
    {
        if (!preg_match('/^\d{12,22}$/', $id)) {
            return false;
        }

        $total = 0;
        $length = strlen($id);

        for ($position = 0; $position < $length - 1; $position++) {
            $digit = (int) $id[$position];
            $total += $digit * ($position % 2 === 0 ? 1 : 3);
        }

        $calculated = (10 - ($total % 10)) % 10;

        return (int) $id[$length - 1] === $calculated;
    }

    private function checkUpsDigit(string $id): bool
    {
        if (!preg_match('/^1Z[A-Z0-9]{16}$/', $id)) {
            return false;
        }

        $total = 0;

        for ($i = 2; $i < 17; $i++) {
            $c = $id[$i];
            $val = is_numeric($c) ? (int) $c : ord($c) - 55;

            $total += $val * ($i % 2 === 0 ? 1 : 2);
        }

        $check = (10 - ($total % 10)) % 10;

        return (int) $id[17] === $check;
    }

    private function checkDhlDigit(string $id): bool
    {
        if (strlen($id) === 10) {
            $total = 0;

            for ($i = 0; $i < 9; $i++) {
                $total += (int) $id[$i] * ($i % 2 === 0 ? 1 : 3);
            }

            $digit = (10 - ($total % 10)) % 10;

            return (int) $id[9] === $digit;
        }

        if (strlen($id) === 14) {
            $total = 0;

            for ($i = 0; $i < 13; $i++) {
                $total += (int) $id[$i];
            }

            return (int) $id[13] === (10 - ($total % 10)) % 10;
        }

        return false;
    }

    private function makeUpsPretty(string $tracking): string
    {
        return substr($tracking, 0, 3) . ' ' . substr($tracking, 3, 3) . ' '
            . substr($tracking, 6, 3) . ' ' . substr($tracking, 9, 3) . ' '
            . substr($tracking, 12, 3);
    }

    private function makeFedExPretty(string $tracking): string
    {
        $len = strlen($tracking);

        if ($len === 15) {
            return substr($tracking, 0, 4) . ' ' . substr($tracking, 4, 3) . ' '
                . substr($tracking, 7, 3) . ' ' . substr($tracking, 10, 3) . ' '
                . substr($tracking, 13, 2);
        }

        return $tracking;
    }

    private function stripWhitespace(string $tracking): string
    {
        return trim($tracking);
    }
}
