<?php

declare(strict_types=1);

namespace Acme\Travel\Booking;

use Acme\Travel\Booking\Dto\FareQuote;

final class HotelFareQuoter
{
    public function quote(float $nightlyRate, int $nights, string $cityCode): FareQuote
    {
        $occupancyTax = match ($cityCode) {
            'NYC' => 0.1475,
            'SF'  => 0.14,
            'LAS' => 0.135,
            default => 0.11,
        };

        $base = $nightlyRate * $nights;

        $cascade = [
            ['label' => 'occupancy_tax', 'rate' => $occupancyTax],
            ['label' => 'tourism_fee',   'rate' => 0.02],
            ['label' => 'resort_fee',    'rate' => 0.035],
            ['label' => 'cleaning',      'rate' => 0.025],
        ];

        $running = $base;
        $charges = [];
        foreach ($cascade as $step) {
            $amount = round($running * $step['rate'], 2);
            $charges[$step['label']] = $amount;
            $running += $amount;
        }

        return new FareQuote(
            nightlyRate: round($nightlyRate, 2),
            base: round($base, 2),
            charges: $charges,
            grandTotal: round($running, 2),
        );
    }
}
