<?php

declare(strict_types=1);

namespace Acme\Airline\Loyalty;

use Acme\Airline\Loyalty\Dto\Booking;
use Acme\Airline\Loyalty\Dto\PointsLedgerEntry;

final class FrequentFlyerAccrual
{
    private const TIER_MULTIPLIERS = [
        'blue'     => 1.0,
        'silver'   => 1.25,
        'gold'     => 1.5,
        'platinum' => 2.0,
    ];

    private const FARE_CLASS_RATES = [
        'economy'  => 5.0,
        'premium'  => 7.5,
        'business' => 12.0,
        'first'    => 15.0,
    ];

    /**
     * @param Booking[] $bookings
     * @return PointsLedgerEntry[]
     */
    public function accrue(array $bookings, string $tier): array
    {
        $tierMult = self::TIER_MULTIPLIERS[$tier] ?? 1.0;
        $entries = [];

        foreach ($bookings as $booking) {
            $earnRate = self::FARE_CLASS_RATES[$booking->fareClass] ?? 0.0;
            $base = $booking->basePrice * $earnRate;
            $points = (int) floor($base * $tierMult);

            $entries[] = new PointsLedgerEntry(
                bookingId: $booking->id,
                category: $booking->fareClass,
                earned: $points,
            );
        }

        return $entries;
    }
}
