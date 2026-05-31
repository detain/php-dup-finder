<?php
declare(strict_types=1);

namespace Finance\Rules;

final class AccountTierDeterminator
{
    private const TIER_BRONZE_MAX = 1000;
    private const TIER_SILVER_MAX = 5000;
    private const TIER_GOLD_MAX = 10000;
    private const TIER_PLATINUM_MAX = 25000;

    private const INCOME_THRESHOLD_1 = 30000;
    private const INCOME_THRESHOLD_2 = 60000;
    private const INCOME_THRESHOLD_3 = 100000;

    private const SCORE_THRESHOLD_POOR = 580;
    private const SCORE_THRESHOLD_FAIR = 650;
    private const SCORE_THRESHOLD_GOOD = 700;
    private const SCORE_THRESHOLD_EXCELLENT = 750;

    public function determineAccountTier(AccountApplication $application): AccountTierResult
    {
        $proposedLimit = $this->calculateProposedLimit($application);
        $accountTier = $this->assignTier($proposedLimit);

        $eligibilityScore = $this->computeEligibilityScore($application);
        $approvalFlag = $this->assessApproval($application, $proposedLimit, $eligibilityScore);

        return new AccountTierResult(
            tier: $accountTier,
            proposedCreditLimit: $proposedLimit,
            eligibilityScore: $eligibilityScore,
            approved: $approvalFlag,
            conditionalOffers: $this->generateConditionalOffers($application),
        );
    }

    private function calculateProposedLimit(AccountApplication $application): int
    {
        $startingLimit = $this->determineStartingLimitFromIncome($application->getVerifiedIncome());

        $creditFactor = $this->computeCreditScoreFactor($application->getCreditScore());

        $adjustedLimit = (int)($startingLimit * $creditFactor);

        $liabilitiesImpact = $this->assessLiabilitiesImpact($application);
        $adjustedLimit = (int)($adjustedLimit * $liabilitiesImpact);

        if ($application->hasPublicRecords()) {
            $adjustedLimit = (int)($adjustedLimit * 0.25);
        }

        if ($application->hasRecentDelinquencies()) {
            $adjustedLimit = (int)($adjustedLimit * 0.5);
        }

        return max(300, $adjustedLimit);
    }

    private function determineStartingLimitFromIncome(int $annualIncome): int
    {
        if ($annualIncome >= self::INCOME_THRESHOLD_3) {
            return self::TIER_PLATINUM_MAX;
        }

        if ($annualIncome >= self::INCOME_THRESHOLD_2) {
            return self::TIER_GOLD_MAX;
        }

        if ($annualIncome >= self::INCOME_THRESHOLD_1) {
            return self::TIER_SILVER_MAX;
        }

        return self::TIER_BRONZE_MAX;
    }

    private function computeCreditScoreFactor(int $creditScore): float
    {
        if ($creditScore >= self::SCORE_THRESHOLD_EXCELLENT) {
            return 1.5;
        }

        if ($creditScore >= self::SCORE_THRESHOLD_GOOD) {
            return 1.25;
        }

        if ($creditScore >= self::SCORE_THRESHOLD_FAIR) {
            return 1.0;
        }

        if ($creditScore >= self::SCORE_THRESHOLD_POOR) {
            return 0.75;
        }

        return 0.5;
    }

    private function assessLiabilitiesImpact(AccountApplication $application): float
    {
        $debtToIncome = $application->getDebtToIncomeRatio();

        if ($debtToIncome >= 0.40) {
            return 0.4;
        }

        if ($debtToIncome >= 0.30) {
            return 0.6;
        }

        if ($debtToIncome >= 0.20) {
            return 0.8;
        }

        return 1.0;
    }

    private function assignTier(int $creditLimit): string
    {
        if ($creditLimit >= self::TIER_PLATINUM_MAX) {
            return 'platinum';
        }

        if ($creditLimit >= self::TIER_GOLD_MAX) {
            return 'gold';
        }

        if ($creditLimit >= self::TIER_SILVER_MAX) {
            return 'silver';
        }

        return 'bronze';
    }

    private function computeEligibilityScore(AccountApplication $application): int
    {
        $baseScore = 0;

        $creditScore = $application->getCreditScore();
        if ($creditScore >= 750) {
            $baseScore += 40;
        } elseif ($creditScore >= 700) {
            $baseScore += 30;
        } elseif ($creditScore >= 650) {
            $baseScore += 20;
        } else {
            $baseScore += 10;
        }

        $income = $application->getVerifiedIncome();
        if ($income >= 100000) {
            $baseScore += 30;
        } elseif ($income >= 60000) {
            $baseScore += 20;
        } else {
            $baseScore += 10;
        }

        if ($application->hasPublicRecords()) {
            $baseScore -= 30;
        }

        if ($application->hasRecentDelinquencies()) {
            $baseScore -= 20;
        }

        return max(0, min(100, $baseScore));
    }

    private function assessApproval(AccountApplication $application, int $proposedLimit, int $eligibilityScore): bool
    {
        if ($eligibilityScore < 30) {
            return false;
        }

        if ($proposedLimit < 500) {
            return false;
        }

        if ($application->hasBankruptcy()) {
            return false;
        }

        return true;
    }

    private function generateConditionalOffers(AccountApplication $application): array
    {
        $offers = [];

        if ($application->getCreditScore() < 650) {
            $offers[] = 'starter_card_with_security_deposit';
        }

        if ($application->getVerifiedIncome() > 50000) {
            $offers[] = 'waived_annual_fee_first_year';
        }

        return $offers;
    }
}
