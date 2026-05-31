<?php

declare(strict_types=1);

namespace App\Features\Beta;

use App\Accounts\Account;
use App\Billing\Subscription;

final class BetaEligibilityEvaluator
{
    public function evaluate(Account $account, Subscription $subscription): bool
    {
        return $this->billingOk($subscription)
            && $this->accountOk($account)
            && $this->consentOk($account);
    }

    private function accountOk(Account $account): bool
    {
        $createdAt = $account->createdAt();
        $cutoff = (new \DateTimeImmutable())->modify('-14 days');

        return !$account->isSuspended()
            && $account->isEmailVerified()
            && $createdAt <= $cutoff;
    }

    private function billingOk(Subscription $subscription): bool
    {
        $plan = $subscription->plan();
        $allowed = match ($plan) {
            'pro', 'enterprise' => true,
            default             => false,
        };

        return $allowed && !$subscription->isPastDue();
    }

    private function consentOk(Account $account): bool
    {
        return $account->hasOptedIntoExperiments();
    }
}
