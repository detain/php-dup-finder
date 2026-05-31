<?php

namespace App\Services\Compliance;

final class TaxIdConfig
{
    public readonly bool $allowMasking;
    public readonly bool $requireFormatted;

    public function __construct(bool $allowMasking = true, bool $requireFormatted = false)
    {
        $this->allowMasking = $allowMasking;
        $this->requireFormatted = $requireFormatted;
    }
}

final class TaxIdService
{
    private TaxIdConfig $config;

    public function __construct(TaxIdConfig $config)
    {
        $this->config = $config;
    }

    public function validate(string $taxId): array
    {
        $clean = $this->normalize($taxId);

        if (preg_match('/^\d{2}-\d{7}$/', $clean)) {
            return $this->validateEin($clean);
        }

        if (preg_match('/^\d{3}-\d{2}-\d{4}$/', $clean)) {
            return $this->validateSsnItin($clean);
        }

        return ['valid' => false, 'type' => 'UNKNOWN'];
    }

    public function format(string $taxId): string
    {
        $clean = preg_replace('/\D/', '', $taxId) ?? '';

        if (strlen($clean) === 9) {
            return substr($clean, 0, 2) . '-' . substr($clean, 2, 7);
        }

        if (strlen($clean) === 11) {
            return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' . substr($clean, 5, 4);
        }

        throw new \InvalidArgumentException('Invalid tax ID length');
    }

    public function mask(string $taxId): string
    {
        $formatted = $this->format($taxId);

        if (str_contains($formatted, '-', 2)) {
            return substr($formatted, 0, 3) . '**-' . substr($formatted, 6);
        }

        return '***-**-' . substr($formatted, 7);
    }

    private function validateEin(string $ein): array
    {
        $prefix = (int) substr($ein, 0, 2);

        return [
            'valid' => $prefix >= 1 && $prefix <= 99,
            'type' => 'EIN',
            'formatted' => $ein,
        ];
    }

    private function validateSsnItin(string $ssn): array
    {
        $area = (int) substr($ssn, 0, 3);
        $group = (int) substr($ssn, 4, 2);

        if ($area >= 900) {
            return [
                'valid' => $group >= 70 && $group <= 99,
                'type' => 'ITIN',
                'formatted' => $ssn,
            ];
        }

        $serial = (int) substr($ssn, 7, 4);

        return [
            'valid' => $area !== 0 && $area !== 666 && $group !== 0 && $serial !== 0,
            'type' => 'SSN',
            'formatted' => $ssn,
        ];
    }

    private function normalize(string $taxId): string
    {
        return trim($taxId);
    }
}
