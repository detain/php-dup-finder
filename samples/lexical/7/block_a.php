<?php
declare(strict_types=1);

namespace Acme\Subscriptions\Schedule;

use Acme\Subscriptions\Domain\Subscription;
use Psr\Log\LoggerInterface;

final class RenewalScheduler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function nextRenewalDate(Subscription $subscription): string
    {
        $startedAt = $subscription->startedAt()->format('c');
        $cycle = $subscription->billingCycleIso();

        // canonical: parse + add interval + format
        $moment = new \DateTimeImmutable($startedAt);
        $moment = $moment->add(new \DateInterval($cycle));
        $formatted = $moment->format('Y-m-d H:i:s');

        $this->logger->debug('renewal scheduled', [
            'sub'  => $subscription->id(),
            'next' => $formatted,
        ]);

        return $formatted;
    }

    public function batchRenewals(iterable $subscriptions): array
    {
        $out = [];
        foreach ($subscriptions as $sub) {
            $out[$sub->id()] = $this->nextRenewalDate($sub);
        }
        return $out;
    }
}
