<?php

declare(strict_types=1);

namespace Acme\Restaurant\Checkout;

use Acme\Restaurant\Checkout\Dto\OrderTotal;

final class DineInBillCalculator
{
    public function finalize(float $foodSubtotal, string $stateCode): OrderTotal
    {
        $stateTaxRate = match ($stateCode) {
            'CA' => 0.0725,
            'NY' => 0.04,
            'TX' => 0.0625,
            default => 0.05,
        };

        $cascade = [
            ['label' => 'sales_tax',     'rate' => $stateTaxRate],
            ['label' => 'gratuity',      'rate' => 0.18],
            ['label' => 'service_fee',   'rate' => 0.03],
            ['label' => 'health_surchg', 'rate' => 0.015],
        ];

        $running = $foodSubtotal;
        $breakdown = [];
        foreach ($cascade as $step) {
            $amount = round($running * $step['rate'], 2);
            $breakdown[$step['label']] = $amount;
            $running += $amount;
        }

        return new OrderTotal(
            subtotal: round($foodSubtotal, 2),
            charges: $breakdown,
            total: round($running, 2),
        );
    }
}
