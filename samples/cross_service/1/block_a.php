<?php
declare(strict_types=1);

namespace Acme\OrderService\Domain;

use Acme\OrderService\Repository\OrderRepository;
use Acme\OrderService\Dto\OrderLine;
use Psr\Log\LoggerInterface;

final class OrderTotalCalculator
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly LoggerInterface $logger
    ) {
    }

    public function totalForOrder(string $orderId): float
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        $subtotal = 0.0;
        foreach ($order->lines as $line) {
            /** @var OrderLine $line */
            $lineTotal = $line->quantity * $line->unitPrice;
            $subtotal += $lineTotal;
        }

        $discount = 0.0;
        if ($order->discountPercent > 0) {
            $discount = $subtotal * ($order->discountPercent / 100.0);
        }
        if ($order->discountFlat > 0) {
            $discount += $order->discountFlat;
        }

        $taxable = $subtotal - $discount;
        $tax = $taxable * ($order->taxRate / 100.0);

        $shipping = $order->shippingCost;
        if ($subtotal >= 100.0) {
            $shipping = 0.0;
        }

        $total = $taxable + $tax + $shipping;
        $this->logger->info('order total', ['order' => $orderId, 'total' => $total]);

        return round($total, 2);
    }
}
