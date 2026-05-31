<?php
declare(strict_types=1);

namespace Acme\AnalyticsService\Money;

use Acme\AnalyticsService\Warehouse\FxWarehouse;

final class RevenueCurrencyNormalizer
{
    public function __construct(private readonly FxWarehouse $warehouse)
    {
    }

    /**
     * Normalize a revenue figure from source ccy to a display ccy for BI dashboards.
     */
    public function normalize(float $amount, string $src, string $dst): float
    {
        if ($src === $dst) {
            return $this->half($amount);
        }

        $anchor = 'USD';
        $srcFx = $src === $anchor ? 1.0 : $this->warehouse->lookup("{$anchor}_{$src}");
        $dstFx = $dst === $anchor ? 1.0 : $this->warehouse->lookup("{$anchor}_{$dst}");

        if ($srcFx <= 0.0 || $dstFx <= 0.0) {
            throw new \DomainException("no FX for {$src}/{$dst}");
        }

        $inUsd = $amount / $srcFx;
        $value = $inUsd * $dstFx;

        if ($dst !== $anchor) {
            $value = $value * (1.0 + 0.015);
        }

        return $this->half($value);
    }

    private function half(float $x): float
    {
        return floor($x * 100 + 0.5) / 100;
    }
}
