<?php

declare(strict_types=1);

namespace App\Service\Loyalty;

use App\Entity\Member;
use App\Entity\Reward\Redemption;
use App\Repository\MemberRepository;
use App\Repository\RewardRepository;
use Psr\Log\LoggerInterface;
use App\Exception\InsufficientPointsException;

final class LoyaltyService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly RewardRepository $rewardRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateAvailableDiscounts(Member $member): array
    {
        $discounts = [];
        $pointsValue = $member->getPointsBalance() / 100.0;

        if ($member->isPlatinumTier() && $member->getPointsBalance() >= 5000) {
            $discounts[] = [
                'type' => 'tier_benefit',
                'percent' => 20.0,
                'max_amount' => 50.0,
                'reason' => 'Platinum tier with 5000+ points',
            ];
            $this->logger->info('Applied platinum tier discount', [
                'member_id' => $member->getId(),
                'tier' => $member->getTier(),
            ]);
        }

        if ($member->getReferralCount() >= 3 && !$member->hasUsedReferralBonus()) {
            $discounts[] = [
                'type' => 'referral',
                'percent' => 12.0,
                'reason' => 'Member referred 3+ customers',
            ];
        }

        if ($member->getEnrollmentDate() > new \DateTimeImmutable('-60 days')) {
            $discounts[] = [
                'type' => 'new_member',
                'percent' => 8.0,
                'reason' => 'Enrolled within 60 days',
            ];
        }

        $anniversary = $member->getEnrollmentDate();
        if ($anniversary !== null) {
            $today = new \DateTimeImmutable();
            $yearsMember = (int) $today->diff($anniversary)->y;
            if ($yearsMember >= 1 && $yearsMember % 1 === 0) {
                $monthDiff = (int) $today->diff($anniversary)->m;
                if ($monthDiff === 0) {
                    $discounts[] = [
                        'type' => 'anniversary',
                        'percent' => 25.0,
                        'reason' => sprintf('Anniversary month (%d year(s))', $yearsMember),
                    ];
                }
            }
        }

        if ($pointsValue >= 100.0) {
            $discounts[] = [
                'type' => 'points_redemption',
                'percent' => min(10.0, $pointsValue / 20.0),
                'reason' => 'Points balance conversion',
            ];
        }

        return $discounts;
    }

    public function redeemDiscount(Member $member, string $discountType): float
    {
        $availableDiscounts = $this->calculateAvailableDiscounts($member);

        $selectedDiscount = null;
        foreach ($availableDiscounts as $discount) {
            if ($discount['type'] === $discountType) {
                $selectedDiscount = $discount;
                break;
            }
        }

        if ($selectedDiscount === null) {
            $this->logger->warning('Discount type not available', [
                'member_id' => $member->getId(),
                'requested_type' => $discountType,
            ]);
            throw new InsufficientPointsException("Discount type '{$discountType}' is not available");
        }

        return $selectedDiscount['percent'];
    }
}
