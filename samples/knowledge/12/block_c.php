<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class TransactionLimitsConfig
{
    public const DEFAULT_DAILY_LIMIT = 10000.00;
    public const DEFAULT_MONTHLY_LIMIT = 100000.00;
    public const DEFAULT_SINGLE_TRANSACTION_LIMIT = 5000.00;

    public const PREMIUM_DAILY_LIMIT = 50000.00;
    public const PREMIUM_MONTHLY_LIMIT = 500000.00;

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getDailyLimit(string $accountType = 'standard'): float
    {
        $limits = $this->config['banking']['transfer_limits']['daily'] ?? [];

        if ($accountType === 'premium') {
            return $limits['premium'] ?? self::PREMIUM_DAILY_LIMIT;
        }

        return $limits['standard'] ?? self::DEFAULT_DAILY_LIMIT;
    }

    public function getMonthlyLimit(string $accountType = 'standard'): float
    {
        $limits = $this->config['banking']['transfer_limits']['monthly'] ?? [];

        if ($accountType === 'premium') {
            return $limits['premium'] ?? self::PREMIUM_MONTHLY_LIMIT;
        }

        return $limits['standard'] ?? self::DEFAULT_MONTHLY_LIMIT;
    }

    public function getSingleTransactionLimit(string $accountType = 'standard'): float
    {
        $limit = $this->config['banking']['transfer_limits']['single_transaction'] ?? [];

        return $limit[$accountType] ?? self::DEFAULT_SINGLE_TRANSACTION_LIMIT;
    }

    public function getAllLimits(string $accountType = 'standard'): array
    {
        return [
            'daily' => $this->getDailyLimit($accountType),
            'monthly' => $this->getMonthlyLimit($accountType),
            'single_transaction' => $this->getSingleTransactionLimit($accountType),
        ];
    }

    public function getValidationRules(): array
    {
        return [
            'min_amount' => 0.01,
            'max_single_transaction' => $this->getSingleTransactionLimit(),
            'max_daily_total' => $this->getDailyLimit(),
            'max_monthly_total' => $this->getMonthlyLimit(),
        ];
    }

    public function isWithinDailyLimit(string $accountId, float $amount): bool
    {
        $currentTotal = $this->getCurrentDailyTotal($accountId);
        $limit = $this->getDailyLimit($this->getAccountType($accountId));

        return ($currentTotal + $amount) <= $limit;
    }

    public function isWithinMonthlyLimit(string $accountId, float $amount): bool
    {
        $currentTotal = $this->getCurrentMonthlyTotal($accountId);
        $limit = $this->getMonthlyLimit($this->getAccountType($accountId));

        return ($currentTotal + $amount) <= $limit;
    }

    public function isWithinSingleTransactionLimit(float $amount): bool
    {
        return $amount <= $this->getSingleTransactionLimit();
    }

    private function getCurrentDailyTotal(string $accountId): float
    {
        return 0.0;
    }

    private function getCurrentMonthlyTotal(string $accountId): float
    {
        return 0.0;
    }

    private function getAccountType(string $accountId): string
    {
        return 'standard';
    }
}
