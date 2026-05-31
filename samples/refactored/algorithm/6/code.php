<?php

declare(strict_types=1);

namespace Acme\Common\Pricing;

final class CompoundCascadeCalculator
{
    /**
     * @param list<array{label:string, rate:float}> $cascade
     * @return array{base:float, lineItems:array<string,float>, total:float}
     */
    public function apply(float $base, array $cascade): array
    {
        $running = $base;
        $lineItems = [];
        foreach ($cascade as $step) {
            $amount = round($running * $step['rate'], 2);
            $lineItems[$step['label']] = $amount;
            $running += $amount;
        }

        return [
            'base' => round($base, 2),
            'lineItems' => $lineItems,
            'total' => round($running, 2),
        ];
    }
}
