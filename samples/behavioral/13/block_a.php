<?php
declare(strict_types=1);

namespace App\Subscription\Service;

use App\Subscription\Repository\SubscriptionRepository;
use App\Subscription\Entity\Subscription;
use App\Subscription\Exception\EligibilityException;
use Psr\Log\LoggerInterface;

final class SubscriptionEligibilityService
{
    private SubscriptionRepository $subscriptionRepository;
    private LoggerInterface $logger;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        LoggerInterface $logger
    ) {
        $this->subscriptionRepository = $subscriptionRepository;
        $this->logger = $logger;
    }

    public function checkEligibility(string $customerId, string $planId): EligibilityResult
    {
        $customer = $this->getCustomerDetails($customerId);

        if ($customer === null) {
            return new EligibilityResult(false, 'Customer not found');
        }

        if (!$customer['is_verified']) {
            return new EligibilityResult(false, 'Email not verified');
        }

        if (!$customer['is_active']) {
            return new EligibilityResult(false, 'Account is not active');
        }

        $existingSubscription = $this->subscriptionRepository->findActiveSubscriptionForCustomer($customerId);

        if ($existingSubscription !== null) {
            return new EligibilityResult(false, 'Customer already has an active subscription');
        }

        $plan = $this->getPlanDetails($planId);

        if ($plan === null) {
            return new EligibilityResult(false, 'Plan not found');
        }

        if (!$plan['is_available']) {
            return new EligibilityResult(false, 'Plan is not currently available');
        }

        if ($plan['requires_trial'] && !$customer['is_trial_eligible']) {
            return new EligibilityResult(false, 'Customer is not eligible for trial');
        }

        $billingRequirements = $this->checkBillingRequirements($customer, $plan);
        if (!$billingRequirements['eligible']) {
            return new EligibilityResult(false, $billingRequirements['reason']);
        }

        return new EligibilityResult(true, 'Eligible for subscription');
    }

    public function validateSubscriptionStart(string $customerId, string $planId): ValidationResult
    {
        $errors = [];

        $customer = $this->getCustomerDetails($customerId);
        if ($customer === null) {
            $errors[] = 'Customer not found';
        } else {
            if (!$customer['is_verified']) {
                $errors[] = 'Email verification required';
            }

            if (!$customer['is_active']) {
                $errors[] = 'Active account required';
            }
        }

        $existingSub = $this->subscriptionRepository->findActiveSubscriptionForCustomer($customerId);
        if ($existingSub !== null) {
            $errors[] = 'Active subscription already exists';
        }

        $plan = $this->getPlanDetails($planId);
        if ($plan === null) {
            $errors[] = 'Plan not found';
        } elseif (!$plan['is_available']) {
            $errors[] = 'Plan unavailable';
        }

        if (count($errors) === 0) {
            return new ValidationResult(true, [], null);
        }

        return new ValidationResult(false, $errors, new EligibilityException(implode('; ', $errors)));
    }

    public function canUpgradePlan(string $customerId, string $targetPlanId): bool
    {
        $currentSubscription = $this->subscriptionRepository->findActiveSubscriptionForCustomer($customerId);

        if ($currentSubscription === null) {
            return false;
        }

        $targetPlan = $this->getPlanDetails($targetPlanId);

        if ($targetPlan === null || !$targetPlan['is_available']) {
            return false;
        }

        $currentPlan = $this->getPlanDetails($currentSubscription->getPlanId());

        if ($currentPlan === null) {
            return false;
        }

        return $targetPlan['tier'] > $currentPlan['tier'];
    }

    public function canDowngradePlan(string $customerId, string $targetPlanId): bool
    {
        $currentSubscription = $this->subscriptionRepository->findActiveSubscriptionForCustomer($customerId);

        if ($currentSubscription === null) {
            return false;
        }

        $monthsSubscribed = $this->calculateMonthsSubscribed($currentSubscription);

        if ($monthsSubscribed < 3) {
            return false;
        }

        $targetPlan = $this->getPlanDetails($targetPlanId);

        if ($targetPlan === null || !$targetPlan['is_available']) {
            return false;
        }

        $currentPlan = $this->getPlanDetails($currentSubscription->getPlanId());

        if ($currentPlan === null) {
            return false;
        }

        return $targetPlan['tier'] < $currentPlan['tier'];
    }

    private function getCustomerDetails(string $customerId): ?array
    {
        return [
            'id' => $customerId,
            'is_verified' => true,
            'is_active' => true,
            'is_trial_eligible' => true
        ];
    }

    private function getPlanDetails(string $planId): ?array
    {
        return [
            'id' => $planId,
            'is_available' => true,
            'tier' => 2,
            'requires_trial' => false
        ];
    }

    private function checkBillingRequirements(array $customer, array $plan): array
    {
        return ['eligible' => true, 'reason' => null];
    }

    private function calculateMonthsSubscribed(Subscription $subscription): int
    {
        $createdAt = $subscription->getCreatedAt();
        $now = new \DateTimeImmutable();
        $months = $createdAt->diff($now)->m + ($createdAt->diff($now)->y * 12);

        return $months;
    }
}
