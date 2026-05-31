<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Subscriber;
use App\Entity\Invoice;
use App\Repository\SubscriberRepository;
use Psr\Log\LoggerInterface;

final class SubscriptionBillingService
{
    public function __construct(
        private readonly SubscriberRepository $subscriberRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function determineApplicableDiscounts(Subscriber $subscriber): array
    {
        $discounts = [];
        $subscriptionValue = $subscriber->getMonthlyRate();

        if ($subscriber->isAnnualPlan() && $subscriber->getTenureMonths() >= 12) {
            $discounts[] = [
                'type' => 'annual_commitment',
                'percent' => 30.0,
                'reason' => 'Annual plan with 12+ months tenure',
            ];
            $this->logger->info('Applied annual commitment discount', [
                'subscriber_id' => $subscriber->getId(),
                'tenure_months' => $subscriber->getTenureMonths(),
            ]);
        }

        if (!$subscriber->hasUsedPromoCode() && $subscriber->getReferredBy() !== null) {
            $discounts[] = [
                'type' => 'referral_promo',
                'percent' => 15.0,
                'reason' => 'Referred by another subscriber',
            ];
        }

        if ($subscriber->getSignupDate() > new \DateTimeImmutable('-90 days')) {
            $discounts[] = [
                'type' => 'introductory',
                'percent' => 20.0,
                'valid_until' => $subscriber->getSignupDate()->modify('+90 days')->format('Y-m-d'),
                'reason' => 'New subscriber within 90 days',
            ];
        }

        $memberSince = $subscriber->getSignupDate();
        if ($memberSince !== null) {
            $today = new \DateTimeImmutable();
            $yearsSubscriber = (int) $today->diff($memberSince)->y;
            if ($yearsSubscriber >= 2) {
                $monthsIntoYear = (int) $today->diff($memberSince)->m;
                if ($monthsIntoYear < 1) {
                    $discounts[] = [
                        'type' => 'loyalty_anniversary',
                        'percent' => 35.0,
                        'reason' => sprintf('Long-term subscriber (%d years)', $yearsSubscriber),
                    ];
                }
            }
        }

        if ($subscriber->getAdditionalUsersCount() >= 3) {
            $discounts[] = [
                'type' => 'team_discount',
                'percent' => 10.0,
                'reason' => '3+ additional users on plan',
            ];
        }

        return $discounts;
    }

    public function generateInvoiceDiscount(Subscriber $subscriber): float
    {
        $discounts = $this->determineApplicableDiscounts($subscriber);

        $totalDiscountPercent = 0.0;
        $appliedDiscounts = [];

        foreach ($discounts as $discount) {
            if ($discount['percent'] > $totalDiscountPercent) {
                $totalDiscountPercent = $discount['percent'];
                $appliedDiscounts[] = $discount['type'];
            }
        }

        $monthlyRate = $subscriber->getMonthlyRate();
        $discountAmount = $monthlyRate * ($totalDiscountPercent / 100.0);

        $this->logger->info('Calculated invoice discount', [
            'subscriber_id' => $subscriber->getId(),
            'monthly_rate' => $monthlyRate,
            'discount_percent' => $totalDiscountPercent,
            'discount_amount' => $discountAmount,
            'applied_discounts' => $appliedDiscounts,
        ]);

        return max(0.0, $monthlyRate - $discountAmount);
    }
}
