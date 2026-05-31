<?php
declare(strict_types=1);

namespace Acme\BillingService\Domain;

use Acme\BillingService\Gateway\OrderGateway;
use Acme\BillingService\Model\InvoiceLineItem;

final class InvoiceAmountResolver
{
    private OrderGateway $gateway;

    public function __construct(OrderGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function resolve(string $orderRef): array
    {
        $payload = $this->gateway->fetchOrder($orderRef);
        if (empty($payload)) {
            throw new \LogicException('cannot bill empty order');
        }

        $itemsTotal = 0.0;
        foreach ($payload['items'] as $item) {
            /** @var InvoiceLineItem $item */
            $itemsTotal += $item['qty'] * $item['price'];
        }

        $reduction = 0.0;
        if (($payload['discount_percent'] ?? 0) > 0) {
            $reduction = $itemsTotal * ((float) $payload['discount_percent'] / 100.0);
        }
        if (($payload['discount_flat'] ?? 0) > 0) {
            $reduction += (float) $payload['discount_flat'];
        }

        $afterDiscount = $itemsTotal - $reduction;
        $taxAmount = $afterDiscount * ((float) $payload['tax_rate'] / 100.0);

        $ship = (float) $payload['shipping'];
        if ($itemsTotal >= 100.0) {
            $ship = 0.0;
        }

        return [
            'subtotal' => $itemsTotal,
            'invoice_total' => round($afterDiscount + $taxAmount + $ship, 2),
        ];
    }
}
