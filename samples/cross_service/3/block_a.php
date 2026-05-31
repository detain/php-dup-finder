<?php
declare(strict_types=1);

namespace Acme\OrderService\Refund;

use Acme\OrderService\Repository\OrderRepository;

final class RefundEligibilityChecker
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function canRefund(string $orderId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return ['ok' => false, 'reason' => 'order_not_found'];
        }

        $now = new \DateTimeImmutable();
        $placed = new \DateTimeImmutable($order->placedAt);
        $ageDays = (int) $placed->diff($now)->days;
        if ($ageDays > 30) {
            return ['ok' => false, 'reason' => 'beyond_window'];
        }

        if ($order->status === 'refunded' || $order->status === 'partially_refunded') {
            return ['ok' => false, 'reason' => 'already_refunded'];
        }

        if ($order->paymentStatus !== 'captured') {
            return ['ok' => false, 'reason' => 'not_captured'];
        }

        foreach ($order->lines as $line) {
            if ($line->finalSale) {
                return ['ok' => false, 'reason' => 'contains_final_sale'];
            }
        }

        return ['ok' => true, 'reason' => null];
    }
}
