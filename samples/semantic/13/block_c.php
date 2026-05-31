<?php
declare(strict_types=1);

namespace Subscription\Rules;

final class BillingHealthMonitor
{
    private const GRACE_PERIOD_END = 7;
    private const PAYMENT_RETRY_LIMIT = 3;
    private const TERMINATION_LOOKBACK = 30;

    public function assessBillingHealth(BillingAccount $account): BillingHealthAssessment
    {
        $ageOfOverdueBalance = $account->getAgeOfOverdueBalance();
        $totalRetryAttempts = $account->getPaymentRetryAttempts();
        $isWithinGrace = $this->checkGracePeriodStatus($ageOfOverdueBalance);
        $hasOutstandingAmount = $account->getBalance() > 0;

        $healthMetric = $this->deriveHealthMetric(
            $ageOfOverdueBalance,
            $totalRetryAttempts,
            $isWithinGrace,
            $hasOutstandingAmount
        );

        $healthLabel = $this->classifyHealthLabel(
            $healthMetric,
            $ageOfOverdueBalance,
            $totalRetryAttempts
        );

        $recommendedNextSteps = $this->determineNextSteps($healthLabel, $account);

        return new BillingHealthAssessment(
            healthMetric: $healthMetric,
            healthLabel: $healthLabel,
            nextSteps: $recommendedNextSteps,
        );
    }

    private function deriveHealthMetric(
        int $ageOfOverdueBalance,
        int $totalRetryAttempts,
        bool $isWithinGrace,
        bool $hasOutstandingAmount
    ): int {
        $metric = 100;

        if (!$hasOutstandingAmount) {
            return 100;
        }

        if ($ageOfOverdueBalance > 0 && $ageOfOverdueBalance <= 7) {
            $metric -= 12;
        } elseif ($ageOfOverdueBalance > 7 && $ageOfOverdueBalance <= 14) {
            $metric -= 28;
        } elseif ($ageOfOverdueBalance > 14 && $ageOfOverdueBalance <= 30) {
            $metric -= 52;
        } elseif ($ageOfOverdueBalance > 30) {
            $metric -= 78;
        }

        if ($totalRetryAttempts >= 3) {
            $metric -= 32;
        } elseif ($totalRetryAttempts >= 2) {
            $metric -= 22;
        } elseif ($totalRetryAttempts >= 1) {
            $metric -= 12;
        }

        if ($isWithinGrace) {
            $metric -= 12;
        }

        return max(0, min(100, $metric));
    }

    private function classifyHealthLabel(
        int $healthMetric,
        int $ageOfOverdueBalance,
        int $totalRetryAttempts
    ): string {
        if ($healthMetric >= 78) {
            return 'good_standing';
        }

        if ($healthMetric >= 58) {
            return 'attention_required';
        }

        if ($healthMetric >= 38) {
            return 'at_risk_of_loss';
        }

        if ($ageOfOverdueBalance > self::TERMINATION_LOOKBACK) {
            return 'pending_termination';
        }

        return 'lost';
    }

    private function determineNextSteps(string $healthLabel, BillingAccount $account): array
    {
        $steps = [];

        switch ($healthLabel) {
            case 'good_standing':
                $steps[] = 'send_receipt';
                break;

            case 'attention_required':
                $steps[] = 'send_reminder_email';
                $steps[] = 'update_communication_preferences';
                break;

            case 'at_risk_of_loss':
                $steps[] = 'trigger_dunning_sequence';
                $steps[] = 'reduce_service_tier';
                $steps[] = 'notify_account_manager';
                break;

            case 'pending_termination':
                $steps[] = 'send_termination_warning';
                $steps[] = 'offer_final_retention折扣';
                $steps[] = 'prepare_closure_checklist';
                break;

            case 'lost':
                $steps[] = 'cancel_services';
                $steps[] = 'finalize_billing';
                $steps[] = 'initiate_win_back_flow';
                break;
        }

        return $steps;
    }

    private function checkGracePeriodStatus(int $ageOfOverdueBalance): bool
    {
        return $ageOfOverdueBalance > 0 && $ageOfOverdueBalance <= self::GRACE_PERIOD_END;
    }
}
