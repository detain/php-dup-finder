<?php

declare(strict_types=1);

namespace App\Subscription;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\ProrationCalculator;
use Psr\Log\LoggerInterface;

final class SubscriptionManagementService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly ProrationCalculator $prorationCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function createSubscription(int $customerId, string $planId): Subscription
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('Invalid customer ID');
        }

        if (empty($planId)) {
            throw new \InvalidArgumentException('Plan ID is required');
        }

        $customer = $this->loadCustomer($customerId);
        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Customer account is not active');
        }

        if ($customer->isBlocked()) {
            throw new \InvalidArgumentException('Customer is blocked from subscriptions');
        }

        $plan = $this->loadPlan($planId);
        if ($plan === null) {
            throw new \RuntimeException('Plan not found');
        }

        if ($plan->isArchived()) {
            throw new \InvalidArgumentException('Plan is no longer available');
        }

        $existingSubscription = $this->subscriptionRepository->findActiveByCustomer($customerId);
        if ($existingSubscription !== null) {
            throw new \InvalidArgumentException('Customer already has an active subscription');
        }

        $subscription = new Subscription();
        $subscription->setCustomerId($customerId);
        $subscription->setPlanId($planId);
        $subscription->setStatus('active');
        $subscription->setCurrentPeriodStart(new \DateTimeImmutable());
        $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('+1 month'));

        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Subscription created', [
            'subscription_id' => $subscription->getId(),
            'customer_id' => $customerId,
            'plan_id' => $planId,
        ]);

        return $subscription;
    }

    public function changePlan(int $subscriptionId, string $newPlanId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);

        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if ($subscription->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Subscription is not active');
        }

        $newPlan = $this->loadPlan($newPlanId);
        if ($newPlan === null) {
            throw new \RuntimeException('Plan not found');
        }

        if ($newPlan->isArchived()) {
            throw new \InvalidArgumentException('New plan is no longer available');
        }

        $currentPlan = $this->loadPlan($subscription->getPlanId());

        if ($newPlan->getPrice() > $currentPlan->getPrice()) {
            $proration = $this->prorationCalculator->calculateUpgrade(
                $subscription,
                $newPlan,
                $currentPlan
            );

            $subscription->setPendingCharge($proration);
            $this->logger->info('Upgrade requires proration charge', [
                'subscription_id' => $subscriptionId,
                'proration' => $proration,
            ]);
        }

        $subscription->setPlanId($newPlanId);
        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Subscription plan changed', [
            'subscription_id' => $subscriptionId,
            'old_plan' => $currentPlan->getId(),
            'new_plan' => $newPlanId,
        ]);

        return $subscription;
    }

    public function cancelSubscription(int $subscriptionId, bool $immediate = false): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);

        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if (in_array($subscription->getStatus(), ['cancelled', 'expired', 'terminated'], true)) {
            throw new \InvalidArgumentException('Subscription is already cancelled');
        }

        if ($subscription->getStatus() === 'past_due') {
            throw new \InvalidArgumentException('Cannot cancel subscription with outstanding payment');
        }

        if ($immediate) {
            $subscription->setStatus('cancelled');
            $subscription->setCancelledAt(new \DateTimeImmutable());
        } else {
            $subscription->setStatus('cancel_at_period_end');
            $subscription->setCancelAt($subscription->getCurrentPeriodEnd());
        }

        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Subscription cancelled', [
            'subscription_id' => $subscriptionId,
            'immediate' => $immediate,
        ]);

        return $subscription;
    }

    private function loadCustomer(int $customerId): ?Customer
    {
        return $this->customerRepository->findById($customerId);
    }

    private function loadPlan(string $planId): ?Plan
    {
        return $this->planRepository->findById($planId);
    }
}
