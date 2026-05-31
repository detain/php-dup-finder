<?php
declare(strict_types=1);

namespace Acme\ReportingService\Domain;

use Acme\ReportingService\Warehouse\OrderFactReader;

final class RevenueAggregator
{
    public function __construct(private readonly OrderFactReader $reader)
    {
    }

    public function recomputeOrderRevenue(string $orderKey): float
    {
        $fact = $this->reader->loadFact($orderKey);
        if ($fact === null) {
            return 0.0;
        }

        $gross = 0.0;
        foreach ($fact['lines'] as $row) {
            $gross += $row['qty'] * $row['unit_price'];
        }

        $rebate = 0.0;
        if ($fact['disc_pct'] > 0) {
            $rebate = $gross * ($fact['disc_pct'] / 100.0);
        }
        if ($fact['disc_flat'] > 0) {
            $rebate += $fact['disc_flat'];
        }

        $net = $gross - $rebate;
        $vat = $net * ($fact['tax_rate'] / 100.0);

        $ship = $fact['shipping_cost'];
        if ($gross >= 100.0) {
            $ship = 0.0;
        }

        return round($net + $vat + $ship, 2);
    }
}
