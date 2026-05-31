<?php

declare(strict_types=1);

namespace Fulfillment\Tier;

use Logistics\Catalog\Parcel;

final class ExpressScorer
{
    private const THRESHOLD = 1;

    public function isExpress(Parcel $parcel): bool
    {
        $score = 0;

        $score += $parcel->serviceLevel() === 'overnight' ? 10 : 0;

        $score += ($parcel->serviceLevel() === 'two_day' && $parcel->weightGrams() <= 5_000) ? 10 : 0;

        $score += ($parcel->isPrioritySender() && $parcel->weightGrams() <= 2_000) ? 10 : 0;

        $score += ($parcel->destinationZone() === 'metro' && $parcel->paidExtraCents() >= 1_500) ? 10 : 0;

        $score += ($parcel->hasPerishableContents() && $parcel->weightGrams() <= 8_000) ? 10 : 0;

        return $score >= self::THRESHOLD;
    }
}
