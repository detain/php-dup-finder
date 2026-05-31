<?php
declare(strict_types=1);

namespace App\Invoicing;

use App\Database\Connection;
use App\Pdf\InvoiceRenderer;

final class InvoiceGenerator
{
    public function __construct(
        private Connection $db,
        private InvoiceRenderer $renderer,
    ) {
    }

    public function generate(int $orderId): string
    {
        $order = $this->db->fetchOne(
            'SELECT id, customer_id, subtotal_cents, status FROM orders WHERE id = ?',
            [$orderId]
        );

        if ($order === null) {
            throw new \RuntimeException('Order not found: ' . $orderId);
        }

        if ($order['status'] !== 'paid') {
            throw new \DomainException('Cannot invoice unpaid order ' . $orderId);
        }

        $subtotal = ((int)$order['subtotal_cents']) / 100.0;
        $customer = $this->db->fetchOne(
            'SELECT name, billing_address, tax_id FROM customers WHERE id = ?',
            [(int)$order['customer_id']]
        );

        $taxLine = round($subtotal * 0.0875, 2);
        $grandTotal = round($subtotal + $taxLine, 2);

        $lineItems = $this->db->fetchAll(
            'SELECT description, quantity, unit_price_cents FROM order_items WHERE order_id = ?',
            [$orderId]
        );

        $items = [];
        foreach ($lineItems as $item) {
            $items[] = [
                'description' => $item['description'],
                'qty'         => (int)$item['quantity'],
                'unit_price'  => ((int)$item['unit_price_cents']) / 100.0,
            ];
        }

        return $this->renderer->render([
            'invoice_no' => 'INV-' . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT),
            'customer'   => $customer,
            'items'      => $items,
            'subtotal'   => $subtotal,
            'tax_rate'   => 0.0875,
            'tax'        => $taxLine,
            'total'      => $grandTotal,
        ]);
    }
}
