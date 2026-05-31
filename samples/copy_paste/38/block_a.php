<?php

declare(strict_types=1);

namespace App\Billing;

final class TaxIdValidator
{
    private const US_PATTERN = '/^\d{2}-\d{7}$/';
    private const EIN_PATTERN = '/^\d{2}-\d{7}$/';
    private const SSN_PATTERN = '/^\d{3}-\d{2}-\d{4}$/';
    private const ITIN_PATTERN = '/^9\d{2}-\d{2}-\d{4}$/';

    public function isValidUsTaxId(string $taxId): bool
    {
        $clean = $this->normalize($taxId);

        return $this->isValidEin($clean) || $this->isValidSsn($clean) || $this->isValidItin($clean);
    }

    public function isValidEin(string $ein): bool
    {
        $clean = $this->normalize($ein);

        if (!preg_match(self::EIN_PATTERN, $clean)) {
            return false;
        }

        $prefix = (int) substr($clean, 0, 2);

        return $prefix >= 1 && $prefix <= 99;
    }

    public function isValidSsn(string $ssn): bool
    {
        $clean = $this->normalize($ssn);

        if (!preg_match(self::SSN_PATTERN, $clean)) {
            return false;
        }

        $area = (int) substr($clean, 0, 3);
        $group = (int) substr($clean, 4, 2);
        $serial = (int) substr($clean, 7, 4);

        if ($area === 0 || $area === 666 || $area >= 900) {
            return false;
        }

        if ($group === 0) {
            return false;
        }

        if ($serial === 0) {
            return false;
        }

        return true;
    }

    public function isValidItin(string $itin): bool
    {
        $clean = $this->normalize($itin);

        if (!preg_match(self::ITIN_PATTERN, $clean)) {
            return false;
        }

        $area = (int) substr($clean, 0, 3);

        if ($area < 900 || $area > 999) {
            return false;
        }

        $group = (int) substr($clean, 4, 2);

        if ($group < 70 || $group > 99) {
            return false;
        }

        return true;
    }

    public function isValidEinPrefix(int $prefix): bool
    {
        return $prefix >= 1 && $prefix <= 99;
    }

    public function formatEin(string $ein): string
    {
        $clean = preg_replace('/\D/', '', $ein);

        if ($clean === null || strlen($clean) !== 9) {
            throw new \InvalidArgumentException('Invalid EIN');
        }

        return substr($clean, 0, 2) . '-' . substr($clean, 2, 7);
    }

    public function formatSsn(string $ssn): string
    {
        $clean = preg_replace('/\D/', '', $ssn);

        if ($clean === null || strlen($clean) !== 9) {
            throw new \InvalidArgumentException('Invalid SSN');
        }

        return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' . substr($clean, 5, 4);
    }

    public function maskEin(string $ein): string
    {
        $formatted = $this->formatEin($ein);

        return substr($formatted, 0, 3) . '**-' . substr($formatted, 6);
    }

    public function maskSsn(string $ssn): string
    {
        $formatted = $this->formatSsn($ssn);

        return '***-**-' . substr($formatted, 7);
    }

    public function validateBusinessTaxId(string $taxId): array
    {
        $clean = $this->normalize($taxId);

        if ($this->isValidEin($clean)) {
            return ['type' => 'EIN', 'formatted' => $this->formatEin($clean)];
        }

        throw new \InvalidArgumentException('Invalid business tax ID');
    }

    public function validatePersonalTaxId(string $taxId): array
    {
        $clean = $this->normalize($taxId);

        if ($this->isValidSsn($clean)) {
            return ['type' => 'SSN', 'formatted' => $this->formatSsn($clean)];
        }

        if ($this->isValidItin($clean)) {
            return ['type' => 'ITIN', 'formatted' => $this->formatEin($clean)];
        }

        throw new \InvalidArgumentException('Invalid personal tax ID');
    }

    public function parseTaxId(string $taxId): array
    {
        $clean = $this->normalize($taxId);

        if (preg_match('/^\d{2}-\d{7}$/', $clean)) {
            $type = $this->isValidEin($clean) ? 'EIN' : 'UNKNOWN';

            return ['raw' => $clean, 'type' => $type, 'formatted' => $clean];
        }

        if (preg_match('/^\d{3}-\d{2}-\d{4}$/', $clean)) {
            $area = (int) substr($clean, 0, 3);
            $type = $area >= 900 ? 'ITIN' : 'SSN';

            return ['raw' => $clean, 'type' => $type, 'formatted' => $clean];
        }

        return ['raw' => $clean, 'type' => 'INVALID', 'formatted' => null];
    }

    public function generateEinSample(): string
    {
        $prefix = str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $suffix = str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        return $prefix . '-' . $suffix;
    }

    public function generateSsnSample(): string
    {
        $area = str_pad((string) random_int(1, 899), 3, '0', STR_PAD_LEFT);
        $group = str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $serial = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        if ($area === '666') {
            $area = '667';
        }

        return $area . '-' . $group . '-' . $serial;
    }

    private function normalize(string $taxId): string
    {
        return trim($taxId);
    }
}
