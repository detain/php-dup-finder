<?php

declare(strict_types=1);

namespace App\Entitlements;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Service\EntitlementStore;
use Psr\Log\LoggerInterface;

final class EntitlementService
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly EntitlementStore $entitlementStore,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEntitled(int $accountId, string $entitlement): bool
    {
        $account = $this->accountRepository->findById($accountId);

        if ($account === null) {
            return false;
        }

        if (!$account->isActive()) {
            return false;
        }

        if ($account->getAccountType() === 'unlimited') {
            return true;
        }

        $entitlements = $this->entitlementStore->getAccountEntitlements($accountId);

        if (in_array($entitlement, $entitlements, true)) {
            return true;
        }

        $accountEntitlements = $account->getEntitlements();

        if (in_array($entitlement, $accountEntitlements, true)) {
            return true;
        }

        $limit = $this->getEntitlementLimit($accountId, $entitlement);

        if ($limit !== null) {
            $usage = $this->getEntitlementUsage($accountId, $entitlement);

            if ($usage >= $limit) {
                return false;
            }
        }

        return false;
    }

    public function checkUsageLimit(int $accountId, string $entitlement): bool
    {
        $account = $this->accountRepository->findById($accountId);

        if ($account === null || !$account->isActive()) {
            return false;
        }

        $limit = $this->getEntitlementLimit($accountId, $entitlement);

        if ($limit === null) {
            return true;
        }

        $usage = $this->getEntitlementUsage($accountId, $entitlement);

        return $usage < $limit;
    }

    public function consumeEntitlement(int $accountId, string $entitlement): void
    {
        if (!$this->isEntitled($accountId, $entitlement)) {
            throw new \InvalidArgumentException("Account not entitled to: {$entitlement}");
        }

        $limit = $this->getEntitlementLimit($accountId, $entitlement);

        if ($limit !== null) {
            $usage = $this->getEntitlementUsage($accountId, $entitlement);

            if ($usage >= $limit) {
                throw new \InvalidArgumentException("Entitlement limit reached: {$entitlement}");
            }

            $this->entitlementStore->incrementUsage($accountId, $entitlement);
        }
    }

    public function getRemainingUsage(int $accountId, string $entitlement): ?int
    {
        $limit = $this->getEntitlementLimit($accountId, $entitlement);

        if ($limit === null) {
            return null;
        }

        $usage = $this->getEntitlementUsage($accountId, $entitlement);

        return $limit - $usage;
    }

    private function getEntitlementLimit(int $accountId, string $entitlement): ?int
    {
        $limits = [
            'api_calls' => 1000,
            'data_exports' => 10,
            'team_members' => 5,
            'projects' => 3,
            'storage_gb' => 10,
        ];

        return $limits[$entitlement] ?? null;
    }

    private function getEntitlementUsage(int $accountId, string $entitlement): int
    {
        return $this->entitlementStore->getUsage($accountId, $entitlement);
    }
}
