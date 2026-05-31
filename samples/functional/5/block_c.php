<?php
declare(strict_types=1);

namespace Acme\Orders\Pricing;

final class MoneyObjectPricer
{
    /** @var array<string,float> */
    private array $rateTable;

    /** @param array<string,float> $rateTable */
    public function __construct(array $rateTable)
    {
        $this->rateTable = $rateTable;
    }

    /**
     * @param list<array{sku:string,price_cents:int,qty:int,taxable:bool}> $lineItems
     * @param array{rate_region:string,discount_pct:float} $context
     */
    public function calculate(array $lineItems, array $context): int
    {
        $subtotal = Money::zero();
        $taxable  = Money::zero();
        foreach ($lineItems as $item) {
            $line = Money::fromCents($item['price_cents'])->multiply($item['qty']);
            $subtotal = $subtotal->add($line);
            if ($item['taxable']) {
                $taxable = $taxable->add($line);
            }
        }
        $pct = max(0.0, min(1.0, $context['discount_pct']));
        $rate = $this->rateTable[$context['rate_region']] ?? 0.0;
        $discountedSubtotal = $subtotal->multiply(1 - $pct);
        $discountedTaxable  = $taxable->multiply(1 - $pct);
        $tax   = $discountedTaxable->multiply($rate);
        $final = $discountedSubtotal->add($tax);
        return $final->cents();
    }
}

final class Money
{
    public function __construct(private readonly int $cents) {}
    public static function zero(): self { return new self(0); }
    public static function fromCents(int $cents): self { return new self($cents); }
    public function add(Money $other): self { return new self($this->cents + $other->cents); }
    public function multiply(float $factor): self { return new self((int) round($this->cents * $factor)); }
    public function cents(): int { return $this->cents; }
}
