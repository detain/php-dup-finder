<?php

declare(strict_types=1);

namespace App\Compliance;

final class FiscalIdentifierValidator
{
    public function verifyEin(string $ein): bool
    {
        $clean = $this->removeWhitespace($ein);

        if (!preg_match('/^\d{2}-\d{7}$/', $clean)) {
            return false;
        }

        $departmentCode = (int) substr($clean, 0, 2);

        return $departmentCode >= 1 && $departmentCode <= 99;
    }

    public function verifySsn(string $ssn): bool
    {
        $clean = $this->removeWhitespace($ssn);

        if (!preg_match('/^\d{3}-\d{2}-\d{4}$/', $clean)) {
            return false;
        }

        $zone = (int) substr($clean, 0, 3);
        $cluster = (int) substr($clean, 4, 2);
        $line = (int) substr($clean, 7, 4);

        if ($zone === 0 || $zone === 666 || $zone >= 900) {
            return false;
        }

        if ($cluster === 0) {
            return false;
        }

        if ($line === 0) {
            return false;
        }

        return true;
    }

    public function verifyItin(string $itin): bool
    {
        $clean = $this->removeWhitespace($itin);

        if (!preg_match('/^9\d{2}-\d{2}-\d{4}$/', $clean)) {
            return false;
        }

        $zoneNumber = (int) substr($clean, 0, 3);

        if ($zoneNumber < 900) {
            return false;
        }

        $clusterNumber = (int) substr($clean, 4, 2);

        if ($clusterNumber < 70) {
            return false;
        }

        return true;
    }

    public function isTaxId(string $id): bool
    {
        return $this->verifyEin($id)
            || $this->verifySsn($id)
            || $this->verifyItin($id);
    }

    public function formatEmployerId(string $ein): string
    {
        $digits = preg_replace('/\D/', '', $ein);

        if ($digits === null || strlen($digits) !== 9) {
            throw new \InvalidArgumentException('Employer ID requires exactly 9 digits');
        }

        return substr($digits, 0, 2) . '-' . substr($digits, 2, 7);
    }

    public function formatSocialNumber(string $ssn): string
    {
        $digits = preg_replace('/\D/', '', $ssn);

        if ($digits === null || strlen($digits) !== 9) {
            throw new \InvalidArgumentException('Social Security Number requires exactly 9 digits');
        }

        return substr($digits, 0, 3) . '-' . substr($digits, 3, 2) . '-' . substr($digits, 5, 4);
    }

    public function hideEmployerId(string $ein): string
    {
        $formatted = $this->formatEmployerId($ein);

        return substr($formatted, 0, 3) . '**-' . substr($formatted, 6);
    }

    public function hideSocialNumber(string $ssn): string
    {
        $formatted = $this->formatSocialNumber($ssn);

        return '***-**-' . substr($formatted, 7);
    }

    public function identify(string $taxId): array
    {
        $scrubbed = $this->removeWhitespace($taxId);

        if (preg_match('/^\d{2}-\d{7}$/', $scrubbed)) {
            $kind = $this->verifyEin($scrubbed) ? 'EIN' : 'UNKNOWN';

            return ['classification' => $kind, 'canonical' => $this->formatEmployerId($scrubbed)];
        }

        if (preg_match('/^\d{3}-\d{2}-\d{4}$/', $scrubbed)) {
            $areaNum = (int) substr($scrubbed, 0, 3);
            $classification = $areaNum >= 900 ? 'ITIN' : 'SSN';

            return [
                'classification' => $classification,
                'canonical' => $classification === 'SSN' ? $this->formatSocialNumber($scrubbed) : $scrubbed,
            ];
        }

        return ['classification' => 'INVALID', 'canonical' => null];
    }

    public function produceEinDemo(): string
    {
        $segment1 = str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $segment2 = str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

        return "{$segment1}-{$segment2}";
    }

    public function produceSsnDemo(): string
    {
        $segment1 = str_pad((string) random_int(1, 899), 3, '0', STR_PAD_LEFT);
        $segment2 = str_pad((string) random_int(1, 99), 2, '0', STR_PAD_LEFT);
        $segment3 = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        if ($segment1 === '666') {
            $segment1 = '667';
        }

        return "{$segment1}-{$segment2}-{$segment3}";
    }

    private function removeWhitespace(string $id): string
    {
        return trim($id);
    }
}
