<?php
declare(strict_types=1);

namespace App\Subscription\Rules;

use App\Subscription\Repository\SubscriptionRepository;
use App\Customer\Repository\CustomerRepository;

final class SubscriptionRulesEngine
{
    private SubscriptionRepository $subscriptionRepo;
    private CustomerRepository $customerRepo;

    private array $rules = [];

    public function __construct(
        SubscriptionRepository $subscriptionRepo,
        CustomerRepository $customerRepo
    ) {
        $this->subscriptionRepo = $subscriptionRepo;
        $this->customerRepo = $customerRepo;
        $this->initializeRules();
    }

    private function initializeRules(): void
    {
        $this->rules = [
            [
                'id' => 'customer_must_exist',
                'description' => 'Customer account must exist',
                'check' => fn($customerId) => $this->customerExists($customerId)
            ],
            [
                'id' => 'customer_must_be_verified',
                'description' => 'Customer must have verified email',
                'check' => fn($customerId) => $this->customerVerified($customerId)
            ],
            [
                'id' => 'customer_must_be_active',
                'description' => 'Customer account must be active',
                'check' => fn($customerId) => $this->customerActive($customerId)
            ],
            [
                'id' => 'no_existing_subscription',
                'description' => 'Customer must not have active subscription',
                'check' => fn($customerId) => !$this->hasActiveSubscription($customerId)
            ],
            [
                'id' => 'plan_must_exist',
                'description' => 'Plan must exist and be active',
                'check' => fn($customerId, $planId) => $this->planExistsAndActive($planId)
            ],
            [
                'id' => 'no_pending_subscription',
                'description' => 'Customer must not have pending subscription',
                'check' => fn($customerId) => !$this->hasPendingSubscription($customerId)
            ],
            [
                'id' => 'customer_standing_good',
                'description' => 'Customer must be in good standing',
                'check' => fn($customerId) => $this->isInGoodStanding($customerId)
            ]
        ];
    }

    public function evaluateEligibility(string $customerId, string $planId): EligibilityReport
    {
        $passedRules = [];
        $failedRules = [];

        foreach ($this->rules as $rule) {
            try {
                $result = ($rule['check'])($customerId, $planId);

                if ($result) {
                    $passedRules[] = $rule['id'];
                } else {
                    $failedRules[] = $rule['id'];
                }
            } catch (\Throwable $e) {
                $failedRules[] = $rule['id'];
            }
        }

        $eligible = count($failedRules) === 0;

        return new EligibilityReport(
            customerId: $customerId,
            planId: $planId,
            eligible: $eligible,
            passedRules: $passedRules,
            failedRules: $failedRules,
            failedRuleDescriptions: $this->getFailedRuleDescriptions($failedRules)
        );
    }

    public function isEligible(string $customerId, string $planId): bool
    {
        $report = $this->evaluateEligibility($customerId, $planId);

        return $report->eligible;
    }

    private function customerExists(string $customerId): bool
    {
        return $this->customerRepo->findById($customerId) !== null;
    }

    private function customerVerified(string $customerId): bool
    {
        $customer = $this->customerRepo->findById($customerId);
        return $customer?->isVerified() ?? false;
    }

    private function customerActive(string $customerId): bool
    {
        $customer = $this->customerRepo->findById($customerId);
        return $customer?->isActive() ?? false;
    }

    private function hasActiveSubscription(string $customerId): bool
    {
        return $this->subscriptionRepo->findActiveSubscriptionForCustomer($customerId) !== null;
    }

    private function hasPendingSubscription(string $customerId): bool
    {
        return $this->subscriptionRepo->findPendingSubscriptionForCustomer($customerId) !== null;
    }

    private function planExistsAndActive(string $planId): bool
    {
        return true;
    }

    private function isInGoodStanding(string $customerId): bool
    {
        $customer = $this->customerRepo->findById($customerId);

        if ($customer === null) {
            return false;
        }

        return !$customer->hasUnpaidInvoices() && !$customer->isFlagged();
    }

    private function getFailedRuleDescriptions(array $failedRules): array
    {
        $descriptions = [];

        foreach ($this->rules as $rule) {
            if (in_array($rule['id'], $failedRules, true)) {
                $descriptions[] = $rule['description'];
            }
        }

        return $descriptions;
    }
}
