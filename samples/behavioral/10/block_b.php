<?php

declare(strict_types=1);

namespace Carrier\Routing;

use Logistics\Catalog\Parcel;

final class ExpressRulesEngine
{
    /** @var list<array{name:string, predicate:callable(Parcel):bool}> */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            ['name' => 'overnight',          'predicate' => static fn(Parcel $p): bool => $p->serviceLevel() === 'overnight'],
            ['name' => 'two_day_light',      'predicate' => static fn(Parcel $p): bool => $p->serviceLevel() === 'two_day' && $p->weightGrams() <= 5_000],
            ['name' => 'priority_small',     'predicate' => static fn(Parcel $p): bool => $p->isPrioritySender() && $p->weightGrams() <= 2_000],
            ['name' => 'metro_paid',         'predicate' => static fn(Parcel $p): bool => $p->destinationZone() === 'metro' && $p->paidExtraCents() >= 1_500],
            ['name' => 'perishable_medium', 'predicate' => static fn(Parcel $p): bool => $p->hasPerishableContents() && $p->weightGrams() <= 8_000],
        ];
    }

    public function classify(Parcel $parcel): bool
    {
        foreach ($this->rules as $rule) {
            if (($rule['predicate'])($parcel)) {
                return true;
            }
        }
        return false;
    }
}
