<?php

declare(strict_types=1);

namespace Acme\Telco\Billing;

use Acme\Telco\Billing\Dto\InvoiceBreakdown;

final class WirelessLineBilling
{
    public function buildInvoice(float $planPrice, string $jurisdiction): InvoiceBreakdown
    {
        $federalUsf = 0.064;
        $stateTax = match ($jurisdiction) {
            'IL' => 0.0625,
            'WA' => 0.065,
            'FL' => 0.06,
            default => 0.055,
        };

        $cascade = [
            ['label' => 'state_tax',       'rate' => $stateTax],
            ['label' => 'federal_usf',     'rate' => $federalUsf],
            ['label' => 'regulatory_fee',  'rate' => 0.02],
            ['label' => '911_surcharge',   'rate' => 0.007],
        ];

        $running = $planPrice;
        $items = [];
        foreach ($cascade as $step) {
            $charge = round($running * $step['rate'], 2);
            $items[$step['label']] = $charge;
            $running += $charge;
        }

        return new InvoiceBreakdown(
            base: round($planPrice, 2),
            lineItems: $items,
            total: round($running, 2),
        );
    }
}
