<?php

declare(strict_types=1);

namespace Acme\Wholesale\Pricing;

use Acme\Wholesale\Cart\LineItem;
use Acme\Wholesale\Logging\AuditTrail;

final class BulkHardwareDiscounter
{
    public function __construct(private readonly AuditTrail $audit)
    {
    }

    /**
     * @param LineItem[] $items
     */
    public function priceCart(array $items, string $customerId): float
    {
        $subtotal = 0.0;
        $totalUnits = 0;
        foreach ($items as $item) {
            $subtotal += $item->unitPrice * $item->quantity;
            $totalUnits += $item->quantity;
        }

        $tiers = [
            ['min' => 0,    'pct' => 0.00],
            ['min' => 25,   'pct' => 0.05],
            ['min' => 100,  'pct' => 0.10],
            ['min' => 500,  'pct' => 0.18],
            ['min' => 1000, 'pct' => 0.25],
        ];

        usort($tiers, static fn(array $a, array $b): int => $a['min'] <=> $b['min']);

        $applied = 0.0;
        foreach ($tiers as $tier) {
            if ($totalUnits >= $tier['min']) {
                $applied = $tier['pct'];
            }
        }

        $discount = $subtotal * $applied;
        $final = $subtotal - $discount;

        $this->audit->record('hardware_discount', [
            'customer' => $customerId,
            'units' => $totalUnits,
            'tier_pct' => $applied,
            'subtotal' => $subtotal,
            'final' => $final,
        ]);

        return round($final, 2);
    }
}
