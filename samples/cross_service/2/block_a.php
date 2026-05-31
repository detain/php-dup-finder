<?php
declare(strict_types=1);

namespace Acme\CheckoutService\Domain;

use Acme\CheckoutService\Cart\Cart;
use Acme\CheckoutService\Customer\CustomerProfile;

final class PointsPreviewer
{
    public function previewPoints(Cart $cart, CustomerProfile $profile): int
    {
        $eligibleSpend = 0.0;
        foreach ($cart->items() as $item) {
            if ($item->category === 'gift-card') {
                continue;
            }
            $eligibleSpend += $item->quantity * $item->unitPrice;
        }

        $basePoints = (int) floor($eligibleSpend);

        $multiplier = 1.0;
        $tier = strtolower($profile->tier);
        if ($tier === 'silver') {
            $multiplier = 1.25;
        } elseif ($tier === 'gold') {
            $multiplier = 1.5;
        } elseif ($tier === 'platinum') {
            $multiplier = 2.0;
        }

        $dow = (int) (new \DateTimeImmutable($cart->checkoutAt))->format('N');
        if ($dow >= 6) {
            $multiplier *= 2.0;
        }

        $points = (int) floor($basePoints * $multiplier);
        if ($points > 50000) {
            $points = 50000;
        }
        return $points;
    }
}
