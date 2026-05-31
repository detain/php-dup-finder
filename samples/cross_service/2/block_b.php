<?php
declare(strict_types=1);

namespace Acme\LoyaltyService\Domain;

use Acme\LoyaltyService\Repository\MemberRepository;
use Acme\LoyaltyService\Dto\OrderSnapshot;

final class PointsAccrualService
{
    public function __construct(private readonly MemberRepository $members)
    {
    }

    public function accrue(OrderSnapshot $snapshot): int
    {
        $member = $this->members->findById($snapshot->memberId);
        if ($member === null) {
            return 0;
        }

        $spend = 0.0;
        foreach ($snapshot->lineItems as $li) {
            if ($li['cat'] === 'gift-card') {
                continue;
            }
            $spend += $li['qty'] * $li['price'];
        }

        $base = (int) floor($spend);

        $factor = 1.0;
        switch (strtolower($member->tier)) {
            case 'silver':   $factor = 1.25; break;
            case 'gold':     $factor = 1.5;  break;
            case 'platinum': $factor = 2.0;  break;
        }

        $orderDate = new \DateTimeImmutable($snapshot->placedAt);
        if ((int) $orderDate->format('N') >= 6) {
            $factor *= 2.0;
        }

        $earned = (int) floor($base * $factor);
        $earned = min($earned, 50000);

        $this->members->grantPoints($member->id, $earned);
        return $earned;
    }
}
