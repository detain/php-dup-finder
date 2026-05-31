<?php
declare(strict_types=1);

namespace Compliance\Rules;

final class AntiMoneyLaunderingRule
{
    private const CASH_REPORTING_THRESHOLD = 10000;
    private const STRUCTURING_THRESHOLD = 9000;
    private const SAR_THRESHOLD = 25000;

    private const DAILY_CASH_LIMIT = 50000;
    private const WEEKLY_CASH_LIMIT = 100000;
    private const MONTHLY_CASH_LIMIT = 500000;

    private const TRANSACTION_BURST_COUNT = 6;
    private const BURST_TIMEFRAME_MINUTES = 30;

    public function processTransaction(AMLTransactionContext $context): AMLDecision
    {
        $transactionAmount = $context->getAmount();
        $customerIdentifier = $context->getCustomerId();

        $thresholdBreaches = $this->identifyThresholdBreaches($transactionAmount, $customerIdentifier);
        $suspiciousPatterns = $this->detectSuspiciousPatterns($context);
        $riskFactors = $this->evaluateRiskFactors($context);

        $amlScore = $this->computeAMLikelihoodScore(
            $thresholdBreaches,
            $suspiciousPatterns,
            $riskFactors
        );

        $requiredAction = $this->determineRequiredAction($amlScore, $thresholdBreaches);

        return new AMLDecision(
            action: $requiredAction,
            riskScore: $amlScore,
            thresholdAlerts: $thresholdBreaches,
            patternAlerts: $suspiciousPatterns,
            riskFactors: $riskFactors,
        );
    }

    private function identifyThresholdBreaches(float $amount, string $customerId): array
    {
        $breaches = [];

        if ($amount >= self::SAR_THRESHOLD) {
            $breaches[] = 'sar_filing_required';
        }

        if ($amount >= self::CASH_REPORTING_THRESHOLD) {
            $breaches[] = 'currency_transaction_report_triggered';
        }

        if ($amount >= self::STRUCTURING_THRESHOLD && $amount < self::CASH_REPORTING_THRESHOLD) {
            $breaches[] = 'potential_structuring_detected';
        }

        $todayTotal = $this->calculateDailyTotal($customerId);
        if (($todayTotal + $amount) > self::DAILY_CASH_LIMIT) {
            $breaches[] = 'daily_cash_limit_exceeded';
        }

        return $breaches;
    }

    private function detectSuspiciousPatterns(AMLTransactionContext $context): array
    {
        $patterns = [];

        $transactionFrequency = $this->getTransactionFrequency($context->getCustomerId());
        if ($transactionFrequency >= self::TRANSACTION_BURST_COUNT) {
            $patterns[] = 'burst_transaction_pattern';
        }

        $roundAmountFrequency = $this->countRoundAmountTransactions($context->getCustomerId());
        if ($roundAmountFrequency > 5) {
            $patterns[] = 'excessive_round_amounts';
        }

        $alternatingAmounts = $this->hasAlternatingAmountPattern($context->getCustomerId());
        if ($alternatingAmounts) {
            $patterns[] = 'alternating_amount_pattern_suspicious';
        }

        return $patterns;
    }

    private function evaluateRiskFactors(AMLTransactionContext $context): array
    {
        $factors = [];

        $isPoliticallyExposed = $context->isPEP();
        if ($isPoliticallyExposed) {
            $factors[] = 'politically_exposed_person';
        }

        $isSanctioned = $this->isOnSanctionsList($context->getCustomerId());
        if ($isSanctioned) {
            $factors[] = 'sanctions_list_match';
        }

        $adverseMedia = $this->hasAdverseMedia($context->getCustomerId());
        if ($adverseMedia) {
            $factors[] = 'adverse_media_mention';
        }

        return $factors;
    }

    private function computeAMLikelihoodScore(
        array $thresholdBreaches,
        array $suspiciousPatterns,
        array $riskFactors
    ): float {
        $score = 0.0;

        $score += count($thresholdBreaches) * 0.30;
        $score += count($suspiciousPatterns) * 0.25;
        $score += count($riskFactors) * 0.35;

        return min(1.0, $score);
    }

    private function determineRequiredAction(array $thresholdBreaches, float $amlScore): string
    {
        if (in_array('sar_filing_required', $thresholdBreaches)) {
            return 'file_sar_and_block';
        }

        if (in_array('potential_structuring_detected', $thresholdBreaches)) {
            return 'enhanced_monitoring';
        }

        if ($amlScore >= 0.80) {
            return 'escalate_to_compliance';
        }

        if ($amlScore >= 0.50) {
            return 'flag_for_review';
        }

        return 'clear_transaction';
    }

    private function calculateDailyTotal(string $customerId): float
    {
        return 0.0;
    }

    private function getTransactionFrequency(string $customerId): int
    {
        return 0;
    }

    private function countRoundAmountTransactions(string $customerId): int
    {
        return 0;
    }

    private function hasAlternatingAmountPattern(string $customerId): bool
    {
        return false;
    }

    private function isOnSanctionsList(string $customerId): bool
    {
        return false;
    }
}
