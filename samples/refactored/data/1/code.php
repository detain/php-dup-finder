<?php
declare(strict_types=1);

namespace App\Tax;

final class TaxPolicy
{
    public const STANDARD_RATE = 0.0875;

    public static function compute(float $amount): float
    {
        return round($amount * self::STANDARD_RATE, 2);
    }
}

namespace App\Checkout;

use App\Tax\TaxPolicy;
use App\Database\Connection;

final class CartTotalCalculator
{
    public function __construct(private Connection $db) {}

    public function calculate(int $cartId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT unit_price, quantity FROM cart_items WHERE cart_id = ?',
            [$cartId]
        );
        $subtotal = array_sum(array_map(
            fn($r) => (float)$r['unit_price'] * (int)$r['quantity'],
            $rows
        ));
        $tax = TaxPolicy::compute($subtotal);
        return [
            'subtotal' => $subtotal,
            'tax'      => $tax,
            'total'    => round($subtotal + $tax, 2),
        ];
    }
}

namespace App\Invoicing;

use App\Tax\TaxPolicy;

final class InvoiceGenerator
{
    public function generate(float $subtotal): array
    {
        $tax = TaxPolicy::compute($subtotal);
        return [
            'subtotal' => $subtotal,
            'tax_rate' => TaxPolicy::STANDARD_RATE,
            'tax'      => $tax,
            'total'    => round($subtotal + $tax, 2),
        ];
    }
}

namespace App\Refunds;

use App\Tax\TaxPolicy;

final class RefundProcessor
{
    public function refund(float $subtotal): float
    {
        return round($subtotal + TaxPolicy::compute($subtotal), 2);
    }
}
