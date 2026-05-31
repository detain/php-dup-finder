<?php

declare(strict_types=1);

namespace App\Commerce\Payments;

use App\Exceptions\TransactionValidationException;

final class CardNumberAnalyzer
{
    private const NETWORK_SIGNATURES = [
        'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        'mastercard' => '/^5[1-5][0-9]{14}$/',
        'amex' => '/^3[47][0-9]{13}$/',
        'discover' => '/^6(?:011|5[0-9]{2})[0-9]{12}$/',
        'diners' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
        'jcb' => '/^(?:2131|1800|35\d{3})\d{11}$/',
        'unionpay' => '/^62[0-9]{14,17}$/',
    ];

    private const SECURITY_CODE_LENGTHS = [
        'visa' => 3,
        'mastercard' => 3,
        'amex' => 4,
        'discover' => 3,
        'diners' => 3,
        'jcb' => 3,
        'unionpay' => 3,
    ];

    private const PAN_LENGTHS_BY_NETWORK = [
        'visa' => [13, 16],
        'mastercard' => [16],
        'amex' => [15],
        'discover' => [16],
        'diners' => [14],
        'jcb' => [15, 16],
        'unionpay' => [16, 17, 18, 19],
    ];

    public function analyze(string $rawPan): void
    {
        $pan = $this->sanitize($rawPan);

        $this->requireNonEmpty($pan);
        $this->requireDigitsOnly($pan);
        $this->requireValidPanLength($pan);
        $this->requirePassesLuhn($pan);
    }

    public function analyzeWithNetwork(string $rawPan, string $network): void
    {
        $pan = $this->sanitize($rawPan);

        $this->requireNonEmpty($pan);
        $this->requireDigitsOnly($pan);
        $this->requirePanLengthForNetwork($pan, $network);
        $this->requireNetworkConsistency($pan, $network);
        $this->requirePassesLuhn($pan);
    }

    public function analyzeExpiry(int $month, int $year): void
    {
        $this->requireValidMonth($month);
        $this->requireValidYear($year);
        $this->requireNotExpired($month, $year);
    }

    public function analyzeCvc(string $cvc, string $network): void
    {
        $this->requireNonEmpty($cvc);
        $this->requireDigitsOnly($cvc);
        $this->requireCvcLengthForNetwork($cvc, $network);
    }

    public function analyzeFull(string $pan, int $expMonth, int $expYear, string $cvc, string $network): void
    {
        $this->analyzeWithNetwork($pan, $network);
        $this->analyzeExpiry($expMonth, $expYear);
        $this->analyzeCvc($cvc, $network);
    }

    public function determineNetwork(string $pan): ?string
    {
        $sanitized = $this->sanitize($pan);

        foreach (self::NETWORK_SIGNATURES as $name => $signature) {
            if (preg_match($signature, $sanitized)) {
                return $name;
            }
        }

        return null;
    }

    public function formatPan(string $pan, string $network): string
    {
        $sanitized = $this->sanitize($pan);

        return match ($network) {
            'amex' => preg_replace('/(\d{4})(\d{6})(\d{5})/', '$1 $2 $3', $sanitized),
            default => preg_replace('/(\d{4})(?=\d)/', '$1 ', $sanitized),
        };
    }

    public function anonymizePan(string $pan): string
    {
        $sanitized = $this->sanitize($pan);
        $length = strlen($sanitized);

        $lastFour = substr($sanitized, -4);
        $maskedPortion = str_repeat('X', max(0, $length - 4));

        return $maskedPortion . $lastFour;
    }

    private function sanitize(string $input): string
    {
        return preg_replace('/[^0-9]/', '', $input);
    }

    private function requireNonEmpty(string $input): void
    {
        if (empty($input)) {
            throw new TransactionValidationException('Field cannot be empty');
        }
    }

    private function requireDigitsOnly(string $input): void
    {
        if (!ctype_digit($input)) {
            throw new TransactionValidationException('Field must contain only numeric digits');
        }
    }

    private function requireValidPanLength(string $pan): void
    {
        $length = strlen($pan);
        $acceptableLengths = array_merge(...array_values(self::PAN_LENGTHS_BY_NETWORK));

        if (!in_array($length, $acceptableLengths, true)) {
            throw new TransactionValidationException("Invalid primary account number length: {$length}");
        }
    }

    private function requirePanLengthForNetwork(string $pan, string $network): void
    {
        $length = strlen($pan);
        $expectedLengths = self::PAN_LENGTHS_BY_NETWORK[$network] ?? [];

        if (!in_array($length, $expectedLengths, true)) {
            throw new TransactionValidationException(
                "PAN length {$length} is invalid for network {$network}"
            );
        }
    }

    private function requireNetworkConsistency(string $pan, string $network): void
    {
        $actualNetwork = $this->determineNetwork($pan);

        if ($actualNetwork !== $network) {
            throw new TransactionValidationException(
                "Network mismatch: expected {$network}, detected {$actualNetwork}"
            );
        }
    }

    private function requirePassesLuhn(string $pan): void
    {
        if (!$this->luhnCheck($pan)) {
            throw new TransactionValidationException('Primary account number failed Luhn validation');
        }
    }

    private function requireValidMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new TransactionValidationException("Invalid expiration month: {$month}");
        }
    }

    private function requireValidYear(int $year): void
    {
        $currentYear = (int) date('Y');

        if ($year < $currentYear || $year > $currentYear + 20) {
            throw new TransactionValidationException("Invalid expiration year: {$year}");
        }
    }

    private function requireNotExpired(int $month, int $year): void
    {
        $expiration = new \DateTime("{$year}-{$month}-01");
        $expiration->modify('last day of this month');

        if ($expiration < new \DateTime('today')) {
            throw new TransactionValidationException('Card expiration date has passed');
        }
    }

    private function requireCvcLengthForNetwork(string $cvc, string $network): void
    {
        $expectedLength = self::SECURITY_CODE_LENGTHS[$network] ?? 3;

        if (strlen($cvc) !== $expectedLength) {
            throw new TransactionValidationException(
                "CVC length {$expectedLength} required for {$network}, got " . strlen($cvc)
            );
        }
    }

    private function luhnCheck(string $pan): bool
    {
        $total = 0;
        $digits = strlen($pan);
        $isEvenPosition = $digits % 2 === 0;

        for ($i = 0; $i < $digits; $i++) {
            $digit = (int) $pan[$i];
            $isEvenIndex = ($i % 2 === 1) === $isEvenPosition;

            if ($isEvenIndex) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $total += $digit;
        }

        return $total % 10 === 0;
    }
}
