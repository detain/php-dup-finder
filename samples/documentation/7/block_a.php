<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Policy;

use App\Domain\Subscription\Entity\Subscription;
use App\Domain\Subscription\ValueObject\SubscriptionPlan;
use DateTimeImmutable;

/**
 * Subscription Cancellation Policy
 *
 * This policy governs how subscription cancellations are processed.
 * The rules are documented in internal ticket #OPS-3421 and mirrored in
 * the customer support knowledge base article KB-452.
 *
 * CANCELLATION TIMING RULES:
 * - Monthly subscriptions: Can cancel at any time, access continues to period end
 * - Annual subscriptions: Can cancel at any time, access continues to period end
 * - Lifetime subscriptions: Non-refundable, access continues indefinitely
 *
 * REFUND ELIGIBILITY (per finance policy FIN-2024-003):
 * - Monthly subscriptions: No refund for current period
 * - Annual subscriptions: Prorated refund for unused months, minus $25 processing fee
 * - Annual subscriptions cancelled within 30 days: Full refund, no processing fee
 * - Lifetime subscriptions: No refund under any circumstances
 * - If cancelled due to service violation by merchant: Full refund
 *
 * DOWNGRADE RULES:
 * - Can downgrade plan at any time
 * - Downgrade takes effect at next billing cycle
 * - No partial credits for current period
 * - Must be on current plan for at least 30 days before downgrading
 *
 * CANCELLATION REASONS COLLECTED:
 * - too_expensive: Customer finds price too high
 * - missing_features: Needed features not available
 * - switching_competitor: Moving to competitor service
 * - rarely_used: Not using the service enough
 * - technical_issues: Too many technical problems
 * - customer_service: Poor customer service experience
 * - other: Other reason (requires text explanation)
 *
 * REACTIVATION RULES:
 * - Cancelled subscriptions can be reactivated within 30 days
 * - After 30 days, subscription is permanently cancelled
 * - Reactivation restores original plan and pricing
 * - If plan is no longer available, closest equivalent is offered
 *
 * See also: docs/policies/subscription-cancellation.md and Confluence OPS-3421
 */
class CancellationPolicy
{
    private const PROCESSING_FEE_ANNUAL = 25.00;
    private const GRACE_PERIOD_DAYS = 30;
    private const MIN_PLAN_DURATION_DAYS = 30;

    /**
     * Process a subscription cancellation request.
     *
     * @param Subscription $subscription The subscription to cancel
     * @param string $reason Cancellation reason code
     * @param string|null $reasonText Additional explanation if reason is 'other'
     * @param DateTimeImmutable $cancelledAt When the cancellation was requested
     * @return CancellationResult The outcome of the cancellation
     */
    public function processCancellation(
        Subscription $subscription,
        string $reason,
        ?string $reasonText = null,
        DateTimeImmutable $cancelledAt = null
    ): CancellationResult {

        $cancelledAt = $cancelledAt ?? new DateTimeImmutable();

        if ($subscription->isLifetime()) {
            return new CancellationResult(
                success: true,
                accessUntil: null,
                refundAmount: 0.0,
                refundEligible: false,
                message: 'Lifetime subscriptions cannot be cancelled. Access continues indefinitely.'
            );
        }

        if ($subscription->isPermanentlyCancelled()) {
            return new CancellationResult(
                success: false,
                accessUntil: null,
                refundAmount: 0.0,
                refundEligible: false,
                message: 'This subscription has already been permanently cancelled.'
            );
        }

        $accessUntil = $subscription->getCurrentPeriodEnd();
        $refundAmount = $this->calculateRefundAmount($subscription, $cancelledAt);
        $refundEligible = $refundAmount > 0;

        $subscription->markAsCancelled(
            $reason,
            $reasonText,
            $cancelledAt,
            $accessUntil
        );

        return new CancellationResult(
            success: true,
            accessUntil: $accessUntil,
            refundAmount: $refundAmount,
            refundEligible: $refundEligible,
            message: $refundEligible
                ? "Your subscription has been cancelled. A refund of \${$refundAmount} will be processed."
                : "Your subscription has been cancelled. You retain access until {$accessUntil->format('F j, Y')}."
        );
    }

    /**
     * Calculate refund amount based on subscription type and cancellation timing.
     * Refund rules are documented in finance policy FIN-2024-003.
     */
    private function calculateRefundAmount(Subscription $subscription, DateTimeImmutable $cancelledAt): float
    {
        if (!$subscription->isAnnual()) {
            return 0.0;
        }

        $daysSinceStart = $subscription->getCurrentPeriodStart()->diff($cancelledAt)->days;
        $totalDays = $subscription->getCurrentPeriodStart()->diff($subscription->getCurrentPeriodEnd())->days;
        $unusedDays = max(0, $totalDays - $daysSinceStart);

        $dailyRate = $subscription->getAnnualPrice() / $totalDays;
        $proratedRefund = $unusedDays * $dailyRate;

        if ($daysSinceStart <= self::GRACE_PERIOD_DAYS) {
            return $proratedRefund;
        }

        return max(0, $proratedRefund - self::PROCESSING_FEE_ANNUAL);
    }

    /**
     * Determine if a subscription can be downgraded to a different plan.
     * Downgrade rules are documented in the subscription management guide.
     */
    public function canDowngrade(Subscription $subscription, SubscriptionPlan $targetPlan): bool
    {
        if ($subscription->isLifetime()) {
            return false;
        }

        $planDuration = $subscription->getCurrentPeriodStart()->diff(new DateTimeImmutable())->days;

        if ($planDuration < self::MIN_PLAN_DURATION_DAYS) {
            return false;
        }

        if ($targetPlan->getTier() >= $subscription->getPlan()->getTier()) {
            return false;
        }

        return true;
    }

    /**
     * Process reactivation of a previously cancelled subscription.
     * Reactivation rules are documented in the support runbook SR-2024-034.
     */
    public function processReactivation(Subscription $subscription): ReactivationResult
    {
        if (!$subscription->isCancelled()) {
            return new ReactivationResult(
                success: false,
                message: 'This subscription is not cancelled and cannot be reactivated.'
            );
        }

        $daysSinceCancellation = $subscription->getCancelledAt()->diff(new DateTimeImmutable())->days;

        if ($daysSinceCancellation > self::GRACE_PERIOD_DAYS) {
            return new ReactivationResult(
                success: false,
                message: "This subscription cannot be reactivated after {$daysSinceCancellation} days. " .
                         "Please create a new subscription."
            );
        }

        if (!$subscription->getPlan()->isAvailable()) {
            return new ReactivationResult(
                success: false,
                message: "Your original plan ({$subscription->getPlan()->getName()}) " .
                         "is no longer available. Please select a new plan."
            );
        }

        $subscription->reactivate();

        return new ReactivationResult(
            success: true,
            message: 'Your subscription has been reactivated successfully. ' .
                     'Your original plan and pricing have been restored.'
        );
    }
}
