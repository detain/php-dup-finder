<?php

declare(strict_types=1);

namespace App\Ecommerce;

class CustomerLifecycleTracker
{
    private SegmentClient $segment;
    private MixpanelClient $mixpanel;
    private LoggerInterface $logger;

    public function __construct(
        SegmentClient $segment,
        MixpanelClient $mixpanel,
        LoggerInterface $logger
    ) {
        $this->segment = $segment;
        $this->mixpanel = $mixpanel;
        $this->logger = $logger;
    }

    public function trackCustomerActivated(
        string $customerId,
        string $activationMethod,
        ?string $promoCode = null,
        array $initialPurchase = []
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'activation_method' => $activationMethod,
            'promo_code_used' => $promoCode,
            'initial_purchase_value' => $initialPurchase['value'] ?? 0,
            'initial_purchase_items' => $initialPurchase['items'] ?? 0,
            'activated_at' => date('c')
        ];

        $this->segment->track(
            'Customer Activated',
            $customerId,
            $properties
        );

        $this->mixpanel->people->set($customerId, [
            'Activated' => true,
            'Activation Method' => $activationMethod,
            'Activation Date' => date('Y-m-d H:i:s')
        ]);

        $this->incrementAggregation('customers_activated_total', [
            'method' => $activationMethod
        ]);

        $this->recordLifetimeValue($customerId, $initialPurchase['value'] ?? 0);

        $this->triggerWelcomeJourney($customerId, $activationMethod);

        $this->logger->info('Customer activation tracked', [
            'customer_id' => $customerId,
            'method' => $activationMethod
        ]);
    }

    public function trackFirstPurchase(
        string $customerId,
        string $orderId,
        float $amount,
        string $currency,
        string $productCategory,
        ?string $couponCode = null,
        string $paymentMethod = 'card'
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'product_category' => $productCategory,
            'coupon_applied' => $couponCode !== null,
            'coupon_code' => $couponCode,
            'payment_method' => $paymentMethod,
            'first_purchase_at' => date('c')
        ];

        $this->segment->track(
            'First Purchase Completed',
            $customerId,
            $properties
        );

        $this->mixpanel->people->set($customerId, [
            'First Purchase Amount' => $amount,
            'First Purchase Date' => date('Y-m-d H:i:s'),
            'Product Category' => $productCategory
        ]);

        $this->incrementAggregation('first_purchases_total', [
            'category' => $productCategory,
            'payment_method' => $paymentMethod
        ]);

        $this->recordPurchaseMetrics($customerId, $amount, $currency);

        $this->updateCustomerSegment($customerId, 'purchasers');

        $this->checkLifetimeMilestones($customerId, $amount);

        $this->logger->info('First purchase tracked', [
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'amount' => $amount
        ]);
    }

    public function trackRepeatPurchase(
        string $customerId,
        string $orderId,
        float $amount,
        string $currency,
        int $purchaseCount,
        float $averageOrderValue
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'purchase_count' => $purchaseCount,
            'average_order_value' => $averageOrderValue,
            'purchase_at' => date('c')
        ];

        $this->segment->track(
            'Repeat Purchase',
            $customerId,
            $properties
        );

        $this->mixpanel->people->increment($customerId, [
            'Purchases Count' => 1,
            'Total Value' => $amount
        ]);

        $this->incrementAggregation('repeat_purchases_total', [
            'purchase_count_bucket' => $this->bucketPurchaseCount($purchaseCount)
        ]);

        $this->recordPurchaseMetrics($customerId, $amount, $currency);

        $this->updateRepeatPurchaseRate($customerId, $purchaseCount);
    }

    public function trackSubscriptionStarted(
        string $customerId,
        string $subscriptionId,
        string $planName,
        string $billingInterval,
        float $recurringValue,
        int $trialDays = 0
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'plan_name' => $planName,
            'billing_interval' => $billingInterval,
            'recurring_value' => $recurringValue,
            'trial_days' => $trialDays,
            'started_at' => date('c')
        ];

        $this->segment->track(
            'Subscription Started',
            $customerId,
            $properties
        );

        $this->mixpanel->people->set($customerId, [
            'Subscription Status' => 'active',
            'Subscription Plan' => $planName,
            'Subscription Start Date' => date('Y-m-d H:i:s'),
            'MRR' => $recurringValue
        ]);

        $this->incrementAggregation('subscriptions_started_total', [
            'plan' => $planName,
            'billing_interval' => $billingInterval
        ]);

        $this->recordMrrChange($recurringValue, 'new');

        $this->updateSubscriptionCohort($customerId, $planName);

        $this->triggerSubscriptionNpsSurvey($subscriptionId);
    }

    public function trackSubscriptionRenewed(
        string $customerId,
        string $subscriptionId,
        float $amount,
        int $renewalCount,
        string $planName
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'amount' => $amount,
            'renewal_count' => $renewalCount,
            'plan_name' => $planName,
            'renewed_at' => date('c')
        ];

        $this->segment->track(
            'Subscription Renewed',
            $customerId,
            $properties
        );

        $this->mixpanel->people->increment($customerId, [
            'Renewal Count' => 1
        ]);

        $this->incrementAggregation('subscriptions_renewed_total', [
            'plan' => $planName
        ]);

        $this->recordMrrChange($amount, 'renewed');

        $this->checkRenewalMilestones($customerId, $renewalCount);
    }

    public function trackSubscriptionCancelled(
        string $customerId,
        string $subscriptionId,
        string $cancellationReason,
        int $subscriptionTenureDays,
        string $planName,
        float $refundAmount = 0
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'cancellation_reason' => $cancellationReason,
            'subscription_tenure_days' => $subscriptionTenureDays,
            'plan_name' => $planName,
            'refund_amount' => $refundAmount,
            'cancelled_at' => date('c')
        ];

        $this->segment->track(
            'Subscription Cancelled',
            $customerId,
            $properties
        );

        $this->mixpanel->people->set($customerId, [
            'Subscription Status' => 'cancelled',
            'Cancellation Reason' => $cancellationReason,
            'Cancellation Date' => date('Y-m-d H:i:s')
        ]);

        $this->incrementAggregation('subscriptions_cancelled_total', [
            'reason' => $cancellationReason,
            'plan' => $planName,
            'tenure_bucket' => $this->bucketTenure($subscriptionTenureDays)
        ]);

        $this->recordMrrChange(0, 'cancelled');

        $this->triggerCancellationSurvey($customerId, $cancellationReason);
    }

    public function trackSubscriptionUpgraded(
        string $customerId,
        string $subscriptionId,
        string $fromPlan,
        string $toPlan,
        float $valueIncrease
    ): void {
        $properties = [
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'value_increase' => $valueIncrease,
            'upgraded_at' => date('c')
        ];

        $this->segment->track(
            'Subscription Upgraded',
            $customerId,
            $properties
        );

        $this->mixpanel->people->set($customerId, [
            'Subscription Plan' => $toPlan,
            'Last Upgrade Date' => date('Y-m-d H:i:s')
        ]);

        $this->incrementAggregation('subscriptions_upgraded_total', [
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan
        ]);

        $this->recordMrrChange($valueIncrease, 'upgrade');
    }

    private function incrementAggregation(string $metric, array $labels): void
    {
        $this->segment->increment($metric, $labels);
    }

    private function recordLifetimeValue(string $customerId, float $value): void
    {
        $this->mixpanel->people->set($customerId, [
            'Lifetime Value' => $value,
            'First Purchase Value' => $value
        ]);
    }

    private function triggerWelcomeJourney(string $customerId, string $method): void
    {
        $this->logger->info('Welcome journey triggered', [
            'customer_id' => $customerId,
            'method' => $method
        ]);
    }

    private function recordPurchaseMetrics(string $customerId, float $amount, string $currency): void
    {
        $this->logger->info('Purchase metrics recorded', [
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => $currency
        ]);
    }

    private function updateCustomerSegment(string $customerId, string $segment): void
    {
        $this->segment->identify($customerId, [
            'segment' => $segment
        ]);
    }

    private function checkLifetimeMilestones(string $customerId, float $amount): void
    {
        $milestones = [100, 500, 1000, 5000, 10000];

        foreach ($milestones as $milestone) {
            if ($amount >= $milestone) {
                $this->segment->track(
                    'Lifetime Milestone Reached',
                    $customerId,
                    ['milestone' => $milestone]
                );
            }
        }
    }

    private function bucketPurchaseCount(int $count): string
    {
        if ($count <= 2) {
            return '2_or_less';
        }
        if ($count <= 5) {
            return '3_to_5';
        }
        if ($count <= 10) {
            return '6_to_10';
        }

        return 'more_than_10';
    }

    private function bucketTenure(int $days): string
    {
        if ($days <= 30) {
            return 'first_month';
        }
        if ($days <= 90) {
            return 'first_quarter';
        }
        if ($days <= 365) {
            return 'first_year';
        }

        return 'more_than_year';
    }

    private function updateRepeatPurchaseRate(string $customerId, int $purchaseCount): void
    {
        $this->mixpanel->people->set($customerId, [
            'Repeat Purchase Rate' => $purchaseCount > 1 ? 'repeat' : 'one_time'
        ]);
    }

    private function updateSubscriptionCohort(string $customerId, string $planName): void
    {
        $cohort = date('Y-m');

        $this->segment->identify($customerId, [
            'subscription_cohort' => $cohort,
            'initial_plan' => $planName
        ]);
    }

    private function recordMrrChange(float $amount, string $changeType): void
    {
        $this->segment->increment('mrr', [$changeType => $amount]);
    }

    private function triggerSubscriptionNpsSurvey(string $subscriptionId): void
    {
        $this->logger->info('Subscription NPS survey queued', [
            'subscription_id' => $subscriptionId
        ]);
    }

    private function checkRenewalMilestones(string $customerId, int $renewalCount): void
    {
        $milestones = [3, 6, 12, 24, 36];

        if (in_array($renewalCount, $milestones, true)) {
            $this->segment->track(
                'Renewal Milestone',
                $customerId,
                ['renewal_count' => $renewalCount]
            );
        }
    }

    private function triggerCancellationSurvey(string $customerId, string $reason): void
    {
        $this->logger->info('Cancellation survey sent', [
            'customer_id' => $customerId,
            'reason' => $reason
        ]);
    }
}
