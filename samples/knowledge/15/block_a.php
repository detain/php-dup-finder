<?php
declare(strict_types=1);

namespace App\Shipping\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PostalCodeValidator extends ConstraintValidator
{
    private const US_PATTERN = '/^\d{5}(-\d{4})?$/';
    private const CA_PATTERN = '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i';
    private const UK_PATTERN = '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i';
    private const DE_PATTERN = '/^\d{5}$/';
    private const FR_PATTERN = '/^\d{5}$/';
    private const AU_PATTERN = '/^\d{4}$/';

    public const MAX_LENGTH = 10;
    public const MIN_LENGTH = 4;

    public function validate($value, Constraint $constraint): void
    {
        if ($value === null || $value === '') {
            $this->context->buildViolation('Postal code is required')
                ->addViolation();
            return;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $this->context->buildViolation('Postal code cannot exceed {{ limit }} characters')
                ->setParameter('{{ limit }}', (string) self::MAX_LENGTH)
                ->addViolation();
        }

        if (strlen($value) < self::MIN_LENGTH) {
            $this->context->buildViolation('Postal code must be at least {{ limit }} characters')
                ->setParameter('{{ limit }}', (string) self::MIN_LENGTH)
                ->addViolation();
        }

        $countryCode = $this->getCountryCodeFromContext();

        if (!$this->isValidFormat($value, $countryCode)) {
            $this->context->buildViolation(
                'Invalid postal code format for country {{ country }}'
            )
                ->setParameter('{{ country }}', $countryCode)
                ->addViolation();
        }
    }

    public function isValidFormat(string $postalCode, string $countryCode): bool
    {
        return match (strtoupper($countryCode)) {
            'US' => (bool) preg_match(self::US_PATTERN, $postalCode),
            'CA' => (bool) preg_match(self::CA_PATTERN, $postalCode),
            'GB', 'UK' => (bool) preg_match(self::UK_PATTERN, $postalCode),
            'DE' => (bool) preg_match(self::DE_PATTERN, $postalCode),
            'FR' => (bool) preg_match(self::FR_PATTERN, $postalCode),
            'AU' => (bool) preg_match(self::AU_PATTERN, $postalCode),
            default => true,
        };
    }

    public function formatPostalCode(string $postalCode, string $countryCode): string
    {
        $postalCode = strtoupper(trim($postalCode));

        return match (strtoupper($countryCode)) {
            'US' => $this->formatUsPostalCode($postalCode),
            'CA' => $this->formatCaPostalCode($postalCode),
            'GB', 'UK' => $this->formatUkPostalCode($postalCode),
            default => $postalCode,
        };
    }

    private function formatUsPostalCode(string $code): string
    {
        $code = preg_replace('/[^0-9]/', '', $code);

        if (strlen($code) === 9) {
            return substr($code, 0, 5) . '-' . substr($code, 5, 4);
        }

        return substr($code, 0, 5);
    }

    private function formatCaPostalCode(string $code): string
    {
        $code = preg_replace('/[^A-Z0-9]/i', '', $code);
        $code = strtoupper($code);

        if (strlen($code) === 6) {
            return substr($code, 0, 3) . ' ' . substr($code, 3, 3);
        }

        return $code;
    }

    private function formatUkPostalCode(string $code): string
    {
        $code = preg_replace('/[^A-Z0-9]/i', '', $code);
        $code = strtoupper($code);

        if (strlen($code) >= 5) {
            return substr($code, 0, -3) . ' ' . substr($code, -3);
        }

        return $code;
    }

    private function getCountryCodeFromContext(): string
    {
        return $this->context->getObject()?->getCountryCode() ?? 'US';
    }
}
