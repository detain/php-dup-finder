<?php
declare(strict_types=1);

namespace App\Banking\Policy;

final class TransactionLimits
{
    public function __construct(
        public readonly float $dailyLimit,
        public readonly float $monthlyLimit,
        public readonly float $singleTransactionLimit,
        public readonly float $minAmount = 0.01
    ) {}

    public static function standard(): self
    {
        return new self(
            dailyLimit: 10000.00,
            monthlyLimit: 100000.00,
            singleTransactionLimit: 5000.00
        );
    }

    public static function premium(): self
    {
        return new self(
            dailyLimit: 50000.00,
            monthlyLimit: 500000.00,
            singleTransactionLimit: 25000.00
        );
    }

    public static function fromAccountType(string $accountType): self
    {
        return match ($accountType) {
            'premium' => self::premium(),
            default => self::standard()
        };
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            dailyLimit: $config['daily_limit'] ?? 10000.00,
            monthlyLimit: $config['monthly_limit'] ?? 100000.00,
            singleTransactionLimit: $config['single_transaction_limit'] ?? 5000.00,
            minAmount: $config['min_amount'] ?? 0.01
        );
    }

    public function validateTransaction(float $amount): TransactionValidationResult
    {
        $errors = [];

        if ($amount < $this->minAmount) {
            $errors[] = "Amount must be at least {$this->minAmount}";
        }

        if ($amount > $this->singleTransactionLimit) {
            $errors[] = "Single transaction cannot exceed {$this->singleTransactionLimit}";
        }

        return new TransactionValidationResult(
            isValid: empty($errors),
            errors: $errors
        );
    }

    public function canAccommodateDaily(float $currentDailyTotal, float $amount): bool
    {
        return ($currentDailyTotal + $amount) <= $this->dailyLimit;
    }

    public function canAccommodateMonthly(float $currentMonthlyTotal, float $amount): bool
    {
        return ($currentMonthlyTotal + $amount) <= $this->monthlyLimit;
    }
}
