<?php

declare(strict_types=1);

namespace App\Beta\Eligibility;

use App\Accounts\Account;
use App\Billing\Subscription;
use Psr\Log\LoggerInterface;

final class BetaProgramGate
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function canEnrollInBeta(Account $account, Subscription $subscription): bool
    {
        if ($account->isSuspended()) {
            $this->logger->info('beta.denied.suspended', ['account' => $account->id()]);
            return false;
        }

        if (!$account->isEmailVerified()) {
            $this->logger->info('beta.denied.email', ['account' => $account->id()]);
            return false;
        }

        if ($account->createdAt()->diff(new \DateTimeImmutable())->days < 14) {
            $this->logger->info('beta.denied.too_new', ['account' => $account->id()]);
            return false;
        }

        if (!in_array($subscription->plan(), ['pro', 'enterprise'], true)) {
            $this->logger->info('beta.denied.plan', ['account' => $account->id()]);
            return false;
        }

        if ($subscription->isPastDue()) {
            $this->logger->info('beta.denied.past_due', ['account' => $account->id()]);
            return false;
        }

        if (!$account->hasOptedIntoExperiments()) {
            $this->logger->info('beta.denied.no_opt_in', ['account' => $account->id()]);
            return false;
        }

        $this->logger->info('beta.granted', ['account' => $account->id()]);
        return true;
    }
}
