<?php
declare(strict_types=1);

namespace Ecommerce\Core\Validation;

use Psr\Log\LoggerInterface;

final class PhoneNumberValidator
{
    private const MAX_LENGTH = 15;
    private const MIN_LENGTH = 10;

    private const COUNTRY_PATTERNS = [
        'US' => '/^1?([2-9]\d{9})$/',
        'CA' => '/^1?([2-9]\d{9})$/',
        'UK' => '/^44?([2-9]\d{9,10})$/',
        'AU' => '/^61?([2-9]\d{8})$/',
        'DE' => '/^49?([1-9]\d{10})$/',
        'FR' => '/^33?([1-9]\d{8})$/',
    ];

    public function __construct(
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function validate(string $phone, ?string $country = null): PhoneValidationResult
    {
        $digits = $this->extractDigits($phone);

        if (strlen($digits) < self::MIN_LENGTH) {
            return PhoneValidationResult::invalid('Phone number must have at least 10 digits');
        }

        if (strlen($digits) > self::MAX_LENGTH) {
            return PhoneValidationResult::invalid('Phone number cannot exceed 15 digits');
        }

        if (!preg_match('/^[2-9]/', $digits)) {
            return PhoneValidationResult::invalid('Phone number must start with a valid area code');
        }

        $normalized = $this->normalize($digits, $country);

        return PhoneValidationResult::valid($normalized);
    }

    public function normalize(string $phone, ?string $country = null): string
    {
        $digits = $this->extractDigits($phone);

        if ($country !== null && isset(self::COUNTRY_PATTERNS[$country])) {
            if (preg_match(self::COUNTRY_PATTERNS[$country], $digits, $matches)) {
                $countryCode = match ($country) {
                    'US', 'CA' => '1',
                    'UK' => '44',
                    'AU' => '61',
                    default => null
                };

                if ($countryCode !== null) {
                    return '+' . $countryCode . $matches[1];
                }
            }
        }

        return '+' . ltrim($digits, '+');
    }

    private function extractDigits(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}
