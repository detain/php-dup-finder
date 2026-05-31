<?php
declare(strict_types=1);

namespace Acme\PaymentService\Refund;

use Acme\PaymentService\Gateway\OrderLookup;

final class RefundGuard
{
    public function __construct(private readonly OrderLookup $lookup)
    {
    }

    public function authorize(string $paymentRef): void
    {
        $order = $this->lookup->byPayment($paymentRef);
        if ($order === null) {
            throw new \DomainException('order_not_found');
        }

        $placedAt = new \DateTimeImmutable($order['placed_at']);
        $now = new \DateTimeImmutable();
        $days = (int) $placedAt->diff($now)->days;
        if ($days > 30) {
            throw new \DomainException('beyond_window');
        }

        $status = $order['status'];
        if (in_array($status, ['refunded', 'partially_refunded'], true)) {
            throw new \DomainException('already_refunded');
        }

        if ($order['payment_state'] !== 'captured') {
            throw new \DomainException('not_captured');
        }

        foreach ($order['items'] as $item) {
            if (!empty($item['final_sale'])) {
                throw new \DomainException('contains_final_sale');
            }
        }
    }
}
