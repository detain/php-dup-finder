<?php

declare(strict_types=1);

namespace App\Payments\Validation;

use App\Exceptions\CardValidationException;

final class CreditCardValidator
{
    private const CARD_TYPE_PATTERNS = [
        'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'mastercard' => '/^5[1-5][0-9]{14}$/',
        'amex' => '/^3[47][0-9]{13}$/',
        'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        'unionpay' => '/^62[0-9]{14,17}$/',
    ];

    private const CVV_LENGTHS = [
        'visa' => 3,
        'mastercard' => 3,
        'amex' => 4,
        'discover' => 3,
        'diners' => 3,
        'jcb' => 3,
        'unionpay' => 3,
    ];

    private const CARD_NUMBER_LENGTHS = [
        'visa' => [13, 16],
        'mastercard' => [16],
        'amex' => [15],
        'discover' => [16],
        'diners' => [14],
        'jcb' => [15, 16],
        'unionpay' => [16, 17, 18, 19],
    ];

    public function validateCardNumber(string $number): void
    {
        $cleanNumber = $this->removeNonDigits($number);

        $this->ensureNotEmpty($cleanNumber);
        $this->ensureNumericOnly($cleanNumber);
        $this->ensureValidLength($cleanNumber);
        $this->ensurePassesLuhnCheck($cleanNumber);
    }

    public function validateCardWithType(string $number, string $expectedType): void
    {
        $cleanNumber = $this->removeNonDigits($number);

        $this->ensureNotEmpty($cleanNumber);
        $this->ensureNumericOnly($cleanNumber);
        $this->ensureValidLengthForType($cleanNumber, $expectedType);
        $this->ensureCardTypeMatches($cleanNumber, $expectedType);
        $this->ensurePassesLuhnCheck($cleanNumber);
    }

    public function validateExpiryDate(int $month, int $year): void
    {
        $this->ensureValidMonth($month);
        $this->ensureValidYear($year);
        $this->ensureNotExpired($month, $year);
    }

    public function validateCvv(string $cvv, string $cardType): void
    {
        $this->ensureNotEmpty($cvv);
        $this->ensureNumericOnly($cvv);
        $this->ensureValidLengthForCardType($cvv, $cardType);
    }

    public function validateCompleteCard(string $number, int $expiryMonth, int $expiryYear, string $cvv, string $cardType): void
    {
        $this->validateCardWithType($number, $cardType);
        $this->validateExpiryDate($expiryMonth, $expiryYear);
        $this->validateCvv($cvv, $cardType);
    }

    public function detectCardType(string $number): ?string
    {
        $cleanNumber = $this->removeNonDigits($number);

        foreach (self::CARD_TYPE_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $cleanNumber)) {
                return $type;
            }
        }

        return null;
    }

    public function formatCardNumber(string $number, string $cardType): string
    {
        $cleanNumber = $this->removeNonDigits($number);

        return match ($cardType) {
            'amex' => preg_replace('/(\d{4})(\d{6})(\d{5})/', '$1 $2 $3', $cleanNumber),
            default => preg_replace('/(\d{4})(?=\d)/', '$1 ', $cleanNumber),
        };
    }

    public function maskCardNumber(string $number): string
    {
        $cleanNumber = $this->removeNonDigits($number);
        $length = strlen($cleanNumber);

        if ($length < 4) {
            return str_repeat('*', $length);
        }

        $lastFour = substr($cleanNumber, -4);
        $masked = str_repeat('*', $length - 4);

        return $masked . $lastFour;
    }

    private function removeNonDigits(string $input): string
    {
        return preg_replace('/\D/', '', $input);
    }

    private function ensureNotEmpty(string $input): void
    {
        if (empty($input)) {
            throw new CardValidationException('Card number cannot be empty');
        }
    }

    private function ensureNumericOnly(string $input): void
    {
        if (!ctype_digit($input)) {
            throw new CardValidationException('Card number must contain only digits');
        }
    }

    private function ensureValidLength(string $number): void
    {
        $length = strlen($number);
        $validLengths = array_merge(...array_values(self::CARD_NUMBER_LENGTHS));

        if (!in_array($length, $validLengths, true)) {
            throw new CardValidationException("Invalid card number length: {$length}");
        }
    }

    private function ensureValidLengthForType(string $number, string $type): void
    {
        $length = strlen($number);
        $validLengths = self::CARD_NUMBER_LENGTHS[$type] ?? [];

        if (!in_array($length, $validLengths, true)) {
            throw new CardValidationException(
                "Invalid length {$length} for card type {$type}. Expected: " . implode(', ', $validLengths)
            );
        }
    }

    private function ensurePassesLuhnCheck(string $number): void
    {
        if (!$this->passesLuhn($number)) {
            throw new CardValidationException('Card number failed Luhn checksum validation');
        }
    }

    private function ensureCardTypeMatches(string $number, string $expectedType): void
    {
        $detectedType = $this->detectCardType($number);

        if ($detectedType !== $expectedType) {
            throw new CardValidationException(
                "Card type mismatch. Expected {$expectedType}, detected {$detectedType}"
            );
        }
    }

    private function ensureValidMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new CardValidationException('Invalid expiry month: ' . $month);
        }
    }

    private function ensureValidYear(int $year): void
    {
        $currentYear = (int) date('Y');

        if ($year < $currentYear || $year > $currentYear + 20) {
            throw new CardValidationException('Invalid expiry year: ' . $year);
        }
    }

    private function ensureNotExpired(int $month, int $year): void
    {
        $now = new \DateTime();
        $expiry = new \DateTime("{$year}-{$month}-01");
        $expiry->modify('last day of this month');

        if ($expiry < $now) {
            throw new CardValidationException('Card has expired');
        }
    }

    private function ensureValidLengthForCardType(string $cvv, string $cardType): void
    {
        $expectedLength = self::CVV_LENGTHS[$cardType] ?? 3;

        if (strlen($cvv) !== $expectedLength) {
            throw new CardValidationException(
                "Invalid CVV length for {$cardType}. Expected {$expectedLength}, got " . strlen($cvv)
            );
        }
    }

    private function passesLuhn(string $number): bool
    {
        $sum = 0;
        $length = strlen($number);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
