<?php

declare(strict_types=1);

namespace Acme\Grocery\Loyalty;

use Acme\Grocery\Loyalty\Dto\CartLine;
use Acme\Grocery\Loyalty\Dto\PointsCreditEntry;

final class SupermarketPointsAccrual
{
    private const MEMBER_LEVEL_BONUS = [
        'basic'   => 1.0,
        'plus'    => 1.10,
        'rewards' => 1.25,
        'vip'     => 1.50,
    ];

    private const DEPARTMENT_EARN = [
        'produce'  => 4.0,
        'dairy'    => 3.0,
        'pantry'   => 2.0,
        'household'=> 1.5,
        'alcohol'  => 0.5,
    ];

    /**
     * @param CartLine[] $lines
     * @return PointsCreditEntry[]
     */
    public function accrue(array $lines, string $memberLevel): array
    {
        $bonus = self::MEMBER_LEVEL_BONUS[$memberLevel] ?? 1.0;
        $credits = [];

        foreach ($lines as $line) {
            $rate = self::DEPARTMENT_EARN[$line->department] ?? 0.0;
            $base = $line->amount * $rate;
            $points = (int) floor($base * $bonus);

            $credits[] = new PointsCreditEntry(
                receiptLineId: $line->id,
                category: $line->department,
                points: $points,
            );
        }

        return $credits;
    }
}
