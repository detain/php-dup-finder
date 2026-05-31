<?php

declare(strict_types=1);

namespace App\Users\Validation;

use App\Exceptions\PhoneValidationException;

final class PhoneNumberNormalizer
{
    private const COUNTRY_PREFIXES = [
        '1' => ['US', 'CA', 'PR', 'VI', 'AG', 'AI', 'BB', 'BS', 'BM', 'DM', 'DO', 'GD', 'JE', 'KN', 'KY', 'LC', 'MS', 'SX', 'TC', 'TT'],
        '44' => ['GB', 'JE', 'IM', 'GG'],
        '61' => ['AU', 'CX', 'CC', 'NR', 'NF'],
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

    private const LENGTHS_BY_COUNTRY = [
        'US' => 10, 'CA' => 10, 'GB' => 10, 'AU' => 9,
        'DE' => 10, 'FR' => 9, 'IT' => 10, 'ES' => 9,
        'NL' => 9, 'BE' => 9, 'CH' => 9, 'AT' => 10,
        'SE' => 9, 'NO' => 8, 'DK' => 8, 'FI' => 9,
        'JP' => 10, 'KR' => 9, 'CN' => 11, 'IN' => 10,
        'BR' => 10, 'MX' => 10, 'AR' => 10, 'CL' => 9,
        'ZA' => 9, 'EG' => 10, 'NG' => 10, 'KE' => 9,
    ];

    public function normalize(string $phone, string $country): string
    {
        $clean = $this->toDigitsOnly($phone);
        $this->validateNotBlank($clean);

        $prefix = $this->lookupPrefix($country);
        $significant = $this->removePrefix($clean, $prefix);
        $this->validateLength($significant, $country);

        return $significant;
    }

    public function normalizeToInternational(string $phone, string $country): string
    {
        $clean = $this->toDigitsOnly($phone);
        $this->validateNotBlank($clean);

        $prefix = $this->lookupPrefix($country);
        $significant = $this->removePrefix($clean, $prefix);

        return '+' . $prefix . $significant;
    }

    public function format(string $phone, string $country): string
    {
        $clean = $this->toDigitsOnly($phone);
        $prefix = $this->lookupPrefix($country);
        $significant = $this->removePrefix($clean, $prefix);

        return $this->formatAccordingToCountry($significant, $country);
    }

    public function formatInternational(string $phone): string
    {
        $clean = $this->toDigitsOnly($phone);
        $country = $this->deriveCountry($clean);
        $prefix = $this->lookupPrefix($country);
        $significant = $this->removePrefix($clean, $prefix);

        return '+' . $prefix . ' ' . $this->formatAccordingToCountry($significant, $country);
    }

    public function formatForStorage(string $phone, string $country): string
    {
        $prefix = $this->lookupPrefix($country);
        $clean = $this->toDigitsOnly($phone);
        $significant = $this->removePrefix($clean, $prefix);

        return $prefix . $significant;
    }

    private function lookupPrefix(string $country): string
    {
        $upperCountry = strtoupper($country);

        foreach (self::COUNTRY_PREFIXES as $code => $countries) {
            if (in_array($upperCountry, $countries, true)) {
                return $code;
            }
        }

        throw new PhoneValidationException("Country {$country} is not supported");
    }

    private function deriveCountry(string $digits): string
    {
        foreach (self::COUNTRY_PREFIXES as $code => $countries) {
            if (str_starts_with($digits, $code)) {
                return $countries[0];
            }
        }

        return 'US';
    }

    private function removePrefix(string $digits, string $prefix): string
    {
        if (str_starts_with($digits, $prefix)) {
            $national = substr($digits, strlen($prefix));
            return ltrim($national, '0');
        }

        return $digits;
    }

    private function validateLength(string $significant, string $country): void
    {
        $expected = self::LENGTHS_BY_COUNTRY[strtoupper($country)] ?? null;

        if ($expected !== null && strlen($significant) !== $expected) {
            throw new PhoneValidationException(
                "Number for {$country} must be {$expected} digits, got " . strlen($significant)
            );
        }
    }

    private function formatAccordingToCountry(string $significant, string $country): string
    {
        $upper = strtoupper($country);

        return match ($upper) {
            'US', 'CA' => '(' . substr($significant, 0, 3) . ') ' . substr($significant, 3, 3) . '-' . substr($significant, 6),
            'GB' => substr($significant, 0, 4) . ' ' . substr($significant, 4, 3) . ' ' . substr($significant, 7),
            'AU' => substr($significant, 0, 4) . ' ' . substr($significant, 4, 3) . ' ' . substr($significant, 7),
            'DE', 'AT' => substr($significant, 0, 3) . ' ' . substr($significant, 3, 3) . ' ' . substr($significant, 6),
            'FR' => substr($significant, 0, 2) . ' ' . substr($significant, 2, 2) . ' ' . substr($significant, 4, 2) . ' ' . substr($significant, 6),
            'JP' => substr($significant, 0, 3) . '-' . substr($significant, 3, 4) . '-' . substr($significant, 7),
            default => $this->generalFormat($significant),
        };
    }

    private function generalFormat(string $significant): string
    {
        $len = strlen($significant);

        if ($len <= 4) {
            return $significant;
        }

        if ($len <= 7) {
            return substr($significant, 0, $len - 4) . '-' . substr($significant, -4);
        }

        return substr($significant, 0, 3) . '-' . substr($significant, 3, 3) . '-' . substr($significant, 6);
    }

    private function toDigitsOnly(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    private function validateNotBlank(string $phone): void
    {
        if (empty($phone)) {
            throw new PhoneValidationException('Phone number cannot be empty');
        }
    }
}
