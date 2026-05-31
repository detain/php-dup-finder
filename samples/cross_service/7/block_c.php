<?php
declare(strict_types=1);

namespace Acme\AccountingService\Tax;

use Acme\AccountingService\Ledger\TaxRateBook;

final class TaxLiabilityPoster
{
    public function __construct(private readonly TaxRateBook $book)
    {
    }

    public function liabilityFor(array $orderFact): float
    {
        $exempt = ['groceries', 'prescription', 'baby-formula'];

        $taxablePart = 0.0;
        foreach ($orderFact['items'] as $row) {
            if (in_array($row['category'], $exempt, true)) {
                continue;
            }
            $taxablePart += $row['qty'] * $row['unit_price'];
        }

        $stateRate  = $this->book->state($orderFact['state']);
        $countyRate = $this->book->county($orderFact['state'], $orderFact['county']);
        $cityRate   = $this->book->city($orderFact['state'], $orderFact['county'], $orderFact['city']);

        $blended = $stateRate + $countyRate + $cityRate;

        $base = $taxablePart;
        if ($this->book->shipTaxable($orderFact['state'])) {
            $base += (float) $orderFact['shipping'];
        }

        return round($base * $blended, 2);
    }
}
