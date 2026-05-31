<?php
declare(strict_types=1);

namespace App\Subscription\Policy;

interface SubscriptionEligibilityPolicyInterface
{
    public function isEligible(string $customerId, string $planId): bool;
    public function getIneligibilityReasons(string $customerId, string $planId): array;
}

final class SubscriptionEligibilityPolicy implements SubscriptionEligibilityPolicyInterface
{
    public function __construct(
        private readonly CustomerRepository $customerRepository,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PlanRepository $planRepository
    ) {}

    public function isEligible(string $customerId, string $planId): bool
    {
        return empty($this->getIneligibilityReasons($customerId, $planId));
    }

    public function getIneligibilityReasons(string $customerId, string $planId): array
    {
        $reasons = [];

        $customer = $this->customerRepository->findById($customerId);

        if ($customer === null) {
            $reasons[] = 'Customer not found';
            return $reasons;
        }

        if (!$customer->isVerified()) {
            $reasons[] = 'Email verification required';
        }

        if (!$customer->isActive()) {
            $reasons[] = 'Account not active';
        }

        if ($this->subscriptionRepository->findActiveSubscriptionForCustomer($customerId) !== null) {
            $reasons[] = 'Active subscription already exists';
        }

        $plan = $this->planRepository->findById($planId);

        if ($plan === null) {
            $reasons[] = 'Plan not found';
        } elseif (!$plan->isAvailable()) {
            $reasons[] = 'Plan not available';
        }

        return $reasons;
    }
}
