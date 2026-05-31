<?php
declare(strict_types=1);

namespace App\Billing\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class SubscriptionPermissionService
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private LoggerInterface $logger,
    ) {}

    public function canCancelSubscription(User $user, string $subscriptionId): bool
    {
        if ($user === null) {
            $this->logger->warning('Cancel subscription permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Cancel subscription permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'subscription_id' => $subscriptionId,
            ]);
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($subscriptionId);
        if ($subscription === null) {
            $this->logger->info('Cancel subscription permission denied: subscription not found', [
                'subscription_id' => $subscriptionId,
            ]);
            return false;
        }

        if ($subscription->getCustomerId()->equals($user->getId())) {
            $this->logger->debug('Cancel subscription permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'subscription_id' => $subscriptionId,
            ]);
            return true;
        }

        if ($this->userHasElevatedBillingRole($user)) {
            $this->logger->debug('Cancel subscription permission granted: elevated role', [
                'user_id' => $user->getId()->toString(),
                'subscription_id' => $subscriptionId,
            ]);
            return true;
        }

        $this->logger->info('Cancel subscription permission denied: access denied', [
            'user_id' => $user->getId()->toString(),
            'subscription_id' => $subscriptionId,
        ]);

        return false;
    }

    public function canChangePlan(User $user, string $subscriptionId): bool
    {
        if ($user === null) {
            $this->logger->warning('Change plan permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Change plan permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'subscription_id' => $subscriptionId,
            ]);
            return false;
        }

        $subscription = $this->subscriptionRepository->findById($subscriptionId);
        if ($subscription === null) {
            $this->logger->info('Change plan permission denied: subscription not found', [
                'subscription_id' => $subscriptionId,
            ]);
            return false;
        }

        if ($subscription->getCustomerId()->equals($user->getId())) {
            $this->logger->debug('Change plan permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'subscription_id' => $subscriptionId,
            ]);
            return true;
        }

        if ($this->userHasElevatedBillingRole($user)) {
            $this->logger->debug('Change plan permission granted: elevated role', [
                'user_id' => $user->getId()->toString(),
                'subscription_id' => $subscriptionId,
            ]);
            return true;
        }

        $this->logger->info('Change plan permission denied: access denied', [
            'user_id' => $user->getId()->toString(),
            'subscription_id' => $subscriptionId,
        ]);

        return false;
    }

    public function canUpdateBillingInfo(User $user, string $customerId): bool
    {
        if ($user === null) {
            $this->logger->warning('Update billing info permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Update billing info permission denied: inactive user', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        if (!$user->getId()->toString() === $customerId && !$this->userHasElevatedBillingRole($user)) {
            $this->logger->info('Update billing info permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'customer_id' => $customerId,
            ]);
            return false;
        }

        $this->logger->debug('Update billing info permission granted', [
            'user_id' => $user->getId()->toString(),
            'customer_id' => $customerId,
        ]);

        return true;
    }

    private function userHasElevatedBillingRole(User $user): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($role->isAdmin() || $role->isBillingAdmin()) {
                return true;
            }
        }
        return false;
    }
}
