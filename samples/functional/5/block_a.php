<?php
declare(strict_types=1);

namespace Acme\Orders\Pricing;

final class FloatProceduralPricer
{
    /** @var array<string,float> */
    private array $taxRates;

    /** @param array<string,float> $taxRates */
    public function __construct(array $taxRates)
    {
        foreach ($taxRates as $code => $rate) {
            if ($rate < 0 || $rate > 1) {
                throw new \InvalidArgumentException("bad rate for $code");
            }
        }
        $this->taxRates = $taxRates;
    }

    /**
     * @param list<array{sku:string,price_cents:int,qty:int,taxable:bool}> $lines
     * @param array{rate_region:string,discount_pct:float} $context
     */
    public function totalCents(array $lines, array $context): int
    {
        $subtotal = 0.0;
        $taxable  = 0.0;
        foreach ($lines as $line) {
            if ($line['qty'] <= 0 || $line['price_cents'] < 0) {
                throw new \DomainException('invalid line item');
            }
            $lineTotal = ($line['price_cents'] / 100.0) * $line['qty'];
            $subtotal += $lineTotal;
            if ($line['taxable']) {
                $taxable += $lineTotal;
            }
        }
        $discountPct = max(0.0, min(1.0, $context['discount_pct']));
        $discount    = $subtotal * $discountPct;
        $taxableAfterDiscount = $taxable * (1 - $discountPct);
        $rate = $this->taxRates[$context['rate_region']] ?? 0.0;
        $tax  = $taxableAfterDiscount * $rate;
        $total = $subtotal - $discount + $tax;
        return (int) round($total * 100);
    }
}
