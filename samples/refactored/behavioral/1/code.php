<?php

declare(strict_types=1);

namespace App\Beta;

use App\Accounts\Account;
use App\Billing\Subscription;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BetaEligibilityPolicy
{
    private const ELIGIBLE_PLANS = ['pro', 'enterprise'];
    private const MIN_ACCOUNT_AGE_DAYS = 14;

    public function __construct(private LoggerInterface $logger = new NullLogger())
    {
    }

    public function isEligible(Account $account, Subscription $subscription): bool
    {
        $reasons = $this->denyReasons($account, $subscription);

        if ($reasons === []) {
            $this->logger->info('beta.granted', ['account' => $account->id()]);
            return true;
        }

        $this->logger->info('beta.denied', [
            'account' => $account->id(),
            'reasons' => $reasons,
        ]);

        return false;
    }

    /** @return list<string> */
    private function denyReasons(Account $account, Subscription $subscription): array
    {
        $now = new \DateTimeImmutable();
        $ageDays = (int) $account->createdAt()->diff($now)->days;
        $reasons = [];

        if ($account->isSuspended())                                       { $reasons[] = 'suspended'; }
        if (!$account->isEmailVerified())                                  { $reasons[] = 'email_unverified'; }
        if ($ageDays < self::MIN_ACCOUNT_AGE_DAYS)                         { $reasons[] = 'account_too_new'; }
        if (!in_array($subscription->plan(), self::ELIGIBLE_PLANS, true))  { $reasons[] = 'plan_ineligible'; }
        if ($subscription->isPastDue())                                    { $reasons[] = 'billing_past_due'; }
        if (!$account->hasOptedIntoExperiments())                          { $reasons[] = 'not_opted_in'; }

        return $reasons;
    }
}
