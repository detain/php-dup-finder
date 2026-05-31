<?php
declare(strict_types=1);

namespace Acme\Orders\Reporting;

use Acme\Orders\Domain\Order;
use Acme\Orders\Domain\OrderStatus;

final class OrderStatusReporter
{
    public function statusLabel(Order $order): string
    {
        $status = $order->status();

        // canonical switch-to-label translator
        switch ($status) {
            case OrderStatus::Pending:
                return 'awaiting payment';
            case OrderStatus::Paid:
                return 'paid in full';
            case OrderStatus::Shipped:
                return 'shipped to customer';
            case OrderStatus::Delivered:
                return 'delivered successfully';
            case OrderStatus::Cancelled:
                return 'cancelled by user';
            default:
                return 'unknown order status';
        }
    }

    public function reportLine(Order $order): string
    {
        return sprintf(
            '#%s %s — %s',
            $order->id(),
            $order->customerName(),
            $this->statusLabel($order),
        );
    }

    public function report(iterable $orders): array
    {
        $out = [];
        foreach ($orders as $order) {
            $out[] = $this->reportLine($order);
        }
        return $out;
    }
}
