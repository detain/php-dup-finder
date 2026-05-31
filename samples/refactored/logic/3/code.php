<?php

declare(strict_types=1);

namespace App\Shared;

use App\Entity\User;
use Psr\Log\LoggerInterface;

interface SubscriptionTierRuleInterface
{
    public function validate(User $user, array $context = []): ?string;
    public function getErrorMessage(): string;
}

final class MinimumTierRule implements SubscriptionTierRuleInterface
{
    private array $allowedTiers;

    public function __construct(array $allowedTiers)
    {
        $this->allowedTiers = $allowedTiers;
    }

    public function validate(User $user, array $context = []): ?string
    {
        if (!in_array($user->getSubscriptionTier(), $this->allowedTiers, true)) {
            return "This action requires " . implode(' or ', $this->allowedTiers) . " subscription";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Subscription tier not sufficient';
    }
}

final class MonthlyLimitRule implements SubscriptionTierRuleInterface
{
    private string $counterField;
    private array $limits;

    public function __construct(string $counterField, array $limits)
    {
        $this->counterField = $counterField;
        $this->limits = $limits;
    }

    public function validate(User $user, array $context = []): ?string
    {
        $tier = $user->getSubscriptionTier();
        $currentCount = $user->{$this->counterField}();
        $limit = $this->limits[$tier] ?? PHP_INT_MAX;

        if ($currentCount >= $limit) {
            return "{$tier} users can perform this action up to {$limit} times per month";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Monthly limit exceeded';
    }
}

final class SubscriptionTierValidator
{
    /** @var SubscriptionTierRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(SubscriptionTierRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validate(User $user, array $context = []): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($user, $context);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
