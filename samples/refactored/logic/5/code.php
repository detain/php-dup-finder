<?php

declare(strict_types=1);

namespace App\Shared;

use App\Entity\AccountInterface;
use Psr\Log\LoggerInterface;

interface AccountRuleInterface
{
    public function validate(AccountInterface $account, int $amount): ?string;
    public function getErrorMessage(): string;
}

final class PositiveAmountRule implements AccountRuleInterface
{
    public function validate(AccountInterface $account, int $amount): ?string
    {
        if ($amount <= 0) {
            return $this->getErrorMessage();
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Amount must be positive';
    }
}

final class AccountStatusRule implements AccountRuleInterface
{
    public function validate(AccountInterface $account, int $amount): ?string
    {
        if ($account->isLocked()) {
            return 'Account is locked';
        }

        if ($account->isSuspended()) {
            return 'Account is suspended';
        }

        if ($account->getStatus() !== 'active') {
            return 'Account must be active';
        }

        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Account status prevents operation';
    }
}

final class LargeTransactionVerificationRule implements AccountRuleInterface
{
    private int $threshold;

    public function __construct(int $threshold)
    {
        $this->threshold = $threshold;
    }

    public function validate(AccountInterface $account, int $amount): ?string
    {
        if ($amount > $this->threshold && !$account->isVerified()) {
            return "Transactions over {$this->threshold} require verified account";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Verification required for large transactions';
    }
}

final class RiskProfileLimitRule implements AccountRuleInterface
{
    private int $threshold;
    private int $maxRiskScore;

    public function __construct(int $threshold, int $maxRiskScore)
    {
        $this->threshold = $threshold;
        $this->maxRiskScore = $maxRiskScore;
    }

    public function validate(AccountInterface $account, int $amount): ?string
    {
        if ($amount > $this->threshold && $account->getRiskScore() > $this->maxRiskScore) {
            return 'High-risk accounts have reduced limits';
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Risk profile limit exceeded';
    }
}

final class AccountOperationValidator
{
    /** @var AccountRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(AccountRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validate(AccountInterface $account, int $amount): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($account, $amount);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
