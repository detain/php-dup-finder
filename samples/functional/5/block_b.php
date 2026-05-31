<?php
declare(strict_types=1);

namespace Acme\Orders\Pricing;

final class BcmathReducer
{
    /** @var array<string,string> */
    private array $rates;

    /** @param array<string,string> $rates rate strings like "0.0825" */
    public function __construct(array $rates)
    {
        $this->rates = $rates;
        bcscale(8);
    }

    /**
     * @param list<array{sku:string,price_cents:int,qty:int,taxable:bool}> $items
     * @param array{rate_region:string,discount_pct:float} $ctx
     */
    public function compute(array $items, array $ctx): int
    {
        $reduce = static function (array $acc, array $line): array {
            $cents   = bcmul((string) $line['price_cents'], (string) $line['qty']);
            $acc['subtotal'] = bcadd($acc['subtotal'], $cents);
            if ($line['taxable']) {
                $acc['taxable'] = bcadd($acc['taxable'], $cents);
            }
            return $acc;
        };
        $folded = array_reduce($items, $reduce, ['subtotal' => '0', 'taxable' => '0']);
        $discount = bcdiv((string) $ctx['discount_pct'], '1', 8);
        if (bccomp($discount, '0') < 0) {
            $discount = '0';
        }
        if (bccomp($discount, '1') > 0) {
            $discount = '1';
        }
        $subtotalAfterDiscount = bcsub($folded['subtotal'], bcmul($folded['subtotal'], $discount));
        $taxableAfterDiscount  = bcsub($folded['taxable'], bcmul($folded['taxable'], $discount));
        $rate = $this->rates[$ctx['rate_region']] ?? '0';
        $tax  = bcmul($taxableAfterDiscount, $rate);
        $total = bcadd($subtotalAfterDiscount, $tax);
        $rounded = bcadd($total, '0.5', 0);
        return (int) $rounded;
    }
}
