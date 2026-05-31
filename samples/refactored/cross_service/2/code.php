<?php
declare(strict_types=1);

namespace Acme\Common\Loyalty;

/**
 * Published as acme/loyalty-rules — checkout, loyalty, and accounting all consume
 * the same authoritative accrual formula. Each service maps its own data shape
 * into LoyaltyAccrualInput and trusts the resulting integer point total.
 */
final class PointsAccrualPolicy
{
    public const CAP_PER_ORDER = 50000;
    public const POINT_LIABILITY_USD = 0.01;

    /** @var array<string,float> */
    private const TIER_MULTIPLIERS = [
        'silver'   => 1.25,
        'gold'     => 1.5,
        'platinum' => 2.0,
    ];

    public function award(LoyaltyAccrualInput $input): int
    {
        $eligible = 0.0;
        foreach ($input->lineItems as $line) {
            if ($line->category === 'gift-card') {
                continue;
            }
            $eligible += $line->quantity * $line->unitPrice;
        }

        $base = (int) floor($eligible);
        $multiplier = self::TIER_MULTIPLIERS[strtolower($input->tier)] ?? 1.0;
        if ((int) $input->placedAt->format('N') >= 6) {
            $multiplier *= 2.0;
        }

        return min((int) floor($base * $multiplier), self::CAP_PER_ORDER);
    }

    public function liabilityFor(int $points): float
    {
        return round($points * self::POINT_LIABILITY_USD, 2);
    }
}
