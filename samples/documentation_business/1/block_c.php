<?php
declare(strict_types=1);

namespace Billing\Subscriptions;

/**
 * Subscription ticket comment - Internal billing support notes
 *
 * This ticket tracks a customer dispute regarding subscription billing.
 *
 * TICKET: #BILL-2024-78432
 * CREATED: 2024-01-15 by support_agent@company.com
 * STATUS: Under review
 *
 * CUSTOMER ISSUE:
 * Customer claims they were charged twice for their annual subscription
 * on January 1st. They were charged $240.00 twice = $480.00 total.
 *
 * INVESTIGATION NOTES:
 * - Customer ID: 45832
 * - Subscription ID: sub_abc123
 * - Plan: Premium Monthly at $40/month
 * - The ticket notes the customer actually has PREPAID for the year
 *   at $408.00 (annual plan) but system shows monthly billing
 *
 * HANDLING INSTRUCTIONS:
 * 1. Verify the actual plan type in the billing system
 * 2. Check payment history for duplicate charges
 * 3. If duplicate found, process full refund immediately
 * 4. Ensure subscription record shows correct annual plan
 * 5. Add 3 months free as compensation for inconvenience
 *
 * PRORATED REFUND NOTES (per billing rules):
 * If customer was on monthly billing but should have been annual:
 * - Calculate unused portion of current billing period
 * - Apply credit to annual plan price
 * - Issue refund for difference if applicable
 *
 * REFERRAL CREDIT RULES:
 * - Customer was referred by user ID 12345
 * - If refund processed, ensure referral credit is also reversed
 * - Referral credits expire 90 days after issue
 *
 * ESCALATION:
 * If customer is not satisfied after proposed resolution,
 * escalate to billing manager.sarah@company.com
 *
 * RESOLUTION DEADLINE: 2024-01-18
 */
final class BillingDisputeHandler
{
    private const TIER_HIERARCHY = [
        'basic' => 1,
        'standard' => 2,
        'premium' => 3,
        'enterprise' => 4
    ];

    /**
     * Process a billing dispute for duplicate charges.
     *
     * Business rules for duplicate charge disputes:
     *
     * 1. VALIDATION: Verify both charges appeared on same day
     *    - If charges are on different days, one may be renewal
     *    - Check if subscription had been prepaid as annual
     *
     * 2. REFUND: Full refund issued for duplicate within 24 hours
     *    - Refund processing takes 5-7 business days
     *    - Customer receives email confirmation when processed
     *
     * 3. COMPENSATION: Additional credit may be issued
     *    - 1 month free for minor processing errors
     *    - 3 months free for significant inconvenience
     *    - Store compensation as credit on account
     *
     * 4. RECORD KEEPING: All disputes logged with:
     *    - Original charge amounts and dates
     *    - Refund amount and date
     *    - Compensation issued
     *    - Support agent handling case
     *
     * @param int $customerId The customer filing the dispute
     * @param array $chargeIds The duplicate charge IDs to investigate
     * @return DisputeResult The outcome of the dispute processing
     */
    public function handleDuplicateChargeDispute(int $customerId, array $chargeIds): DisputeResult
    {
        $customer = $this->entityManager->find(Customer::class, $customerId);

        // Fetch both charges
        $charges = $this->paymentGateway->getCharges($chargeIds);

        // Verify both charges are from our system
        foreach ($charges as $charge) {
            if ($charge['source'] !== 'internal') {
                throw new \InvalidArgumentException('Charge not from internal system');
            }
        }

        // Check if charges are on same day
        $chargeDates = array_unique(array_map(
            fn($c) => $c['created_at']->format('Y-m-d'),
            $charges
        ));

        if (count($chargeDates) > 1) {
            // Charges on different days - one may be legitimate renewal
            $this->logger->info('Charges on different days, investigating further', [
                'customer_id' => $customerId,
                'charge_dates' => array_values($chargeDates)
            ]);
        }

        // Calculate total duplicate amount
        $duplicateAmount = array_sum(array_column($charges, 'amount'));

        // Process full refund
        $refundResult = $this->paymentGateway->refund($duplicateAmount);

        if (!$refundResult->isSuccessful()) {
            $this->logger->error('Refund processing failed', [
                'customer_id' => $customerId,
                'amount' => $duplicateAmount
            ]);
            return DisputeResult::failure('Refund processing failed');
        }

        // Issue compensation credit
        $compensationMonths = 3;
        $monthlyAmount = $customer->getSubscription()->getBillingAmount() / 12;
        $compensationCredit = $monthlyAmount * $compensationMonths;

        $this->issueCompensationCredit($customer, $compensationCredit);

        // Log the dispute resolution
        $this->logDisputeResolution($customerId, $chargeIds, $duplicateAmount, $compensationCredit);

        return DisputeResult::success(
            $duplicateAmount,
            $compensationCredit,
            'Full refund processed. Compensation credit added to account.'
        );
    }

    /**
     * Calculate the unused portion of a prepaid subscription period.
     *
     * For annual subscriptions, unused time is calculated as:
     * (days remaining in period / total days in period) * annual price
     *
     * This unused credit can then be applied toward:
     * - Tier upgrade charges
     * - Future subscription renewals
     * - Refund calculations when switching plans
     *
     * @param Subscription $subscription The subscription to calculate credit for
     * @return float The unused credit amount in dollars
     */
    public function calculateUnusedCredit(Subscription $subscription): float
    {
        $now = new \DateTimeImmutable();
        $periodStart = $subscription->getCurrentPeriodStart();
        $periodEnd = $subscription->getCurrentPeriodEnd();

        $totalDays = $periodStart->diff($periodEnd)->days;
        $daysUsed = $periodStart->diff($now)->days;
        $daysRemaining = max(0, $totalDays - $daysUsed);

        if ($daysRemaining <= 0) {
            return 0.0;
        }

        // Get the effective rate per day
        $billingAmount = $subscription->getBillingAmount()->getAmount();

        // If annual plan, calculate based on annual price
        if ($subscription->getBillingInterval() === 'year') {
            $billingAmount = $billingAmount * 12; // Convert to yearly
        }

        $dailyRate = $billingAmount / $totalDays;
        $unusedCredit = $dailyRate * $daysRemaining;

        return round($unusedCredit, 2);
    }

    private function issueCompensationCredit(Customer $customer, float $amount): void
    {
        $credit = new AccountCredit();
        $credit->setCustomer($customer);
        $credit->setAmount($amount);
        $credit->setType('compensation');
        $credit->setExpiresAt(new \DateTimeImmutable('+90 days'));
        $credit->setReason('Duplicate charge dispute resolution');
        $credit->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($credit);
        $this->entityManager->flush();
    }

    private function logDisputeResolution(
        int $customerId,
        array $chargeIds,
        float $refundAmount,
        float $compensationCredit
    ): void {
        $this->logger->info('Billing dispute resolved', [
            'customer_id' => $customerId,
            'charge_ids' => $chargeIds,
            'refund_amount' => $refundAmount,
            'compensation_credit' => $compensationCredit,
            'resolved_at' => date('c')
        ]);
    }
}
