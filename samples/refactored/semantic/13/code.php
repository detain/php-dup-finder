<?php
declare(strict_types=1);

namespace Subscription\Shared;

interface HealthClassificationRule
{
    public function classify(int $healthScore, array $context): string;
    public function getScore(): int;
}

class GoodStandingRule implements HealthClassificationRule
{
    public function classify(int $healthScore, array $context): string
    {
        if ($healthScore >= 78) {
            return 'good_standing';
        }
        return 'not_applicable';
    }

    public function getScore(): int
    {
        return 78;
    }
}

class AttentionRequiredRule implements HealthClassificationRule
{
    public function classify(int $healthScore, array $context): string
    {
        if ($healthScore >= 58 && $healthScore < 78) {
            return 'attention_required';
        }
        return 'not_applicable';
    }

    public function getScore(): int
    {
        return 58;
    }
}

class HighRiskRule implements HealthClassificationRule
{
    public function classify(int $healthScore, array $context): string
    {
        if ($healthScore >= 38 && $healthScore < 58) {
            return 'at_risk_of_loss';
        }
        return 'not_applicable';
    }

    public function getScore(): int
    {
        return 38;
    }
}

class UnifiedHealthEvaluator
{
    private const GRACE_PERIOD_DAYS = 7;
    private const TERMINATION_THRESHOLD_DAYS = 30;

    private const SCORE_DEDUCTIONS = [
        'overdue_days' => [
            7 => 12,
            14 => 28,
            30 => 52,
            31 => 78,
        ],
        'retry_count' => [
            1 => 12,
            2 => 22,
            3 => 32,
        ],
    ];

    public function evaluate(mixed $account): HealthAssessment
    {
        $score = $this->calculateScore($account);
        $label = $this->classify($score, $account);

        return new HealthAssessment(
            score: $score,
            label: $label,
            nextSteps: $this->determineNextSteps($label),
        );
    }

    private function calculateScore(mixed $account): int
    {
        $score = 100;

        if ($account->getBalance() <= 0) {
            return 100;
        }

        $overdueDays = $account->getOverdueDays();
        foreach (self::SCORE_DEDUCTIONS['overdue_days'] as $threshold => $deduction) {
            if ($overdueDays >= $threshold) {
                $score -= $deduction;
                break;
            }
        }

        $retryCount = $account->getPaymentRetryCount();
        foreach (self::SCORE_DEDUCTIONS['retry_count'] as $threshold => $deduction) {
            if ($retryCount >= $threshold) {
                $score -= $deduction;
            }
        }

        return max(0, min(100, $score));
    }

    private function classify(int $score, mixed $account): string
    {
        if ($score >= 78) {
            return 'good_standing';
        }
        if ($score >= 58) {
            return 'attention_required';
        }
        if ($score >= 38) {
            return 'at_risk_of_loss';
        }
        if ($account->getOverdueDays() > self::TERMINATION_THRESHOLD_DAYS) {
            return 'pending_termination';
        }
        return 'lost';
    }

    private function determineNextSteps(string $label): array
    {
        return match ($label) {
            'good_standing' => ['continue_normal_operations'],
            'attention_required' => ['send_reminder', 'monitor_closely'],
            'at_risk_of_loss' => ['trigger_retention_flow', 'escalate'],
            'pending_termination' => ['final_notice', 'offer_last_chance'],
            'lost' => ['cancel_services', 'initiate_win_back'],
        };
    }
}
