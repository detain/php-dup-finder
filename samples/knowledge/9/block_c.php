<?php

declare(strict_types=1);

namespace App\Support\Macros;

use App\Domain\Order;
use App\Repositories\OrderRepository;
use DateTimeImmutable;

final class CustomerServiceMacros
{
    public function __construct(private OrderRepository $orders) {}

    public function refundMacroFor(int $orderId): string
    {
        $order = $this->orders->findOrFail($orderId);

        if ($order->deliveredAt === null) {
            return "Hi! It looks like your order hasn't been delivered yet, so a refund isn't applicable. "
                . "Please reach back out once you've received it.";
        }

        $now = new DateTimeImmutable();
        $daysSinceDelivery = (int) $now->diff($order->deliveredAt)->days;
        $deadline = $order->deliveredAt->modify('+30 days');

        if ($daysSinceDelivery <= 30) {
            $remaining = 30 - $daysSinceDelivery;
            return sprintf(
                "Hi! Yes, you're well within our 30-day refund window — you have %d day%s left "
                . "(until %s). I've started the return process; you'll get a confirmation email shortly.",
                $remaining,
                $remaining === 1 ? '' : 's',
                $deadline->format('M j, Y')
            );
        }

        return sprintf(
            "Hi! Unfortunately, this order was delivered on %s, which is more than 30 days ago, "
            . "so it's outside our refund window. We can still help with troubleshooting or a "
            . "replacement under warranty if applicable.",
            $order->deliveredAt->format('M j, Y')
        );
    }
}
