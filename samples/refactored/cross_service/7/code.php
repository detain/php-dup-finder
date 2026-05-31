<?php
declare(strict_types=1);

namespace Acme\Common\Tax;

/**
 * acme/sales-tax is the single tax-rule package. Catalog (preview),
 * Checkout (charge), and Accounting (post liability) call the same compute()
 * with their local cart/order mapped into a TaxRequest.
 */
final class SalesTaxCalculator
{
    public const EXEMPT_CATEGORIES = ['groceries', 'prescription', 'baby-formula'];

    public function __construct(private readonly TaxRateRepository $rates)
    {
    }

    public function compute(TaxRequest $request): float
    {
        $taxable = 0.0;
        foreach ($request->lines as $line) {
            if (in_array($line->category, self::EXEMPT_CATEGORIES, true)) {
                continue;
            }
            $taxable += $line->quantity * $line->unitPrice;
        }

        $jurisdiction = $request->jurisdiction;
        $combined = $this->rates->rateFor($jurisdiction);

        $base = $taxable;
        if ($this->rates->shippingTaxable($jurisdiction->state)) {
            $base += $request->shippingCost;
        }

        return round($base * $combined, 2);
    }
}
