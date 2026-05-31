<?php

declare(strict_types=1);

namespace App\Analytics;

class BusinessMetricsTracker
{
    private AnalyticsClient $analytics;
    private LoggerInterface $logger;
    private array $userProperties = [];

    public function __construct(AnalyticsClient $analytics, LoggerInterface $logger)
    {
        $this->analytics = $analytics;
        $this->logger = $logger;
    }

    public function trackNewUserRegistration(
        string $userId,
        string $email,
        string $registrationMethod,
        ?string $referralCode = null,
        array $metadata = []
    ): void {
        $properties = array_merge([
            'user_id' => $userId,
            'email' => $email,
            'registration_method' => $registrationMethod,
            'referral_code' => $referralCode,
            'registered_at' => date('c'),
            'source' => $this->determineRegistrationSource($registrationMethod)
        ], $metadata);

        $this->analytics->track(
            'User Registered',
            $userId,
            $properties
        );

        $this->incrementCounter('new_user_registrations_total', [
            'method' => $registrationMethod,
            'has_referral' => $referralCode ? 'true' : 'false'
        ]);

        $this->recordUserProperties($userId, $properties);

        $this->logger->info('New user registration tracked', [
            'user_id' => $userId,
            'method' => $registrationMethod
        ]);
    }

    public function trackFirstOrderPlaced(
        string $userId,
        string $orderId,
        float $orderValue,
        string $currency,
        string $paymentMethod,
        string $couponCode = null
    ): void {
        $properties = [
            'user_id' => $userId,
            'order_id' => $orderId,
            'order_value' => $orderValue,
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'coupon_used' => $couponCode !== null,
            'coupon_code' => $couponCode,
            'order_placed_at' => date('c')
        ];

        $this->analytics->track(
            'First Order Placed',
            $userId,
            $properties
        );

        $this->incrementCounter('first_orders_total', [
            'payment_method' => $paymentMethod,
            'has_coupon' => $couponCode !== null ? 'true' : 'false'
        ]);

        $this->recordRevenueMetrics('first_order', $orderValue, $currency);

        $this->updateUserLifecycleValue($userId, 'first_purchase', $orderValue);

        $this->logger->info('First order tracked', [
            'user_id' => $userId,
            'order_id' => $orderId,
            'value' => $orderValue
        ]);
    }

    public function trackSubscriptionConverted(
        string $userId,
        string $subscriptionId,
        string $planId,
        string $billingCycle,
        float $subscriptionValue,
        string $conversionSource
    ): void {
        $properties = [
            'user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle,
            'subscription_value' => $subscriptionValue,
            'currency' => 'USD',
            'conversion_source' => $conversionSource,
            'converted_at' => date('c')
        ];

        $this->analytics->track(
            'Subscription Converted',
            $userId,
            $properties
        );

        $this->incrementCounter('subscription_conversions_total', [
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle,
            'source' => $conversionSource
        ]);

        $this->recordRevenueMetrics('subscription', $subscriptionValue, 'USD');

        $this->updateCustomerHealthScore($userId, 'subscription_converted');

        $this->sendConversionNotification($userId, $planId);

        $this->logger->info('Subscription conversion tracked', [
            'user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId
        ]);
    }

    public function trackUserEngagement(
        string $userId,
        string $eventType,
        string $page,
        int $sessionDuration,
        int $actionsPerformed
    ): void {
        $properties = [
            'user_id' => $userId,
            'event_type' => $eventType,
            'page' => $page,
            'session_duration' => $sessionDuration,
            'actions_performed' => $actionsPerformed,
            'engaged_at' => date('c')
        ];

        $this->analytics->track(
            'User Engaged',
            $userId,
            $properties
        );

        $this->recordGauge('active_users', 1, ['event_type' => $eventType]);

        $this->updateUserEngagementScore($userId, $sessionDuration, $actionsPerformed);
    }

    public function trackUpgrade意向(
        string $userId,
        string $currentPlan,
        string $targetPlan,
        string $upgradeReason
    ): void {
        $properties = [
            'user_id' => $userId,
            'current_plan' => $currentPlan,
            'target_plan' => $targetPlan,
            'upgrade_reason' => $upgradeReason,
            'tracked_at' => date('c')
        ];

        $this->analytics->track(
            'Upgrade Intention Detected',
            $userId,
            $properties
        );

        $this->incrementCounter('upgrade_intentions_total', [
            'from_plan' => $currentPlan,
            'to_plan' => $targetPlan
        ]);

        $this->recordCustomerHealthScoreChange($userId, 'upgrade_intent', 10);
    }

    public function trackChurnRisk(string $userId, string $riskLevel, array $riskFactors): void
    {
        $properties = [
            'user_id' => $userId,
            'risk_level' => $riskLevel,
            'risk_factors' => $riskFactors,
            'assessed_at' => date('c')
        ];

        $this->analytics->track(
            'Churn Risk Detected',
            $userId,
            $properties
        );

        $this->incrementCounter('churn_risk_detected_total', [
            'risk_level' => $riskLevel
        ]);

        if ($riskLevel === 'high') {
            $this->triggerChurnPreventionCampaign($userId, $riskFactors);
        }
    }

    private function determineRegistrationSource(string $method): string
    {
        return match($method) {
            'email' => 'direct',
            'google' => 'social',
            'facebook' => 'social',
            'twitter' => 'social',
            'invite' => 'referral',
            default => 'organic'
        };
    }

    private function incrementCounter(string $metric, array $labels): void
    {
        $this->analytics->incrementCounter($metric, $labels);
    }

    private function recordGauge(string $metric, int $value, array $labels): void
    {
        $this->analytics->recordGauge($metric, $value, $labels);
    }

    private function recordUserProperties(string $userId, array $properties): void
    {
        $this->analytics->identify($userId, $properties);
    }

    private function recordRevenueMetrics(string $type, float $value, string $currency): void
    {
        $this->incrementCounter('revenue_total', [
            'type' => $type,
            'currency' => $currency
        ]);

        $this->recordGauge('revenue_amount', (int)$value, [
            'type' => $type,
            'currency' => $currency
        ]);
    }

    private function updateUserLifecycleValue(string $userId, string $lifecycleStage, float $orderValue = null): void
    {
        $this->analytics->identify($userId, [
            'lifecycle_stage' => $lifecycleStage,
            'lifecycle_value' => $orderValue,
            'lifecycle_updated_at' => date('c')
        ]);
    }

    private function updateUserEngagementScore(string $userId, int $sessionDuration, int $actions): void
    {
        $engagementScore = $this->calculateEngagementScore($sessionDuration, $actions);

        $this->analytics->identify($userId, [
            'engagement_score' => $engagementScore,
            'last_engaged_at' => date('c')
        ]);
    }

    private function calculateEngagementScore(int $sessionDuration, int $actions): int
    {
        return min(100, ($sessionDuration / 60) * 10 + $actions * 5);
    }

    private function updateCustomerHealthScore(string $userId, string $event): void
    {
        $this->analytics->identify($userId, [
            'health_score' => 100,
            'health_last_updated' => date('c')
        ]);
    }

    private function recordCustomerHealthScoreChange(string $userId, string $event, int $scoreChange): void
    {
        $this->logger->info('Customer health score updated', [
            'user_id' => $userId,
            'event' => $event,
            'score_change' => $scoreChange
        ]);
    }

    private function sendConversionNotification(string $userId, string $planId): void
    {
        $this->logger->info('Conversion notification sent', [
            'user_id' => $userId,
            'plan_id' => $planId
        ]);
    }

    private function triggerChurnPreventionCampaign(string $userId, array $riskFactors): void
    {
        $this->logger->info('Churn prevention triggered', [
            'user_id' => $userId,
            'risk_factors' => $riskFactors
        ]);
    }
}
