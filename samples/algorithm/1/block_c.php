<?php

declare(strict_types=1);

namespace Acme\Wholesale\Pricing;

use Acme\Wholesale\Cart\LineItem;
use Acme\Wholesale\Metrics\Counter;

final class PrintShopVolumeDiscounter
{
    public function __construct(private readonly Counter $metrics)
    {
    }

    /**
     * @param LineItem[] $items
     */
    public function calculate(array $items, string $shopId): float
    {
        $gross = 0.0;
        $sheets = 0;
        foreach ($items as $item) {
            $gross += $item->unitPrice * $item->quantity;
            $sheets += $item->quantity;
        }

        $schedule = [
            ['min_sheets' => 0,     'discount' => 0.00],
            ['min_sheets' => 500,   'discount' => 0.07],
            ['min_sheets' => 2500,  'discount' => 0.12],
            ['min_sheets' => 10000, 'discount' => 0.20],
            ['min_sheets' => 50000, 'discount' => 0.30],
        ];

        usort($schedule, static fn(array $a, array $b): int => $a['min_sheets'] <=> $b['min_sheets']);

        $discountRate = 0.0;
        foreach ($schedule as $row) {
            if ($sheets >= $row['min_sheets']) {
                $discountRate = $row['discount'];
            }
        }

        $savings = $gross * $discountRate;
        $finalPrice = $gross - $savings;

        $this->metrics->increment('printshop.discount.tier.' . (int) ($discountRate * 100));

        return round($finalPrice, 2);
    }
}
