<?php

declare(strict_types=1);

namespace App\Services\Phone;

final class CountryPhoneSpec
{
    public readonly string $callingCode;
    public readonly int $nationalLength;
    public readonly string $formatTemplate;

    public function __construct(string $callingCode, int $nationalLength, string $formatTemplate = null)
    {
        $this->callingCode = $callingCode;
        $this->nationalLength = $nationalLength;
        $this->formatTemplate = $formatTemplate ?? 'default';
    }
}

final class PhoneCatalog
{
    private static function getSpecs(): array
    {
        return [
            'US' => new CountryPhoneSpec('1', 10, '(###) ###-####'),
            'GB' => new CountryPhoneSpec('44', 10, '#### ### ###'),
            'DE' => new CountryPhoneSpec('49', 10, '### ### ###'),
            'FR' => new CountryPhoneSpec('33', 9, '## ## ## ## ##'),
            'JP' => new CountryPhoneSpec('81', 10, '###-####-####'),
        ];
    }

    public static function get(string $country): CountryPhoneSpec
    {
        $specs = self::getSpecs();
        return $specs[strtoupper($country)] ?? throw new \InvalidArgumentException(
            "Unsupported country: {$country}"
        );
    }
}

final class PhoneFormatterService
{
    public function format(string $number, string $country): string
    {
        $spec = PhoneCatalog::get($country);
        $clean = preg_replace('/\D/', '', $number);
        $national = $this->extractNational($clean, $spec->callingCode);

        if (strlen($national) !== $spec->nationalLength) {
            throw new \InvalidArgumentException(
                "Invalid length for {$country}: expected {$spec->nationalLength}, got " . strlen($national)
            );
        }

        return $this->applyFormat($national, $spec->formatTemplate);
    }

    public function formatInternational(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number);
        $country = $this->detectCountry($clean);
        $spec = PhoneCatalog::get($country);

        $national = $this->extractNational($clean, $spec->callingCode);

        return '+' . $spec->callingCode . ' ' . $this->applyFormat($national, $spec->formatTemplate);
    }

    private function extractNational(string $digits, string $code): string
    {
        if (str_starts_with($digits, $code)) {
            return ltrim(substr($digits, strlen($code)), '0');
        }
        return $digits;
    }

    private function detectCountry(string $digits): string
    {
        foreach (PhoneCatalog::getSupportedCountries() as $country) {
            $spec = PhoneCatalog::get($country);
            if (str_starts_with($digits, $spec->callingCode)) {
                return $country;
            }
        }
        return 'US';
    }

    private function applyFormat(string $national, string $template): string
    {
        $result = '';
        $digitIndex = 0;

        for ($i = 0; $i < strlen($template); $i++) {
            if ($template[$i] === '#') {
                $result .= $national[$digitIndex++] ?? '';
            } else {
                $result .= $template[$i];
            }
        }

        return $result;
    }
}
