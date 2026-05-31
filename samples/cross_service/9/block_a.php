<?php
declare(strict_types=1);

namespace Acme\OrderService\Fraud;

use Acme\OrderService\Signal\SignalRepository;

final class OrderFraudGate
{
    public function __construct(private readonly SignalRepository $signals)
    {
    }

    public function decide(string $orderId): string
    {
        $sig = $this->signals->load($orderId);
        $score = 0;

        if ($sig->ordersInLastHour > 5) {
            $score += 30;
        } elseif ($sig->ordersInLastHour > 2) {
            $score += 15;
        }

        if ($sig->billingCountry !== $sig->shippingCountry) {
            $score += 20;
        }
        if ($sig->ipCountry !== $sig->billingCountry) {
            $score += 15;
        }

        if (in_array($sig->binBucket, ['prepaid', 'gift'], true)) {
            $score += 25;
        }

        if ($sig->customerAccountAgeDays < 1) {
            $score += 20;
        } elseif ($sig->customerAccountAgeDays < 7) {
            $score += 10;
        }

        if ($sig->priorChargebacks > 0) {
            $score += 40;
        }

        $score = min(100, $score);
        if ($score >= 70) {
            return 'decline';
        }
        if ($score >= 40) {
            return 'review';
        }
        return 'accept';
    }
}
