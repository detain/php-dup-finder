<?php

declare(strict_types=1);

namespace App\Notifications\Phone;

use App\Exceptions\PhoneNumberException;

final class GlobalPhoneNumberFormatter
{
    private const REGION_CODES = [
        '1' => ['US', 'CA', 'AG', 'AI', 'AS', 'BB', 'BM', 'BS', 'DM', 'DO', 'GD', 'GU', 'JM', 'KN', 'KY', 'LC', 'MP', 'MS', 'PR', 'SX', 'TC', 'TT', 'VC', 'VG', 'VI'],
        '44' => ['GB', 'JE', 'IM', 'GG'],
        '61' => ['AU', 'CC', 'CX', 'NR', 'NF'],
        '49' => ['DE'],
        '33' => ['FR'],
        '39' => ['IT', 'SM', 'VA'],
        '34' => ['ES'],
        '31' => ['NL'],
        '32' => ['BE'],
        '41' => ['CH'],
        '46' => ['SE'],
        '47' => ['NO', 'SJ'],
        '45' => ['DK'],
        '358' => ['FI', 'AX'],
        '81' => ['JP'],
        '82' => ['KR'],
        '86' => ['CN'],
        '91' => ['IN'],
        '55' => ['BR'],
        '52' => ['MX'],
        '54' => ['AR'],
        '56' => ['CL'],
        '27' => ['ZA'],
        '20' => ['EG'],
        '234' => ['NG'],
        '254' => ['KE'],
    ];

    private const DIGIT_COUNTS = [
        'US' => 10, 'CA' => 10, 'GB' => 10, 'AU' => 9,
        'DE' => 10, 'FR' => 9, 'IT' => 10, 'ES' => 9,
        'NL' => 9, 'BE' => 9, 'CH' => 9, 'AT' => 10,
        'SE' => 9, 'NO' => 8, 'DK' => 8, 'FI' => 9,
        'JP' => 10, 'KR' => 9, 'CN' => 11, 'IN' => 10,
        'BR' => 10, 'MX' => 10, 'AR' => 10, 'CL' => 9,
        'ZA' => 9, 'EG' => 10, 'NG' => 10, 'KE' => 9,
    ];

    public function formatNumber(string $phone, string $country): string
    {
        $digits = $this->removeFormatting($phone);
        $this->ensureNonEmpty($digits);

        $regionCode = $this->obtainRegionCode($country);
        $nationalSignificant = $this->stripRegionCode($digits, $regionCode);
        $this->ensureCorrectDigitCount($nationalSignificant, $country);

        return $this->applyNationalFormat($nationalSignificant, $country);
    }

    public function formatWithInternationalPrefix(string $phone): string
    {
        $digits = $this->removeFormatting($phone);
        $this->ensureNonEmpty($digits);

        $detectedRegion = $this->identifyRegion($digits);
        $regionCode = $this->obtainRegionCode($detectedRegion);
        $nationalSignificant = $this->stripRegionCode($digits, $regionCode);

        return '+' . $regionCode . ' ' . $this->applyNationalFormat($nationalSignificant, $detectedRegion);
    }

    public function formatForMessaging(string $phone, string $country): string
    {
        $digits = $this->removeFormatting($phone);

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        $regionCode = $this->obtainRegionCode($country);
        return '+' . $regionCode . $this->stripRegionCode($digits, $regionCode);
    }

    public function formatForCalls(string $phone, string $country): string
    {
        $regionCode = $this->obtainRegionCode($country);
        $digits = $this->removeFormatting($phone);
        $national = $this->stripRegionCode($digits, $regionCode);

        return '+' . $regionCode . $national;
    }

    public function normalizeToE164(string $phone, string $country): string
    {
        $digits = $this->removeFormatting($phone);
        $regionCode = $this->obtainRegionCode($country);
        $national = $this->stripRegionCode($digits, $regionCode);

        return '+' . $regionCode . $national;
    }

    private function obtainRegionCode(string $country): string
    {
        $upperCountry = strtoupper($country);

        foreach (self::REGION_CODES as $code => $countries) {
            if (in_array($upperCountry, $countries, true)) {
                return $code;
            }
        }

        throw new PhoneNumberException("Country {$country} is not supported");
    }

    private function identifyRegion(string $digits): string
    {
        foreach (self::REGION_CODES as $code => $countries) {
            if (str_starts_with($digits, $code)) {
                return $countries[0];
            }
        }

        return 'US';
    }

    private function stripRegionCode(string $digits, string $regionCode): string
    {
        if (str_starts_with($digits, $regionCode)) {
            $national = substr($digits, strlen($regionCode));
            return ltrim($national, '0');
        }

        return $digits;
    }

    private function ensureCorrectDigitCount(string $national, string $country): void
    {
        $expected = self::DIGIT_COUNTS[strtoupper($country)] ?? null;

        if ($expected !== null && strlen($national) !== $expected) {
            throw new PhoneNumberException(
                "Phone number for {$country} must have {$expected} digits, got " . strlen($national)
            );
        }
    }

    private function applyNationalFormat(string $national, string $country): string
    {
        $upperCountry = strtoupper($country);

        return match ($upperCountry) {
            'US', 'CA' => '(' . substr($national, 0, 3) . ') ' . substr($national, 3, 3) . '-' . substr($national, 6),
            'GB' => substr($national, 0, 4) . ' ' . substr($national, 4, 3) . ' ' . substr($national, 7),
            'AU' => substr($national, 0, 4) . ' ' . substr($national, 4, 3) . ' ' . substr($national, 7),
            'DE', 'AT' => substr($national, 0, 3) . ' ' . substr($national, 3, 3) . ' ' . substr($national, 6),
            'FR' => substr($national, 0, 2) . ' ' . substr($national, 2, 2) . ' ' . substr($national, 4, 2) . ' ' . substr($national, 6),
            'JP' => substr($national, 0, 3) . '-' . substr($national, 3, 4) . '-' . substr($national, 7),
            default => $this->splitIntoGroups($national),
        };
    }

    private function splitIntoGroups(string $national): string
    {
        $len = strlen($national);

        if ($len <= 3) {
            return $national;
        }

        if ($len <= 5) {
            return substr($national, 0, $len - 3) . ' ' . substr($national, -3);
        }

        if ($len <= 7) {
            return substr($national, 0, 3) . ' ' . substr($national, 3, $len - 3) . ' ' . substr($national, -3);
        }

        return substr($national, 0, 3) . ' ' . substr($national, 3, 3) . ' ' . substr($national, 6);
    }

    private function removeFormatting(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    private function ensureNonEmpty(string $phone): void
    {
        if (empty($phone)) {
            throw new PhoneNumberException('Phone number cannot be empty');
        }
    }
}
