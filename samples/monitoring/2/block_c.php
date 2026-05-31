<?php

declare(strict_types=1);

namespace App\Subscriptions;

class SubscriptionMetricsAggregator
{
    private TimeSeriesDB $tsdb;
    private LoggerInterface $logger;
    private array $metricBuffer = [];

    public function __construct(TimeSeriesDB $tsdb, LoggerInterface $logger)
    {
        $this->tsdb = $tsdb;
        $this->logger = $logger;
    }

    public function recordTrialStarted(
        string $customerId,
        string $planId,
        int $trialDays,
        ?string $promoCode = null
    ): void {
        $metric = new MetricPoint(
            name: 'trials_started_total',
            value: 1,
            labels: [
                'plan_id' => $planId,
                'trial_days' => (string)$trialDays,
                'has_promo' => $promoCode !== null ? 'true' : 'false'
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordCustomerSubscriptionState($customerId, 'trial', [
            'plan_id' => $planId,
            'trial_end' => date('Y-m-d H:i:s', strtotime("+{$trialDays} days"))
        ]);

        $this->incrementPlanFunnelMetric('trial_started', $planId);
    }

    public function recordTrialConverted(
        string $customerId,
        string $planId,
        float $initialAmount,
        string $paymentMethod
    ): void {
        $metric = new MetricPoint(
            name: 'trials_converted_total',
            value: 1,
            labels: [
                'plan_id' => $planId,
                'payment_method' => $paymentMethod
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordTrialConversionRate($customerId, $planId, true);

        $this->recordRevenueContribution($planId, $initialAmount, 'conversion');

        $this->updateMrrMetric($planId, $initialAmount, 'new');

        $this->sendConversionCelebration($customerId, $planId);
    }

    public function recordTrialExpired(
        string $customerId,
        string $planId,
        string $expireReason
    ): void {
        $metric = new MetricPoint(
            name: 'trials_expired_total',
            value: 1,
            labels: [
                'plan_id' => $planId,
                'expire_reason' => $expireReason
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordTrialConversionRate($customerId, $planId, false);

        $this->updatePlanChurnMetric($planId, 'trial_expiration');

        $this->triggerTrialExpiringReengagement($customerId, $planId);
    }

    public function recordPlanUpgraded(
        string $customerId,
        string $fromPlanId,
        string $toPlanId,
        float $valueDelta,
        int $upgradeDelayDays
    ): void {
        $metric = new MetricPoint(
            name: 'plan_upgrades_total',
            value: 1,
            labels: [
                'from_plan' => $fromPlanId,
                'to_plan' => $toPlanId
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordPlanMigration($fromPlanId, $toPlanId);

        $this->updateMrrMetric($toPlanId, $valueDelta, 'upgrade');

        $this->recordUpgradeVelocity($fromPlanId, $toPlanId, $upgradeDelayDays);

        $this->incrementPlanFunnelMetric('upgrade', $toPlanId);
    }

    public function recordPlanDowngraded(
        string $customerId,
        string $fromPlanId,
        string $toPlanId,
        float $valueDelta
    ): void {
        $metric = new MetricPoint(
            name: 'plan_downgrades_total',
            value: 1,
            labels: [
                'from_plan' => $fromPlanId,
                'to_plan' => $toPlanId
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordPlanMigration($fromPlanId, $toPlanId);

        $this->updateMrrMetric($fromPlanId, abs($valueDelta), 'downgrade');

        $this->incrementPlanFunnelMetric('downgrade', $fromPlanId);
    }

    public function recordSubscriptionPaused(
        string $customerId,
        string $subscriptionId,
        string $reason,
        int $pauseDurationDays
    ): void {
        $metric = new MetricPoint(
            name: 'subscriptions_paused_total',
            value: 1,
            labels: [
                'reason' => $reason
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordCustomerSubscriptionState($customerId, 'paused', [
            'subscription_id' => $subscriptionId,
            'pause_reason' => $reason,
            'pause_duration_days' => $pauseDurationDays
        ]);

        $this->updateMrrMetric($subscriptionId, 0, 'paused');
    }

    public function recordSubscriptionResumed(
        string $customerId,
        string $subscriptionId,
        int $pausedDurationDays
    ): void {
        $metric = new MetricPoint(
            name: 'subscriptions_resumed_total',
            value: 1,
            labels: [],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordCustomerSubscriptionState($customerId, 'active', [
            'subscription_id' => $subscriptionId,
            'resumed_at' => date('c')
        ]);

        $this->recordPauseResumptionCorrelation($pausedDurationDays);
    }

    public function recordSubscriptionCancelled(
        string $customerId,
        string $subscriptionId,
        string $planId,
        string $cancellationReason,
        float $refundedAmount,
        int $subscriptionTenureDays
    ): void {
        $metric = new MetricPoint(
            name: 'subscriptions_cancelled_total',
            value: 1,
            labels: [
                'plan_id' => $planId,
                'reason' => $cancellationReason,
                'tenure_bucket' => $this->bucketTenure($subscriptionTenureDays)
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordCustomerSubscriptionState($customerId, 'cancelled', [
            'subscription_id' => $subscriptionId,
            'cancellation_reason' => $cancellationReason,
            'tenure_days' => $subscriptionTenureDays,
            'refunded_amount' => $refundedAmount
        ]);

        $this->updateMrrMetric($planId, 0, 'cancelled');

        $this->updatePlanChurnMetric($planId, 'cancellation');

        $this->recordCancellationRecoveryAttempt($customerId, $planId, $cancellationReason);
    }

    public function recordSubscriptionReactivated(
        string $customerId,
        string $subscriptionId,
        string $planId,
        string $reactivationSource
    ): void {
        $metric = new MetricPoint(
            name: 'subscriptions_reactivated_total',
            value: 1,
            labels: [
                'plan_id' => $planId,
                'source' => $reactivationSource
            ],
            timestamp: time()
        );

        $this->bufferMetric($metric);

        $this->recordCustomerSubscriptionState($customerId, 'active', [
            'subscription_id' => $subscriptionId,
            'reactivated_at' => date('c'),
            'reactivation_source' => $reactivationSource
        ]);

        $this->updateMrrMetric($planId, 0, 'reactivated');

        $this->recordChurnReversal($planId);
    }

    private function bufferMetric(MetricPoint $metric): void
    {
        $this->metricBuffer[] = $metric;

        if (count($this->metricBuffer) >= 100) {
            $this->flushBuffer();
        }
    }

    private function flushBuffer(): void
    {
        if (empty($this->metricBuffer)) {
            return;
        }

        $this->tsdb->writeBatch($this->metricBuffer);

        $this->metricBuffer = [];
    }

    private function recordCustomerSubscriptionState(string $customerId, string $state, array $properties): void
    {
        $this->tsdb->writePoint(
            'customer_subscription_state',
            1,
            array_merge(['customer_id' => $customerId, 'state' => $state], $properties),
            time()
        );
    }

    private function incrementPlanFunnelMetric(string $event, string $planId): void
    {
        $this->tsdb->writePoint(
            'plan_funnel',
            1,
            ['plan_id' => $planId, 'event' => $event],
            time()
        );
    }

    private function recordTrialConversionRate(string $customerId, string $planId, bool $converted): void
    {
        $label = $converted ? 'converted' : 'expired';

        $this->tsdb->writePoint(
            'trial_conversion_rate',
            $converted ? 1 : 0,
            ['plan_id' => $planId, 'result' => $label],
            time()
        );
    }

    private function recordRevenueContribution(string $planId, float $amount, string $type): void
    {
        $this->tsdb->writePoint(
            'revenue_contribution',
            $amount,
            ['plan_id' => $planId, 'type' => $type],
            time()
        );
    }

    private function updateMrrMetric(string $planId, float $amount, string $changeType): void
    {
        $this->tsdb->writePoint(
            'monthly_recurring_revenue',
            $amount,
            ['plan_id' => $planId, 'change_type' => $changeType],
            time()
        );
    }

    private function updatePlanChurnMetric(string $planId, string $churnType): void
    {
        $this->tsdb->writePoint(
            'plan_churn',
            1,
            ['plan_id' => $planId, 'type' => $churnType],
            time()
        );
    }

    private function sendConversionCelebration(string $customerId, string $planId): void
    {
        $this->logger->info('Conversion celebration triggered', [
            'customer_id' => $customerId,
            'plan_id' => $planId
        ]);
    }

    private function triggerTrialExpiringReengagement(string $customerId, string $planId): void
    {
        $this->logger->info('Trial expiring reengagement triggered', [
            'customer_id' => $customerId,
            'plan_id' => $planId
        ]);
    }

    private function recordPlanMigration(string $fromPlan, string $toPlan): void
    {
        $this->tsdb->writePoint(
            'plan_migrations',
            1,
            ['from_plan' => $fromPlan, 'to_plan' => $toPlan],
            time()
        );
    }

    private function recordUpgradeVelocity(string $fromPlan, string $toPlan, int $delayDays): void
    {
        $this->tsdb->writePoint(
            'upgrade_velocity',
            $delayDays,
            ['from_plan' => $fromPlan, 'to_plan' => $toPlan],
            time()
        );
    }

    private function recordPauseResumptionCorrelation(int $pausedDurationDays): void
    {
        $this->tsdb->writePoint(
            'pause_resumption_correlation',
            $pausedDurationDays,
            [],
            time()
        );
    }

    private function recordCancellationRecoveryAttempt(
        string $customerId,
        string $planId,
        string $reason
    ): void {
        $this->logger->info('Cancellation recovery queued', [
            'customer_id' => $customerId,
            'plan_id' => $planId,
            'reason' => $reason
        ]);
    }

    private function recordChurnReversal(string $planId): void
    {
        $this->tsdb->writePoint(
            'churn_reversals',
            1,
            ['plan_id' => $planId],
            time()
        );
    }

    private function bucketTenure(int $days): string
    {
        if ($days <= 30) {
            return '0-30_days';
        }
        if ($days <= 90) {
            return '31-90_days';
        }
        if ($days <= 180) {
            return '91-180_days';
        }
        if ($days <= 365) {
            return '181-365_days';
        }

        return '365+_days';
    }
}
