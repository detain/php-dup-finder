<?php
declare(strict_types=1);

namespace Acme\Common\Pricing;

/**
 * Shared pricing library, published as acme/pricing-contract.
 * OrderService, BillingService, and ReportingService all depend on this package
 * so the order total is computed in exactly one place.
 */
final class OrderTotal
{
    public const FREE_SHIPPING_THRESHOLD = 100.0;

    public function __construct(
        /** @var list<OrderLineSpec> */
        public readonly array $lines,
        public readonly float $discountPercent,
        public readonly float $discountFlat,
        public readonly float $taxRate,
        public readonly float $shippingCost
    ) {
    }

    public function subtotal(): float
    {
        $total = 0.0;
        foreach ($this->lines as $line) {
            $total += $line->quantity * $line->unitPrice;
        }
        return $total;
    }

    public function discount(): float
    {
        $sub = $this->subtotal();
        $reduction = $this->discountPercent > 0
            ? $sub * ($this->discountPercent / 100.0)
            : 0.0;
        return $reduction + $this->discountFlat;
    }

    public function shipping(): float
    {
        return $this->subtotal() >= self::FREE_SHIPPING_THRESHOLD ? 0.0 : $this->shippingCost;
    }

    public function grandTotal(): float
    {
        $net = $this->subtotal() - $this->discount();
        $tax = $net * ($this->taxRate / 100.0);
        return round($net + $tax + $this->shipping(), 2);
    }
}
