<?php

declare(strict_types=1);

namespace Acme\Card\Loyalty;

use Acme\Card\Loyalty\Dto\Transaction;
use Acme\Card\Loyalty\Dto\RewardEntry;

final class CreditCardRewardsAccrual
{
    private const CARD_TIER_BOOST = [
        'standard'  => 1.0,
        'preferred' => 1.2,
        'signature' => 1.5,
        'reserve'   => 2.5,
    ];

    private const MCC_BUCKET_RATES = [
        'travel'     => 3.0,
        'dining'     => 4.0,
        'groceries'  => 2.0,
        'gas'        => 2.5,
        'everything' => 1.0,
    ];

    /**
     * @param Transaction[] $transactions
     * @return RewardEntry[]
     */
    public function accrue(array $transactions, string $cardTier): array
    {
        $boost = self::CARD_TIER_BOOST[$cardTier] ?? 1.0;
        $rewards = [];

        foreach ($transactions as $txn) {
            $rate = self::MCC_BUCKET_RATES[$txn->mccBucket] ?? 0.0;
            $base = $txn->amountUsd * $rate;
            $points = (int) floor($base * $boost);

            $rewards[] = new RewardEntry(
                transactionId: $txn->id,
                category: $txn->mccBucket,
                pointsEarned: $points,
            );
        }

        return $rewards;
    }
}
