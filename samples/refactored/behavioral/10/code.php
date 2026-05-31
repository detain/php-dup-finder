<?php

declare(strict_types=1);

namespace App\Shipping;

use Logistics\Catalog\Parcel;

final class ExpressShipmentPolicy
{
    /** @var list<callable(Parcel):bool> */
    private array $criteria;

    public function __construct()
    {
        $this->criteria = [
            static fn(Parcel $p): bool => $p->serviceLevel() === 'overnight',
            static fn(Parcel $p): bool => $p->serviceLevel() === 'two_day'
                && $p->weightGrams() <= 5_000,
            static fn(Parcel $p): bool => $p->isPrioritySender()
                && $p->weightGrams() <= 2_000,
            static fn(Parcel $p): bool => $p->destinationZone() === 'metro'
                && $p->paidExtraCents() >= 1_500,
            static fn(Parcel $p): bool => $p->hasPerishableContents()
                && $p->weightGrams() <= 8_000,
        ];
    }

    public function isExpress(Parcel $parcel): bool
    {
        foreach ($this->criteria as $matches) {
            if ($matches($parcel)) {
                return true;
            }
        }

        return false;
    }
}
