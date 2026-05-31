<?php

declare(strict_types=1);

namespace App\Shared;

use App\Entity\AccountInterface;
use Psr\Log\LoggerInterface;

interface EntitlementStrategyInterface
{
    public function check(AccountInterface $account, string $entitlement): bool;
}

final class SubscriptionTierStrategy implements EntitlementStrategyInterface
{
    private array $tierEntitlements;
    private array $tierLimits;

    public function __construct(array $tierEntitlements, array $tierLimits)
    {
        $this->tierEntitlements = $tierEntitlements;
        $this->tierLimits = $tierLimits;
    }

    public function check(AccountInterface $account, string $entitlement): bool
    {
        $tier = $account->getSubscriptionTier();

        if ($account->isSuperAdmin() || $account->isBetaTester()) {
            return true;
        }

        if (in_array($entitlement, $this->tierEntitlements[$tier] ?? [], true)) {
            $limit = $this->tierLimits[$entitlement] ?? null;

            if ($limit !== null) {
                $usage = $account->getUsage($entitlement);
                return $usage < $limit;
            }

            return true;
        }

        return false;
    }
}

final class RoleBasedStrategy implements EntitlementStrategyInterface
{
    public function check(AccountInterface $account, string $entitlement): bool
    {
        if ($account->getStatus() !== 'active') {
            return false;
        }

        if ($account->isSuperAdmin()) {
            return true;
        }

        foreach ($account->getRoles() as $role) {
            if (in_array($entitlement, $role->getPermissions(), true)) {
                return true;
            }
        }

        return false;
    }
}

final class OverrideStrategy implements EntitlementStrategyInterface
{
    public function __construct(
        private readonly array $overrides,
    ) {}

    public function check(AccountInterface $account, string $entitlement): bool
    {
        $accountOverrides = $this->overrides[$account->getId()] ?? [];

        if (isset($accountOverrides[$entitlement])) {
            return $accountOverrides[$entitlement];
        }

        return true;
    }
}

final class EntitlementOrchestrator
{
    /** @var EntitlementStrategyInterface[] */
    private array $strategies = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerStrategy(EntitlementStrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    public function checkEntitlement(AccountInterface $account, string $entitlement): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->check($account, $entitlement)) {
                return true;
            }
        }

        return false;
    }
}
