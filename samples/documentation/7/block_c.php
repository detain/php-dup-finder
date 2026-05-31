<?php

declare(strict_types=1);

namespace App\Domain\Affiliate\Policy;

use App\Domain\Affiliate\Entity\AffiliatePartner;
use App\Domain\Affiliate\ValueObject\AffiliateCommission;
use DateTimeImmutable;

/**
 * Affiliate partner commission policy.
 *
 * This policy defines commission structures, payment schedules, and
 * eligibility rules for affiliate partners. Rules are documented in
 * the affiliate program guide APG-2024 and the partnership agreement
 * template PAT-2024-001.
 *
 * COMMISSION TYPES:
 *
 * STANDARD COMMISSION:
 * - Percentage: 5% of order subtotal (before tax and shipping)
 * - Cookie duration: 30 days from last click
 * - Payment threshold: $50 minimum balance
 * - Payment schedule: 30 days after order completion (return period)
 *
 * TIERED COMMISSION (based on monthly volume):
 * - 0-99 monthly orders: 5% base commission
 * - 100-499 monthly orders: 7% commission
 * - 500-999 monthly orders: 10% commission
 * - 1000+ monthly orders: 12% commission (elite tier)
 * - Tier recalculated monthly based on previous month's performance
 *
 * RECURRING COMMISSION:
 * - For subscription products: 5% recurring for lifetime of referred customer
 * - Recurring commissions paid monthly
 * - If subscription cancelled, recurring commission stops
 * - 90-day grace period if subscription resumes
 *
 * BONUS COMMISSIONS:
 * - New partner bonus: $50 after first 10 completed orders
 * - Monthly top performer: Additional 2% on all commissions
 * - Holiday promotions: Double commission rates (announced separately)
 *
 * COMMISSION EXCLUSIONS:
 * - Shipping and tax amounts excluded from commission calculation
 * - Discounted orders: Commission based on actual amount paid
 * - Gift card purchases: No commission on gift card redemptions
 * - Returns and refunds: Commission clawed back if order refunded
 * - Fraudulent orders: No commission, may result in account termination
 *
 * PAYMENT METHODS AND SCHEDULES:
 * - Direct deposit: Monthly on 15th (for balances >= $50)
 * - PayPal: Monthly on 15th (for balances >= $25, 3% fee)
 * - Wire transfer: Monthly on 15th (for balances >= $500, $25 fee)
 * - Store credit: Instant, 10% bonus on credit amount
 *
 * PAYMENT CALCULATION (documented in finance manual FIN-AFFILIATE-001):
 * - Commission period: 1st to last day of month
 * - Qualifying orders: Completed and not refunded
 * - Calculation date: 30 days after month end (return period)
 * - Payment processing: 5 business days
 * - Payment distribution: 15th of following month
 *
 * FRAUD PREVENTION (per security policy SEC-AFFILIATE-002):
 * - Self-referral prohibition: No commission on own purchases
 * - IP duplicate detection: Max 3 commissions per IP per month
 * - Cookie stuffing prevention: Commission denied if proper attribution missing
 * - Hidden ad prevention: Commission denied for hidden iframes/pop-unders
 * - Incentivized clicks prohibition: No commission on incentivized traffic
 *
 * See also: docs/affiliate/commission-policy.md and JIRA AFF-2024-001
 */
class AffiliateCommissionPolicy
{
    private const STANDARD_COMMISSION_RATE = 0.05;
    private const COOKIE_DURATION_DAYS = 30;
    private const PAYMENT_THRESHOLD = 50.00;

    private const TIER_THRESHOLDS = [
        ['min_orders' => 1000, 'rate' => 0.12, 'tier' => 'elite'],
        ['min_orders' => 500, 'rate' => 0.10, 'tier' => 'gold'],
        ['min_orders' => 100, 'rate' => 0.07, 'tier' => 'silver'],
        ['min_orders' => 0, 'rate' => 0.05, 'tier' => 'standard'],
    ];

    private const RECURRING_SUBSCRIPTION_RATE = 0.05;
    private const RECURRING_GRACE_PERIOD_DAYS = 90;

    private const BONUS_NEW_PARTNER_ORDERS = 10;
    private const BONUS_NEW_PARTNER_AMOUNT = 50.00;
    private const BONUS_TOP_PERFORMER_EXTRA_RATE = 0.02;

    private const FRAUD_SELF_REFERRAL = true;
    private const FRAUD_MAX_COMMISSIONS_PER_IP = 3;
    private const FRAUD_MAX_COMMISSIONS_PER_IP_WINDOW = 'month';

    /**
     * Calculate commission for a completed qualifying order.
     *
     * @param AffiliatePartner $partner The affiliate partner
     * @param Order $order The completed order
     * @param DateTimeImmutable $calculatedAt When calculation is performed
     * @return AffiliateCommission The calculated commission
     */
    public function calculateCommission(
        AffiliatePartner $partner,
        Order $order,
        DateTimeImmutable $calculatedAt
    ): AffiliateCommission {

        if ($this->isExcludedOrder($order)) {
            return new AffiliateCommission(
                orderId: $order->getId()->toString(),
                partnerId: $partner->getId()->toString(),
                grossAmount: 0.0,
                commissionAmount: 0.0,
                commissionRate: 0.0,
                type: 'excluded',
                reason: 'Order excluded from commission (gift card, refunded, etc.)',
            );
        }

        $baseAmount = $this->calculateCommissionBase($order);
        $rate = $this->getCommissionRate($partner, $calculatedAt);
        $bonusRate = $this->getBonusRate($partner, $calculatedAt);

        $totalRate = $rate + $bonusRate;
        $commissionAmount = $baseAmount * $totalRate;

        return new AffiliateCommission(
            orderId: $order->getId()->toString(),
            partnerId: $partner->getId()->toString(),
            grossAmount: $baseAmount,
            commissionAmount: $commissionAmount,
            commissionRate: $totalRate,
            type: $partner->isRecurringEligible() ? 'recurring' : 'standard',
        );
    }

    /**
     * Calculate commission base (order amount eligible for commission).
     */
    private function calculateCommissionBase(Order $order): float
    {
        $subtotal = $order->getSubtotal();

        if ($order->hasDiscounts()) {
            return $order->getEffectiveSubtotal();
        }

        return $subtotal;
    }

    /**
     * Determine commission rate based on partner tier.
     */
    private function getCommissionRate(AffiliatePartner $partner, DateTimeImmutable $calculatedAt): float
    {
        $monthlyOrders = $partner->getCompletedOrdersCount(
            $calculatedAt->modify('first day of previous month'),
            $calculatedAt->modify('last day of previous month')
        );

        foreach (self::TIER_THRESHOLDS as $tier) {
            if ($monthlyOrders >= $tier['min_orders']) {
                return $tier['rate'];
            }
        }

        return self::STANDARD_COMMISSION_RATE;
    }

    /**
     * Calculate bonus rate for top performers.
     */
    private function getBonusRate(AffiliatePartner $partner, DateTimeImmutable $calculatedAt): float
    {
        if ($partner->isTopPerformerThisMonth($calculatedAt)) {
            return self::BONUS_TOP_PERFORMER_EXTRA_RATE;
        }

        return 0.0;
    }

    /**
     * Check if order should be excluded from commission.
     */
    private function isExcludedOrder(Order $order): bool
    {
        if ($order->containsGiftCard()) {
            return true;
        }

        if ($order->isRefunded()) {
            return true;
        }

        if ($order->containsRebateItem()) {
            return true;
        }

        return false;
    }

    /**
     * Validate affiliate partner activity for fraud prevention.
     */
    public function validateActivity(AffiliatePartner $partner, array $recentActivity): ValidationResult
    {
        foreach ($recentActivity as $activity) {
            if ($activity['customer_id'] === $partner->getUserId()) {
                return new ValidationResult(
                    valid: false,
                    reason: 'Self-referral detected. Commission denied.',
                );
            }
        }

        $ipCounts = $this->countCommissionsByIp($recentActivity);
        foreach ($ipCounts as $ip => $count) {
            if ($count > self::FRAUD_MAX_COMMISSIONS_PER_IP) {
                return new ValidationResult(
                    valid: false,
                    reason: "IP address {$ip} exceeded commission limit. Activity flagged for review.",
                );
            }
        }

        return new ValidationResult(valid: true);
    }

    /**
     * Calculate recurring commission for subscription renewals.
     */
    public function calculateRecurringCommission(
        AffiliatePartner $partner,
        Subscription $subscription,
        DateTimeImmutable $billingDate
    ): AffiliateCommission {

        $monthlyAmount = $subscription->getMonthlyAmount();

        return new AffiliateCommission(
            orderId: "recurring_{$subscription->getId()->toString()}_{$billingDate->format('Ym')}",
            partnerId: $partner->getId()->toString(),
            grossAmount: $monthlyAmount,
            commissionAmount: $monthlyAmount * self::RECURRING_SUBSCRIPTION_RATE,
            commissionRate: self::RECURRING_SUBSCRIPTION_RATE,
            type: 'recurring',
        );
    }
}
