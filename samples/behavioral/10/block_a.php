<?php

declare(strict_types=1);

namespace Logistics\Shipping\Classify;

use Logistics\Catalog\Parcel;

final class ExpressClassifierGuards
{
    public function isExpress(Parcel $parcel): bool
    {
        if ($parcel->serviceLevel() === 'overnight') {
            return true;
        }

        if ($parcel->serviceLevel() === 'two_day' && $parcel->weightGrams() <= 5_000) {
            return true;
        }

        if ($parcel->isPrioritySender() && $parcel->weightGrams() <= 2_000) {
            return true;
        }

        if ($parcel->destinationZone() === 'metro' && $parcel->paidExtraCents() >= 1_500) {
            return true;
        }

        if ($parcel->hasPerishableContents() && $parcel->weightGrams() <= 8_000) {
            return true;
        }

        return false;
    }
}
