<?php

declare(strict_types=1);

namespace App\Billing\Processing;

use App\Exceptions\PaymentProcessingException;

final class PaymentCardProcessor
{
    private const ACCEPTED_CARD_NETWORKS = [
        'visa' => '/^4\d{12}(\d{3})?$/',
        'mastercard' => '/^5[1-5]\d{14}$/',
        'american_express' => '/^3[47]\d{13}$/',
        'discover' => '/^6(?:011|5\d{2})\d{12}$/',
        'diners_club' => '/^3(?:0[0-5]|\d)\d{11}$/',
        'jcb' => '/^(?:2131|1800|35\d{2})\d{12}$/',
        'union_pay' => '/^62\d{14,17}$/',
    ];

    private const CVV_REQUIREMENTS = [
        'visa' => 3,
        'mastercard' => 3,
        'american_express' => 4,
        'discover' => 3,
        'diners_club' => 3,
        'jcb' => 3,
        'union_pay' => 3,
    ];

    private const NUMBER_LENGTH_REQUIREMENTS = [
        'visa' => [13, 16],
        'mastercard' => [16],
        'american_express' => [15],
        'discover' => [16],
        'diners_club' => [14],
        'jcb' => [15, 16],
        'union_pay' => [16, 17, 18, 19],
    ];

    public function processCardNumber(string $rawNumber): void
    {
        $sanitized = $this->stripNonNumeric($rawNumber);

        $this->checkNotBlank($sanitized);
        $this->checkAllDigits($sanitized);
        $this->checkCorrectLength($sanitized);
        $this->checkLuhnChecksum($sanitized);
    }

    public function processCardWithNetwork(string $rawNumber, string $network): void
    {
        $sanitized = $this->stripNonNumeric($rawNumber);

        $this->checkNotBlank($sanitized);
        $this->checkAllDigits($sanitized);
        $this->checkLengthForNetwork($sanitized, $network);
        $this->checkNetworkMatch($sanitized, $network);
        $this->checkLuhnChecksum($sanitized);
    }

    public function processExpiration(int $month, int $year): void
    {
        $this->checkValidMonth($month);
        $this->checkValidYear($year);
        $this->checkNotPreviouslyExpired($month, $year);
    }

    public function processSecurityCode(string $code, string $network): void
    {
        $this->checkNotBlank($code);
        $this->checkAllDigits($code);
        $this->checkLengthForNetworkCode($code, $network);
    }

    public function processFullPaymentMethod(string $number, int $expMonth, int $expYear, string $cvv, string $network): void
    {
        $this->processCardWithNetwork($number, $network);
        $this->processExpiration($expMonth, $expYear);
        $this->processSecurityCode($cvv, $network);
    }

    public function identifyNetwork(string $number): ?string
    {
        $sanitized = $this->stripNonNumeric($number);

        foreach (self::ACCEPTED_CARD_NETWORKS as $network => $pattern) {
            if (preg_match($pattern, $sanitized)) {
                return $network;
            }
        }

        return null;
    }

    public function formatNumber(string $number, string $network): string
    {
        $sanitized = $this->stripNonNumeric($number);

        return match ($network) {
            'american_express' => preg_replace('/(\d{4})(\d{6})(\d{5})/', '$1 $2 $3', $sanitized),
            default => preg_replace('/(\d{4})(?=\d)/', '$1 ', $sanitized),
        };
    }

    public function obscureNumber(string $number): string
    {
        $sanitized = $this->stripNonNumeric($number);
        $length = strlen($sanitized);

        $visible = substr($sanitized, -4);
        $masked = str_repeat('*', $length - 4);

        return $masked . $visible;
    }

    private function stripNonNumeric(string $input): string
    {
        return preg_replace('/\D/', '', $input);
    }

    private function checkNotBlank(string $input): void
    {
        if (strlen(trim($input)) === 0) {
            throw new PaymentProcessingException('Input cannot be empty');
        }
    }

    private function checkAllDigits(string $input): void
    {
        if (!ctype_digit($input)) {
            throw new PaymentProcessingException('Input must contain only digits');
        }
    }

    private function checkCorrectLength(string $number): void
    {
        $length = strlen($number);
        $validLengths = array_merge(...array_values(self::NUMBER_LENGTH_REQUIREMENTS));

        if (!in_array($length, $validLengths, true)) {
            throw new PaymentProcessingException("Length {$length} is not valid for any known card type");
        }
    }

    private function checkLengthForNetwork(string $number, string $network): void
    {
        $length = strlen($number);
        $expectedLengths = self::NUMBER_LENGTH_REQUIREMENTS[$network] ?? [];

        if (!in_array($length, $expectedLengths, true)) {
            throw new PaymentProcessingException(
                "Length {$length} is not valid for {$network}"
            );
        }
    }

    private function checkNetworkMatch(string $number, string $network): void
    {
        $identified = $this->identifyNetwork($number);

        if ($identified !== $network) {
            throw new PaymentProcessingException(
                "Network mismatch: provided {$network}, detected " . ($identified ?? 'unknown')
            );
        }
    }

    private function checkLuhnChecksum(string $number): void
    {
        if (!$this->isValidLuhn($number)) {
            throw new PaymentProcessingException('Card number failed checksum validation');
        }
    }

    private function checkValidMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new PaymentProcessingException("Invalid month value: {$month}");
        }
    }

    private function checkValidYear(int $year): void
    {
        $currentYear = (int) date('Y');

        if ($year < $currentYear || $year > $currentYear + 20) {
            throw new PaymentProcessingException("Invalid year value: {$year}");
        }
    }

    private function checkNotPreviouslyExpired(int $month, int $year): void
    {
        $expiryDate = new \DateTime("{$year}-{$month}-01");
        $expiryDate->modify('end of month');

        if ($expiryDate < new \DateTime()) {
            throw new PaymentProcessingException('Card expiration date is in the past');
        }
    }

    private function checkLengthForNetworkCode(string $code, string $network): void
    {
        $expected = self::CVV_REQUIREMENTS[$network] ?? 3;

        if (strlen($code) !== $expected) {
            throw new PaymentProcessingException(
                "CVV for {$network} must be {$expected} digits, got " . strlen($code)
            );
        }
    }

    private function isValidLuhn(string $number): bool
    {
        $sum = 0;
        $numLength = strlen($number);
        $parity = $numLength % 2;

        for ($position = 0; $position < $numLength; $position++) {
            $digit = (int) $number[$position];

            if ($position % 2 === $parity) {
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
