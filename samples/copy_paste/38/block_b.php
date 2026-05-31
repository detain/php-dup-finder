<?php

declare(strict_types=1);

namespace App\Accounting;

final class TaxIdentifierChecker
{
    public function checkEin(string $ein): bool
    {
        $stripped = $this->sanitize($ein);

        if (!preg_match('/^\d{2}-\d{7}$/', $stripped)) {
            return false;
        }

        $firstTwo = (int) substr($stripped, 0, 2);

        return $firstTwo >= 1 && $firstTwo <= 99;
    }

    public function checkSsn(string $ssn): bool
    {
        $stripped = $this->sanitize($ssn);

        if (!preg_match('/^\d{3}-\d{2}-\d{4}$/', $stripped)) {
            return false;
        }

        $areaNumber = (int) substr($stripped, 0, 3);
        $groupCode = (int) substr($stripped, 4, 2);
        $sequence = (int) substr($stripped, 7, 4);

        if ($areaNumber === 0 || $areaNumber === 666 || $areaNumber >= 900) {
            return false;
        }

        if ($groupCode === 0) {
            return false;
        }

        if ($sequence === 0) {
            return false;
        }

        return true;
    }

    public function checkItin(string $itin): bool
    {
        $stripped = $this->sanitize($itin);

        if (!preg_match('/^9\d{2}-\d{2}-\d{4}$/', $stripped)) {
            return false;
        }

        $areaNum = (int) substr($stripped, 0, 3);

        if ($areaNum < 900) {
            return false;
        }

        $grpCode = (int) substr($stripped, 4, 2);

        if ($grpCode < 70) {
            return false;
        }

        return true;
    }

    public function isUsTaxId(string $taxId): bool
    {
        return $this->checkEin($taxId)
            || $this->checkSsn($taxId)
            || $this->checkItin($taxId);
    }

    public function normalizeEin(string $ein): string
    {
        $numeric = preg_replace('/\D/', '', $ein);

        if ($numeric === null || strlen($numeric) !== 9) {
            throw new \InvalidArgumentException('EIN must be 9 digits');
        }

        return substr($numeric, 0, 2) . '-' . substr($numeric, 2, 7);
    }

    public function normalizeSsn(string $ssn): string
    {
        $numeric = preg_replace('/\D/', '', $ssn);

        if ($numeric === null || strlen($numeric) !== 9) {
            throw new \InvalidArgumentException('SSN must be 9 digits');
        }

        return substr($numeric, 0, 3) . '-' . substr($numeric, 3, 2) . '-' . substr($numeric, 5, 4);
    }

    public function obscureEin(string $ein): string
    {
        $formatted = $this->normalizeEin($ein);

        return substr($formatted, 0, 3) . '**-' . substr($formatted, 6);
    }

    public function obscureSsn(string $ssn): string
    {
        $formatted = $this->normalizeSsn($ssn);

        return '***-**-' . substr($formatted, 7);
    }

    public function parseUsTaxId(string $taxId): array
    {
        $clean = $this->sanitize($taxId);

        if (preg_match('/^\d{2}-\d{7}$/', $clean)) {
            return [
                'category' => $this->checkEin($clean) ? 'EIN' : 'INVALID',
                'value' => $this->normalizeEin($clean),
            ];
        }

        if (preg_match('/^\d{3}-\d{2}-\d{4}$/', $clean)) {
            $area = (int) substr($clean, 0, 3);
            $category = $area >= 900 ? 'ITIN' : 'SSN';

            return [
                'category' => $category,
                'value' => $category === 'SSN' ? $this->normalizeSsn($clean) : $clean,
            ];
        }

        return ['category' => 'UNKNOWN', 'value' => null];
    }

    public function buildEinSample(): string
    {
        $p = str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $s = str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        return "{$p}-{$s}";
    }

    public function buildSsnSample(): string
    {
        $a = str_pad((string) random_int(1, 899), 3, '0', STR_PAD_LEFT);
        $g = str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $s = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        if ($a === '666') {
            $a = '667';
        }

        return "{$a}-{$g}-{$s}";
    }

    private function sanitize(string $taxId): string
    {
        return trim($taxId);
    }
}
