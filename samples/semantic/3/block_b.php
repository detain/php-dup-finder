<?php

declare(strict_types=1);

namespace Acme\Api\Gateway;

use Acme\Api\Auth\TokenContext;
use Acme\Api\Repository\PlanRepository;
use Acme\Api\Exception\SubscriptionRequiredException;
use DateTimeImmutable;

final class PremiumEndpointGuard
{
    public function __construct(private PlanRepository $plans)
    {
    }

    public function check(TokenContext $token): void
    {
        $plan = $this->plans->forCustomer($token->customerId());
        $cutoff = $plan->periodEndsAt()->modify('+72 hours');
        $now = new DateTimeImmutable();

        $stillValid = ($plan->state() === 'ACTIVE' || $plan->state() === 'GRACE')
            && $now <= $cutoff;

        if (!$stillValid) {
            throw new SubscriptionRequiredException(
                'A current subscription is required to access this endpoint.'
            );
        }
    }
}
