<?php
declare(strict_types=1);

namespace Acme\Common\Money;

/**
 * acme/money-conversion is the single authoritative converter. Pricing, Invoicing,
 * and Analytics receive an FxRateProvider via DI and share the same anchor (USD),
 * markup policy, and rounding rule.
 */
final class CurrencyConverter
{
    public const ANCHOR = 'USD';
    public const NON_ANCHOR_MARKUP = 0.015;

    public function __construct(private readonly FxRateProvider $rates)
    {
    }

    public function convert(Money $amount, string $targetCurrency): Money
    {
        $source = $amount->currency;
        if ($source === $targetCurrency) {
            return new Money($this->halfUp($amount->value), $targetCurrency);
        }

        $srcRate = $source === self::ANCHOR ? 1.0 : $this->rateOrFail(self::ANCHOR, $source);
        $tgtRate = $targetCurrency === self::ANCHOR ? 1.0 : $this->rateOrFail(self::ANCHOR, $targetCurrency);

        $inAnchor = $amount->value / $srcRate;
        $converted = $inAnchor * $tgtRate;

        if ($targetCurrency !== self::ANCHOR) {
            $converted *= (1.0 + self::NON_ANCHOR_MARKUP);
        }

        return new Money($this->halfUp($converted), $targetCurrency);
    }

    private function rateOrFail(string $base, string $code): float
    {
        $rate = $this->rates->rate("{$base}_{$code}");
        if ($rate <= 0.0) {
            throw new MissingRateException($base, $code);
        }
        return $rate;
    }

    private function halfUp(float $value): float
    {
        return floor($value * 100 + 0.5) / 100;
    }
}
