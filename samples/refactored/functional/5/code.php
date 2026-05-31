<?php
declare(strict_types=1);

namespace Acme\Orders\Pricing;

final class OrderPricer
{
    /** @var array<string,float> */
    private array $taxRates;

    /** @param array<string,float> $taxRates */
    public function __construct(array $taxRates)
    {
        foreach ($taxRates as $code => $rate) {
            if ($rate < 0 || $rate > 1) {
                throw new \InvalidArgumentException("invalid tax rate for $code");
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
        $subtotalCents = 0;
        $taxableCents  = 0;
        foreach ($lines as $line) {
            if ($line['qty'] <= 0 || $line['price_cents'] < 0) {
                throw new \DomainException('invalid line item');
            }
            $lineCents     = $line['price_cents'] * $line['qty'];
            $subtotalCents += $lineCents;
            if ($line['taxable']) {
                $taxableCents += $lineCents;
            }
        }
        $discountPct = max(0.0, min(1.0, $context['discount_pct']));
        $rate        = $this->taxRates[$context['rate_region']] ?? 0.0;
        $netSubtotal = (int) round($subtotalCents * (1 - $discountPct));
        $netTaxable  = (int) round($taxableCents  * (1 - $discountPct));
        $taxCents    = (int) round($netTaxable * $rate);
        return $netSubtotal + $taxCents;
    }
}
