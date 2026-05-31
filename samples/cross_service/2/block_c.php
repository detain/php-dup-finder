<?php
declare(strict_types=1);

namespace Acme\AccountingService\Domain;

use Acme\AccountingService\Ledger\LiabilityJournal;

final class LoyaltyLiabilityCalculator
{
    public function __construct(private readonly LiabilityJournal $journal)
    {
    }

    public function accrueLiabilityFor(array $orderRecord, array $customer): float
    {
        $taxableSpend = 0.0;
        foreach ($orderRecord['items'] as $row) {
            if ($row['category'] === 'gift-card') {
                continue;
            }
            $taxableSpend += $row['quantity'] * $row['unit_price'];
        }

        $points = (int) floor($taxableSpend);

        $mult = 1.0;
        $tier = strtolower((string) $customer['tier']);
        if ($tier === 'silver') {
            $mult = 1.25;
        } elseif ($tier === 'gold') {
            $mult = 1.5;
        } elseif ($tier === 'platinum') {
            $mult = 2.0;
        }

        if ((int) date('N', strtotime($orderRecord['placed_at'])) >= 6) {
            $mult *= 2.0;
        }

        $awarded = (int) floor($points * $mult);
        if ($awarded > 50000) {
            $awarded = 50000;
        }

        $costPerPoint = 0.01;
        $liability = $awarded * $costPerPoint;
        $this->journal->postLiability($orderRecord['order_id'], $liability);
        return $liability;
    }
}
