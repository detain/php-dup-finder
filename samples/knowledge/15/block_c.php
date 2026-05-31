<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class AddressConfigLoader
{
    public const SUPPORTED_COUNTRIES_KEY = 'address_validation.supported_countries';
    public const POSTAL_CODE_PATTERNS_KEY = 'address_validation.postal_code_patterns';
    public const POSTAL_CODE_FORMATS_KEY = 'address_validation.postal_code_formats';
    public const DEFAULT_COUNTRY = 'US';

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getSupportedCountries(): array
    {
        return $this->config[self::SUPPORTED_COUNTRIES_KEY] ?? [
            'US', 'CA', 'GB', 'UK', 'DE', 'FR', 'AU'
        ];
    }

    public function getPostalCodePattern(string $countryCode): ?string
    {
        $patterns = $this->config[self::POSTAL_CODE_PATTERNS_KEY] ?? [];

        $defaultPatterns = [
            'US' => '/^\d{5}(-\d{4})?$/',
            'CA' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',
            'GB' => '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i',
            'UK' => '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i',
            'DE' => '/^\d{5}$/',
            'FR' => '/^\d{5}$/',
            'AU' => '/^\d{4}$/',
        ];

        return $patterns[$countryCode] ?? $defaultPatterns[$countryCode] ?? null;
    }

    public function getPostalCodeFormat(string $countryCode): string
    {
        $formats = $this->config[self::POSTAL_CODE_FORMATS_KEY] ?? [];

        $defaultFormats = [
            'US' => '#####(-####)',
            'CA' => 'A#A #A#A',
            'GB' => 'AA## #AA',
            'UK' => 'AA## #AA',
            'DE' => '#####',
            'FR' => '#####',
            'AU' => '####',
        ];

        return $formats[$countryCode] ?? $defaultFormats[$countryCode] ?? 'N/A';
    }

    public function getPostalCodeRules(): array
    {
        $countries = $this->getSupportedCountries();
        $rules = [];

        foreach ($countries as $country) {
            $rules[$country] = [
                'pattern' => $this->getPostalCodePattern($country),
                'format' => $this->getPostalCodeFormat($country),
                'normalization' => $this->getNormalizationRules($country),
            ];
        }

        return $rules;
    }

    public function validatePostalCode(string $postalCode, string $countryCode): ValidationResult
    {
        $errors = [];

        if (empty($postalCode)) {
            return new ValidationResult(false, ['Postal code is required']);
        }

        if (!$this->isCountrySupported($countryCode)) {
            return new ValidationResult(false, ["Country {$countryCode} is not supported"]);
        }

        $pattern = $this->getPostalCodePattern($countryCode);

        if ($pattern !== null && !preg_match($pattern, $postalCode)) {
            $format = $this->getPostalCodeFormat($countryCode);
            $errors[] = "Invalid postal code format. Expected: {$format}";
        }

        return new ValidationResult(
            valid: empty($errors),
            errors: $errors
        );
    }

    public function isCountrySupported(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->getSupportedCountries(), true);
    }

    private function getNormalizationRules(string $countryCode): array
    {
        $defaultRules = [
            'US' => ['remove_chars' => '[^0-9]', 'uppercase' => false],
            'CA' => ['remove_chars' => '[^A-Z0-9]', 'uppercase' => true],
            'GB' => ['remove_chars' => '[^A-Z0-9]', 'uppercase' => true],
            'UK' => ['remove_chars' => '[^A-Z0-9]', 'uppercase' => true],
            'DE' => ['remove_chars' => '[^0-9]', 'uppercase' => false],
            'FR' => ['remove_chars' => '[^0-9]', 'uppercase' => false],
            'AU' => ['remove_chars' => '[^0-9]', 'uppercase' => false],
        ];

        return $defaultRules[$countryCode] ?? ['remove_chars' => '', 'uppercase' => false];
    }

    public function normalizePostalCode(string $postalCode, string $countryCode): string
    {
        $rules = $this->getNormalizationRules($countryCode);

        $normalized = preg_replace('/' . $rules['remove_chars'] . '/', '', $postalCode);

        if ($rules['uppercase']) {
            $normalized = strtoupper($normalized);
        }

        return $normalized;
    }
}
