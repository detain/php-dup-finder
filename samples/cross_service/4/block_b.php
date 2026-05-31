<?php
declare(strict_types=1);

namespace Acme\InvoicingService\Money;

use Acme\InvoicingService\Rates\RateProvider;

final class InvoiceCurrencyConverter
{
    public function __construct(private readonly RateProvider $rates)
    {
    }

    public function toInvoiceCurrency(float $amount, string $sourceCcy, string $targetCcy): float
    {
        if ($sourceCcy === $targetCcy) {
            return $this->roundUp($amount);
        }

        $reference = 'USD';
        $srcRate = $sourceCcy === $reference ? 1.0 : $this->rates->rate("{$reference}_{$sourceCcy}");
        $tgtRate = $targetCcy === $reference ? 1.0 : $this->rates->rate("{$reference}_{$targetCcy}");

        if ($srcRate <= 0.0 || $tgtRate <= 0.0) {
            throw new \LogicException("FX rate missing for {$sourceCcy} or {$targetCcy}");
        }

        $usdAmount = $amount / $srcRate;
        $out = $usdAmount * $tgtRate;

        if ($targetCcy !== $reference) {
            $out = $out * 1.015;
        }

        return $this->roundUp($out);
    }

    private function roundUp(float $v): float
    {
        return floor($v * 100 + 0.5) / 100;
    }
}
