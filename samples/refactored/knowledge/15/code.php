<?php
declare(strict_types=1);

namespace App\Address\Policy;

final class PostalCodePolicy
{
    private const PATTERNS = [
        'US' => '/^\d{5}(-\d{4})?$/',
        'CA' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',
        'GB' => '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i',
        'UK' => '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i',
        'DE' => '/^\d{5}$/',
        'FR' => '/^\d{5}$/',
        'AU' => '/^\d{4}$/',
    ];

    private const FORMATS = [
        'US' => '#####(-####)',
        'CA' => 'A#A #A#A',
        'GB' => 'AA## #AA',
        'UK' => 'AA## #AA',
        'DE' => '#####',
        'FR' => '#####',
        'AU' => '####',
    ];

    public function __construct(
        public readonly array $supportedCountries = ['US', 'CA', 'GB', 'UK', 'DE', 'FR', 'AU'],
        public readonly array $customPatterns = [],
        public readonly array $customFormats = []
    ) {}

    public function getPattern(string $countryCode): ?string
    {
        return $this->customPatterns[$countryCode]
            ?? self::PATTERNS[$countryCode]
            ?? null;
    }

    public function getFormat(string $countryCode): string
    {
        return $this->customFormats[$countryCode]
            ?? self::FORMATS[$countryCode]
            ?? 'N/A';
    }

    public function validate(string $postalCode, string $countryCode): ValidationResult
    {
        $errors = [];

        if (empty($postalCode)) {
            return new ValidationResult(false, ['Postal code is required']);
        }

        if (!in_array($countryCode, $this->supportedCountries, true)) {
            return new ValidationResult(false, ["Country {$countryCode} is not supported"]);
        }

        $pattern = $this->getPattern($countryCode);

        if ($pattern !== null && !preg_match($pattern, $postalCode)) {
            $errors[] = "Invalid postal code format. Expected: {$this->getFormat($countryCode)}";
        }

        return new ValidationResult(
            isValid: empty($errors),
            errors: $errors
        );
    }

    public function normalize(string $postalCode, string $countryCode): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]/', '', $postalCode);

        if (in_array($countryCode, ['CA', 'GB', 'UK'], true)) {
            return strtoupper($normalized);
        }

        return $normalized;
    }
}
