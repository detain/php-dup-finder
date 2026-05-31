<?php
declare(strict_types=1);

namespace Subscription\Rules;

final class SubscriptionStatusEvaluator
{
    private const GRACE_PERIOD_DAYS = 7;
    private const DUNNING_MAX_ATTEMPTS = 3;
    private const CANCELLATION_THREHOLD_DAYS = 30;

    public function evaluateSubscriptionHealth(Subscription $subscription): HealthStatus
    {
        $daysPastDue = $subscription->getDaysPastDue();
        $paymentAttempts = $subscription->getFailedPaymentAttempts();
        $isInGracePeriod = $this->isInGracePeriod($subscription);
        $hasOverdueBalance = $subscription->getOutstandingBalance() > 0;

        $healthScore = $this->calculateHealthScore(
            $daysPastDue,
            $paymentAttempts,
            $isInGracePeriod,
            $hasOverdueBalance
        );

        $status = $this->determineStatus(
            $healthScore,
            $daysPastDue,
            $paymentAttempts
        );

        $actions = $this->determineRequiredActions($status, $subscription);

        return new HealthStatus(
            score: $healthScore,
            status: $status,
            requiresAttention: $this->requiresAttention($status),
            suggestedActions: $actions,
        );
    }

    private function calculateHealthScore(
        int $daysPastDue,
        int $paymentAttempts,
        bool $isInGracePeriod,
        bool $hasOverdueBalance
    ): int {
        $score = 100;

        if (!$hasOverdueBalance) {
            return 100;
        }

        if ($daysPastDue > 0 && $daysPastDue <= 7) {
            $score -= 10;
        } elseif ($daysPastDue > 7 && $daysPastDue <= 14) {
            $score -= 25;
        } elseif ($daysPastDue > 14 && $daysPastDue <= 30) {
            $score -= 50;
        } elseif ($daysPastDue > 30) {
            $score -= 75;
        }

        if ($paymentAttempts >= 3) {
            $score -= 30;
        } elseif ($paymentAttempts >= 2) {
            $score -= 20;
        } elseif ($paymentAttempts >= 1) {
            $score -= 10;
        }

        if ($isInGracePeriod) {
            $score -= 15;
        }

        return max(0, min(100, $score));
    }

    private function determineStatus(int $healthScore, int $daysPastDue, int $paymentAttempts): string
    {
        if ($healthScore >= 80) {
            return 'active';
        }

        if ($healthScore >= 60) {
            return 'at_risk';
        }

        if ($healthScore >= 40) {
            return 'delinquent';
        }

        if ($daysPastDue > self::CANCELLATION_THREHOLD_DAYS) {
            return 'cancellation_pending';
        }

        return 'churned';
    }

    private function determineRequiredActions(string $status, Subscription $subscription): array
    {
        $actions = [];

        switch ($status) {
            case 'active':
                $actions[] = 'continue_monitoring';
                break;

            case 'at_risk':
                $actions[] = 'send_warning_notification';
                $actions[] = 'offer_payment_plan';
                break;

            case 'delinquent':
                $actions[] = 'initiate_dunning';
                $actions[] = 'restrict_access';
                $actions[] = 'escalate_to_collections';
                break;

            case 'cancellation_pending':
                $actions[] = 'final_collection_notice';
                $actions[] = 'offer_retention_deal';
                break;

            case 'churned':
                $actions[] = 'cancel_subscription';
                $actions[] = 'process_final_invoice';
                $actions[] = 'archive_account';
                break;
        }

        return $actions;
    }

    private function isInGracePeriod(Subscription $subscription): bool
    {
        $daysPastDue = $subscription->getDaysPastDue();

        return $daysPastDue > 0 && $daysPastDue <= self::GRACE_PERIOD_DAYS;
    }

    private function requiresAttention(string $status): bool
    {
        return in_array($status, ['at_risk', 'delinquent', 'cancellation_pending', 'churned']);
    }
}
