<?php
declare(strict_types=1);

namespace Billing\Subscriptions;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;

/**
 * Subscription billing service handling recurring payments and renewals.
 *
 * Business Rules:
 * - Subscriptions renew automatically on the billing date unless cancelled
 * - Failed payments trigger retry on days 1, 3, and 7 after the billing date
 * - Account suspension occurs 14 days after initial payment failure
 * - Prorated refunds are calculated based on days used in current period
 * - Annual subscriptions receive a 15% discount vs monthly billing
 * - Referral credits expire 90 days after being awarded
 * - Tier upgrades take effect immediately with prorated charge
 *
 * @see https://internal-docs/wiki/subscription-billing-rules
 */
final class SubscriptionBillingService
{
    /**
     * Process subscription renewal for all due subscriptions.
     *
     * This method runs daily via cron job and handles:
     * 1. Identifying subscriptions where billing_date <= today
     * 2. Attempting to charge the customer's payment method
     * 3. On success: recording payment, updating next billing date
     * 4. On failure: scheduling retry attempts per the retry policy
     * 5. After max retries: suspending account and notifying customer
     *
     * The retry policy follows these intervals:
     * - Day 1 after failed charge: first retry
     * - Day 3 after failed charge: second retry
     * - Day 7 after failed charge: final retry, then suspend
     *
     * @return array{processed: int, succeeded: int, failed: int, retried: int}
     */
    public function processRenewals(): array
    {
        $dueSubscriptions = $this->entityManager
            ->getRepository(Subscription::class)
            ->findDueForRenewal();

        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'retried' => 0];

        foreach ($dueSubscriptions as $subscription) {
            $stats['processed']++;

            $result = $this->processRenewal($subscription);

            match ($result['status']) {
                'success' => $stats['succeeded']++,
                'retry' => $stats['retried']++,
                'failed' => $stats['failed']++
            };
        }

        $this->logger->info('Renewal processing completed', $stats);

        return $stats;
    }

    /**
     * Calculate prorated refund when subscription is cancelled mid-period.
     *
     * The refund amount is calculated as:
     * (days_remaining / days_in_period) * amount_paid
     *
     * Days remaining is calculated from cancellation date to the
     * end of the current billing period. The result is rounded
     * to 2 decimal places.
     *
     * Example:
     * - Period: 30 days, Amount: $30.00
     * - Cancelled on day 10 (20 days remaining)
     * - Refund: (20/30) * $30.00 = $20.00
     *
     * @param Subscription $subscription The subscription to calculate refund for
     * @return float The prorated refund amount in dollars
     */
    public function calculateProratedRefund(Subscription $subscription): float
    {
        $periodStart = $subscription->getCurrentPeriodStart();
        $periodEnd = $subscription->getCurrentPeriodEnd();
        $billingAmount = $subscription->getBillingAmount();

        $totalDays = $periodStart->diff($periodEnd)->days;
        $daysRemaining = (new \DateTimeImmutable())->diff($periodEnd)->days;

        if ($daysRemaining <= 0) {
            return 0.0;
        }

        $proratedAmount = ($daysRemaining / $totalDays) * $billingAmount->getAmount();

        $this->logger->info('Calculated prorated refund', [
            'subscription_id' => $subscription->getId(),
            'total_days' => $totalDays,
            'days_remaining' => $daysRemaining,
            'prorated_amount' => round($proratedAmount, 2)
        ]);

        return round($proratedAmount, 2);
    }

    /**
     * Upgrade subscription to a new plan tier.
     *
     * When upgrading mid-billing-period:
     * 1. Calculate unused portion of current plan
     * 2. Apply prorated credit to new plan
     * 3. Charge difference immediately
     * 4. Update subscription to new tier
     * 5. Adjust next billing date to reflect upgrade
     *
     * Tier hierarchy (lowest to highest):
     * basic -> standard -> premium -> enterprise
     *
     * @param Subscription $subscription The subscription to upgrade
     * @param string $newTier The target tier to upgrade to
     * @return UpgradeResult Details of the upgrade transaction
     * @throws InvalidUpgradeException When target tier is invalid or not a tier upgrade
     */
    public function upgradeTier(Subscription $subscription, string $newTier): UpgradeResult
    {
        $currentTier = $subscription->getTier();
        $tierHierarchy = ['basic' => 1, 'standard' => 2, 'premium' => 3, 'enterprise' => 4];

        if (!isset($tierHierarchy[$newTier])) {
            throw new InvalidUpgradeException("Invalid tier: {$newTier}");
        }

        if ($tierHierarchy[$newTier] <= $tierHierarchy[$currentTier]) {
            throw new InvalidUpgradeException(
                "Cannot downgrade from {$currentTier} to {$newTier}. Use downgradeTier() instead."
            );
        }

        // Calculate unused time on current plan
        $creditAmount = $this->calculateUnusedCredit($subscription);

        // Get new plan pricing
        $newPlan = $this->entityManager->getRepository(Plan::class)
            ->findOneBy(['tier' => $newTier, 'interval' => $subscription->getBillingInterval()]);

        $chargeAmount = max(0, $newPlan->getPrice() - $creditAmount);

        // Process immediate charge if there's a difference
        if ($chargeAmount > 0) {
            $paymentResult = $this->processPayment($subscription, $chargeAmount);
            if (!$paymentResult->isSuccessful()) {
                return UpgradeResult::failure($paymentResult->getError());
            }
        }

        // Update subscription
        $subscription->setTier($newTier);
        $subscription->setBillingAmount($newPlan->getPrice());
        $subscription->setUpgradedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return UpgradeResult::success($creditAmount, $chargeAmount, $newTier);
    }

    private function calculateUnusedCredit(Subscription $subscription): float
    {
        $periodStart = $subscription->getCurrentPeriodStart();
        $periodEnd = $subscription->getCurrentPeriodEnd();
        $now = new \DateTimeImmutable();

        $totalDays = $periodStart->diff($periodEnd)->days;
        $daysUsed = $periodStart->diff($now)->days;
        $daysRemaining = max(0, $totalDays - $daysUsed);

        $dailyRate = $subscription->getBillingAmount()->getAmount() / $totalDays;

        return round($dailyRate * $daysRemaining, 2);
    }

    private function processRenewal(Subscription $subscription): array
    {
        // Implementation details
        return ['status' => 'success'];
    }

    private function processPayment(Subscription $subscription, float $amount): PaymentResult
    {
        return PaymentResult::success();
    }
}
