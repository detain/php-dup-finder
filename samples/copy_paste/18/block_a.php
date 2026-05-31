<?php

declare(strict_types=1);

namespace App\Contacts\Formatting;

use App\Exceptions\PhoneFormattingException;

final class InternationalPhoneFormatter
{
    private const COUNTRY_CALLING_CODES = [
        'US' => '1', 'CA' => '1', 'GB' => '44', 'AU' => '61',
        'DE' => '49', 'FR' => '33', 'IT' => '39', 'ES' => '34',
        'NL' => '31', 'BE' => '32', 'CH' => '41', 'AT' => '43',
        'SE' => '46', 'NO' => '47', 'DK' => '45', 'FI' => '358',
        'JP' => '81', 'KR' => '82', 'CN' => '86', 'IN' => '91',
        'BR' => '55', 'MX' => '52', 'AR' => '54', 'CL' => '56',
        'ZA' => '27', 'EG' => '20', 'NG' => '234', 'KE' => '254',
    ];

    private const NATIONAL_NUMBER_LENGTHS = [
        'US' => 10, 'CA' => 10, 'GB' => 10, 'AU' => 9,
        'DE' => 10, 'FR' => 9, 'IT' => 10, 'ES' => 9,
        'NL' => 9, 'BE' => 9, 'CH' => 9, 'AT' => 10,
        'SE' => 9, 'NO' => 8, 'DK' => 8, 'FI' => 9,
        'JP' => 10, 'KR' => 9, 'CN' => 11, 'IN' => 10,
        'BR' => 10, 'MX' => 10, 'AR' => 10, 'CL' => 9,
        'ZA' => 9, 'EG' => 10, 'NG' => 10, 'KE' => 9,
    ];

    public function format(string $phoneNumber, string $countryCode): string
    {
        $cleaned = $this->stripNonDigits($phoneNumber);
        $this->validateNotEmpty($cleaned);

        $code = $this->getCallingCode($countryCode);
        $national = $this->extractNationalNumber($cleaned, $code);
        $this->validateNationalLength($national, $countryCode);

        return $this->formatNationalNumber($national, $countryCode);
    }

    public function formatWithCountryCode(string $phoneNumber): string
    {
        $cleaned = $this->stripNonDigits($phoneNumber);
        $this->validateNotEmpty($cleaned);

        if (str_starts_with($cleaned, '+')) {
            $cleaned = substr($cleaned, 1);
        }

        $country = $this->detectCountry($cleaned);
        $code = $this->getCallingCode($country);
        $national = $this->extractNationalNumber($cleaned, $code);

        return '+' . $code . ' ' . $this->formatNationalNumber($national, $country);
    }

    public function formatForSms(string $phoneNumber, string $countryCode): string
    {
        $cleaned = $this->stripNonDigits($phoneNumber);

        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        $code = $this->getCallingCode($countryCode);
        return '+' . $code . $cleaned;
    }

    public function formatForDisplay(string $phoneNumber, string $countryCode): string
    {
        $formatted = $this->format($phoneNumber, $countryCode);
        return $this->addCountryFlag($formatted, $countryCode);
    }

    public function formatForDialing(string $phoneNumber, string $countryCode): string
    {
        $code = $this->getCallingCode($countryCode);
        $cleaned = $this->stripNonDigits($phoneNumber);
        $national = $this->extractNationalNumber($cleaned, $code);

        return '+' . $code . $national;
    }

    public function normalize(string $phoneNumber, string $countryCode): string
    {
        $cleaned = $this->stripNonDigits($phoneNumber);
        $code = $this->getCallingCode($countryCode);
        $national = $this->extractNationalNumber($cleaned, $code);

        return $code . $national;
    }

    private function getCallingCode(string $country): string
    {
        $upper = strtoupper($country);

        if (!isset(self::COUNTRY_CALLING_CODES[$upper])) {
            throw new PhoneFormattingException("Unsupported country code: {$country}");
        }

        return self::COUNTRY_CALLING_CODES[$upper];
    }

    private function extractNationalNumber(string $digits, string $callingCode): string
    {
        if (strlen($callingCode) > 1 && str_starts_with($digits, $callingCode)) {
            return substr($digits, strlen($callingCode));
        }

        if (strlen($callingCode) === 1 && str_starts_with($digits, $callingCode)) {
            if (strlen($digits) > 3) {
                return substr($digits, 1);
            }
        }

        return $digits;
    }

    private function validateNationalLength(string $national, string $country): void
    {
        $expectedLength = self::NATIONAL_NUMBER_LENGTHS[strtoupper($country)] ?? null;

        if ($expectedLength !== null && strlen($national) !== $expectedLength) {
            throw new PhoneFormattingException(
                "Invalid phone number length for {$country}. Expected {$expectedLength} digits, got " . strlen($national)
            );
        }
    }

    private function formatNationalNumber(string $national, string $country): string
    {
        $upper = strtoupper($country);

        return match ($upper) {
            'US', 'CA' => '(' . substr($national, 0, 3) . ') ' . substr($national, 3, 3) . '-' . substr($national, 6),
            'GB' => substr($national, 0, 4) . ' ' . substr($national, 4, 3) . ' ' . substr($national, 7),
            'AU' => substr($national, 0, 4) . ' ' . substr($national, 4, 3) . ' ' . substr($national, 7),
            'DE', 'AT' => substr($national, 0, 3) . ' ' . substr($national, 3, 3) . ' ' . substr($national, 6),
            'FR' => substr($national, 0, 2) . ' ' . substr($national, 2, 2) . ' ' . substr($national, 4, 2) . ' ' . substr($national, 6),
            'JP' => substr($national, 0, 3) . '- ' . substr($national, 3, 4) . '- ' . substr($national, 7),
            default => $this->chunkNationalNumber($national),
        };
    }

    private function chunkNationalNumber(string $national): string
    {
        $length = strlen($national);

        if ($length <= 3) {
            return $national;
        }

        if ($length === 4) {
            return substr($national, 0, 2) . ' ' . substr($national, 2);
        }

        if ($length <= 6) {
            return substr($national, 0, 3) . ' ' . substr($national, 3);
        }

        return substr($national, 0, 3) . ' ' . substr($national, 3, 3) . ' ' . substr($national, 6);
    }

    private function detectCountry(string $digits): string
    {
        foreach (self::COUNTRY_CALLING_CODES as $country => $code) {
            if (str_starts_with($digits, $code)) {
                return $country;
            }
        }

        return 'US';
    }

    private function addCountryFlag(string $formatted, string $countryCode): string
    {
        $flag = $this->getCountryFlag($countryCode);
        return $flag . ' ' . $formatted;
    }

    private function getCountryFlag(string $countryCode): string
    {
        $flags = [
            'US' => '🇺🇸', 'CA' => '🇨🇦', 'GB' => '🇬🇧', 'AU' => '🇦🇺',
            'DE' => '🇩🇪', 'FR' => '🇫🇷', 'IT' => '🇮🇹', 'ES' => '🇪🇸',
            'NL' => '🇳🇱', 'BE' => '🇧🇪', 'CH' => '🇨🇭', 'AT' => '🇦🇹',
            'JP' => '🇯🇵', 'KR' => '🇰🇷', 'CN' => '🇨🇳', 'IN' => '🇮🇳',
        ];

        return $flags[strtoupper($countryCode)] ?? '🌐';
    }

    private function stripNonDigits(string $input): string
    {
        return preg_replace('/\D/', '', $input);
    }

    private function validateNotEmpty(string $input): void
    {
        if (empty($input)) {
            throw new PhoneFormattingException('Phone number cannot be empty');
        }
    }
}
