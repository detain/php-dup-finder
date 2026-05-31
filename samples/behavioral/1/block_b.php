<?php

declare(strict_types=1);

namespace App\Programs\Access;

use App\Accounts\Account;
use App\Billing\Subscription;

final class ExperimentalFeatureGuard
{
    private const ELIGIBLE_PLANS = ['pro', 'enterprise'];
    private const MIN_AGE_DAYS = 14;

    public function isAccountAllowedIntoExperimentalProgram(
        Account $account,
        Subscription $subscription,
    ): bool {
        $checks = [
            'not_suspended'   => !$account->isSuspended(),
            'email_verified'  => $account->isEmailVerified(),
            'aged_enough'     => $this->ageInDays($account) >= self::MIN_AGE_DAYS,
            'plan_eligible'   => in_array(
                $subscription->plan(),
                self::ELIGIBLE_PLANS,
                true,
            ),
            'billing_healthy' => !$subscription->isPastDue(),
            'opted_in'        => $account->hasOptedIntoExperiments(),
        ];

        foreach ($checks as $name => $passed) {
            if (!$passed) {
                return false;
            }
        }

        return true;
    }

    private function ageInDays(Account $account): int
    {
        $now = new \DateTimeImmutable();
        $diff = $account->createdAt()->diff($now);

        return (int) $diff->days;
    }
}
