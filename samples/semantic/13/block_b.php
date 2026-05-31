<?php
declare(strict_types=1);

namespace Subscription\Rules;

final class MemberRetentionClassifier
{
    private const GRACE_WINDOW_DAYS = 7;
    private const MAX_RETRY_COUNT = 3;
    private const TERMINATION_THRESHOLD_DAYS = 30;

    public function classifyMemberStatus(MemberAccount $account): RetentionClassification
    {
        $overdueDays = $account->getOverdueDays();
        $retryCount = $account->getPaymentRetryCount();
        $isInGraceWindow = $this->isInGraceWindow($overdueDays);
        $hasBalanceDue = $account->getAmountDue() > 0;

        $retentionScore = $this->computeRetentionScore(
            $overdueDays,
            $retryCount,
            $isInGraceWindow,
            $hasBalanceDue
        );

        $classification = $this->assignClassification(
            $retentionScore,
            $overdueDays,
            $retryCount
        );

        $interventionPlan = $this->buildInterventionPlan($classification, $account);

        return new RetentionClassification(
            score: $retentionScore,
            classification: $classification,
            interventionPlan: $interventionPlan,
        );
    }

    private function computeRetentionScore(
        int $overdueDays,
        int $retryCount,
        bool $isInGraceWindow,
        bool $hasBalanceDue
    ): int {
        $score = 100;

        if (!$hasBalanceDue) {
            return 100;
        }

        if ($overdueDays > 0 && $overdueDays <= 7) {
            $score -= 15;
        } elseif ($overdueDays > 7 && $overdueDays <= 14) {
            $score -= 30;
        } elseif ($overdueDays > 14 && $overdueDays <= 30) {
            $score -= 55;
        } elseif ($overdueDays > 30) {
            $score -= 80;
        }

        if ($retryCount >= 3) {
            $score -= 35;
        } elseif ($retryCount >= 2) {
            $score -= 25;
        } elseif ($retryCount >= 1) {
            $score -= 15;
        }

        if ($isInGraceWindow) {
            $score -= 10;
        }

        return max(0, min(100, $score));
    }

    private function assignClassification(
        int $retentionScore,
        int $overdueDays,
        int $retryCount
    ): string {
        if ($retentionScore >= 75) {
            return 'healthy';
        }

        if ($retentionScore >= 55) {
            return 'engagement_needed';
        }

        if ($retentionScore >= 35) {
            return 'high_risk';
        }

        if ($overdueDays > self::TERMINATION_THRESHOLD_DAYS) {
            return 'termination_imminent';
        }

        return 'churned';
    }

    private function buildInterventionPlan(string $classification, MemberAccount $account): array
    {
        $plan = [];

        switch ($classification) {
            case 'healthy':
                $plan[] = 'maintain_current_engagement';
                break;

            case 'engagement_needed':
                $plan[] = 'send_reengagement_email';
                $plan[] = 'offer_loyalty_bonus';
                break;

            case 'high_risk':
                $plan[] = 'activate_retention_team';
                $plan[] = 'schedule_outbound_call';
                $plan[] = 'prepare_special_offer';
                break;

            case 'termination_imminent':
                $plan[] = 'escalate_to_management';
                $plan[] = 'send_final_notice';
                $plan[] = 'offer_last_chance_deal';
                break;

            case 'churned':
                $plan[] = 'process_cancellation';
                $plan[] = 'export_to_data_warehouse';
                $plan[] = 'begin_win_back_campaign';
                break;
        }

        return $plan;
    }

    private function isInGraceWindow(int $overdueDays): bool
    {
        return $overdueDays > 0 && $overdueDays <= self::GRACE_WINDOW_DAYS;
    }
}
