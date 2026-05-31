<?php
declare(strict_types=1);

namespace App\Refunds;

use App\Database\Connection;
use App\Payments\PaymentGateway;
use Psr\Log\LoggerInterface;

final class RefundProcessor
{
    public function __construct(
        private Connection $db,
        private PaymentGateway $gateway,
        private LoggerInterface $logger,
    ) {
    }

    public function refund(int $orderId, float $refundSubtotal): array
    {
        $order = $this->db->fetchOne(
            'SELECT id, transaction_id, total_cents, refunded_cents FROM orders WHERE id = ?',
            [$orderId]
        );

        if ($order === null) {
            throw new \RuntimeException('Order not found for refund: ' . $orderId);
        }

        if ($refundSubtotal <= 0.0) {
            throw new \InvalidArgumentException('Refund amount must be positive');
        }

        $originalTotal = ((int)$order['total_cents']) / 100.0;
        $alreadyRefunded = ((int)$order['refunded_cents']) / 100.0;

        $refundTax = round($refundSubtotal * 0.0875, 2);
        $refundGrand = round($refundSubtotal + $refundTax, 2);

        if ($alreadyRefunded + $refundGrand > $originalTotal + 0.01) {
            throw new \DomainException('Refund exceeds original total');
        }

        $result = $this->gateway->refund(
            (string)$order['transaction_id'],
            (int)round($refundGrand * 100)
        );

        $this->db->execute(
            'UPDATE orders SET refunded_cents = refunded_cents + ? WHERE id = ?',
            [(int)round($refundGrand * 100), $orderId]
        );

        $this->logger->info('Refund processed', [
            'order_id'    => $orderId,
            'subtotal'    => $refundSubtotal,
            'tax'         => $refundTax,
            'grand_total' => $refundGrand,
        ]);

        return [
            'gateway_id' => $result['gateway_id'],
            'amount'     => $refundGrand,
        ];
    }
}
