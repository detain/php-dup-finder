<?php
declare(strict_types=1);

namespace App\Subscription\Validator;

use App\Subscription\Repository\SubscriptionRepository;
use App\Customer\Repository\CustomerRepository;
use Psr\Log\LoggerInterface;

final class SubscriptionEligibilityValidator
{
    private SubscriptionRepository $subscriptionRepo;
    private CustomerRepository $customerRepo;
    private LoggerInterface $logger;

    public function __construct(
        SubscriptionRepository $subscriptionRepo,
        CustomerRepository $customerRepo,
        LoggerInterface $logger
    ) {
        $this->subscriptionRepo = $subscriptionRepo;
        $this->customerRepo = $customerRepo;
        $this->logger = $logger;
    }

    public function validateNewSubscription(string $customerId, string $planId): array
    {
        $validationErrors = [];

        $customer = $this->customerRepo->findById($customerId);
        if ($customer === null) {
            $validationErrors[] = 'customer_not_found';
        } else {
            if (!$customer->isEmailVerified()) {
                $validationErrors[] = 'email_verification_required';
            }

            if (!$customer->isActive()) {
                $validationErrors[] = 'account_not_active';
            }

            if ($customer->hasUnpaidInvoices()) {
                $validationErrors[] = 'outstanding_payment_required';
            }

            if ($customer->isFlagged()) {
                $validationErrors[] = 'account_flagged';
            }
        }

        $plan = $this->getPlan($planId);
        if ($plan === null) {
            $validationErrors[] = 'plan_not_found';
        } else {
            if (!$plan->isActive()) {
                $validationErrors[] = 'plan_not_available';
            }

            $ageDays = $customer?->getAccountAgeDays() ?? 0;
            if ($plan->getMinAccountAge() > $ageDays) {
                $validationErrors[] = 'account_too_new';
            }

            if ($plan->hasCountryRestriction()) {
                $customerCountry = $customer?->getCountry();
                if ($customerCountry !== null && !$plan->isCountryAllowed($customerCountry)) {
                    $validationErrors[] = 'plan_not_available_in_region';
                }
            }
        }

        $existingSubscription = $this->subscriptionRepo->findActiveSubscriptionForCustomer($customerId);
        if ($existingSubscription !== null) {
            $validationErrors[] = 'subscription_already_active';
        }

        $pendingSubscription = $this->subscriptionRepo->findPendingSubscriptionForCustomer($customerId);
        if ($pendingSubscription !== null) {
            $validationErrors[] = 'subscription_pending';
        }

        return $validationErrors;
    }

    public function meetsAllRequirements(string $customerId, string $planId): bool
    {
        $errors = $this->validateNewSubscription($customerId, $planId);

        return count($errors) === 0;
    }

    public function getEligibilityReasons(string $customerId, string $planId): array
    {
        $errors = $this->validateNewSubscription($customerId, $planId);

        $reasons = [];

        foreach ($errors as $error) {
            $reasons[] = $this->translateError($error);
        }

        return $reasons;
    }

    private function translateError(string $error): string
    {
        $translations = [
            'customer_not_found' => 'Unable to find your account',
            'email_verification_required' => 'Please verify your email address first',
            'account_not_active' => 'Your account must be active to subscribe',
            'outstanding_payment_required' => 'Please clear any outstanding payments',
            'account_flagged' => 'Account has an outstanding issue that must be resolved',
            'plan_not_found' => 'The selected plan is not available',
            'plan_not_available' => 'The selected plan is currently unavailable',
            'account_too_new' => 'Account age does not meet plan requirements',
            'plan_not_available_in_region' => 'Plan is not available in your region',
            'subscription_already_active' => 'You already have an active subscription',
            'subscription_pending' => 'A subscription is currently being processed'
        ];

        return $translations[$error] ?? 'Subscription eligibility requirements not met';
    }

    private function getPlan(string $planId): ?object
    {
        return null;
    }
}
