<?php
declare(strict_types=1);

namespace App\Checkout;

use App\Database\Connection;
use Psr\Log\LoggerInterface;

final class CartTotalCalculator
{
    public function __construct(
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function calculate(int $cartId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT product_id, quantity, unit_price FROM cart_items WHERE cart_id = ?',
            [$cartId]
        );

        if ($rows === []) {
            $this->logger->warning('Empty cart total requested', ['cart_id' => $cartId]);
            return ['subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        }

        $subtotal = 0.0;
        foreach ($rows as $row) {
            $line = (float)$row['unit_price'] * (int)$row['quantity'];
            if ($line < 0) {
                throw new \DomainException('Negative line total for product ' . $row['product_id']);
            }
            $subtotal += $line;
        }

        $taxableAmount = $subtotal;
        $exempt = $this->db->fetchOne(
            'SELECT tax_exempt FROM carts WHERE id = ?',
            [$cartId]
        );

        if ((bool)($exempt['tax_exempt'] ?? false) === true) {
            $tax = 0.0;
        } else {
            $tax = round($taxableAmount * 0.0875, 2);
        }

        $total = round($subtotal + $tax, 2);

        $this->logger->info('Cart total computed', [
            'cart_id'   => $cartId,
            'subtotal'  => $subtotal,
            'tax'       => $tax,
            'total'     => $total,
        ]);

        return [
            'subtotal' => $subtotal,
            'tax'      => $tax,
            'total'    => $total,
        ];
    }
}
