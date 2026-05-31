<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\Subscription;
use DateInterval;
use DateTimeImmutable;

final class SubscriptionActivePolicy
{
    public function __construct(
        private int $graceDays = 3,
        private ?DateTimeImmutable $clock = null,
    ) {
    }

    public function isActive(Subscription $sub): bool
    {
        $now = $this->clock ?? new DateTimeImmutable();
        $statusOk = in_array(strtolower($sub->status()), ['active', 'grace'], true);
        if (!$statusOk) {
            return false;
        }

        $cutoff = $sub->periodEndsAt()->add(new DateInterval('P' . $this->graceDays . 'D'));
        return $now <= $cutoff;
    }
}

final class RenewalScheduler
{
    public function __construct(
        private SubscriptionActivePolicy $policy,
        private SubscriptionRepositoryInterface $subs,
    ) {}

    public function scheduleReminders(): int
    {
        $sent = 0;
        foreach ($this->subs->dueSoon() as $sub) {
            if ($this->policy->isActive($sub)) {
                $sent++;
            }
        }
        return $sent;
    }
}
