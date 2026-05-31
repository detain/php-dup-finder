<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Entity\Quote;
use App\Repository\QuoteRepository;
use Psr\Log\LoggerInterface;

final class QuotePricingService
{
    public function __construct(
        private readonly QuoteRepository $quoteRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculatePremium(int $quoteId): int
    {
        $quote = $this->quoteRepository->findById($quoteId);

        if ($quote === null) {
            throw new \RuntimeException('Quote not found');
        }

        $basePremium = $this->calculateBasePremium($quote);
        $coverageMultiplier = $this->getCoverageMultiplier($quote->getCoverageLevel());
        $riskAdjustment = $this->calculateRiskAdjustment($quote);
        $discounts = $this->calculateDiscounts($quote);

        $premium = (int) round($basePremium * $coverageMultiplier);
        $premium += $riskAdjustment;
        $premium -= $discounts;

        $minPremium = (int) round($quote->getCoverageAmount() * 0.005);
        if ($premium < $minPremium) {
            $premium = $minPremium;
        }

        $maxPremium = (int) round($quote->getCoverageAmount() * 0.05);
        if ($premium > $maxPremium) {
            $premium = $maxPremium;
        }

        return $premium;
    }

    private function calculateBasePremium(Quote $quote): int
    {
        $coverageAmount = $quote->getCoverageAmount();
        $insuredAge = $quote->getInsuredAge();

        if ($insuredAge <= 25) {
            $rate = 0.003;
        } elseif ($insuredAge <= 35) {
            $rate = 0.004;
        } elseif ($insuredAge <= 50) {
            $rate = 0.005;
        } elseif ($insuredAge <= 65) {
            $rate = 0.007;
        } else {
            $rate = 0.01;
        }

        return (int) round($coverageAmount * $rate);
    }

    private function getCoverageMultiplier(string $coverageLevel): float
    {
        return match ($coverageLevel) {
            'basic' => 1.0,
            'standard' => 1.25,
            'enhanced' => 1.5,
            'premium' => 1.75,
            'elite' => 2.0,
            default => 1.0,
        };
    }

    private function calculateRiskAdjustment(Quote $quote): int
    {
        $basePremium = $this->calculateBasePremium($quote);
        $riskScore = $quote->getRiskScore();
        $healthFactor = $quote->getHealthFactor();

        if ($riskScore >= 80) {
            return (int) round($basePremium * 0.5);
        } elseif ($riskScore >= 60) {
            return (int) round($basePremium * 0.25);
        }

        if ($healthFactor === 'poor') {
            return (int) round($basePremium * 0.3);
        } elseif ($healthFactor === 'fair') {
            return (int) round($basePremium * 0.1);
        }

        return 0;
    }

    private function calculateDiscounts(Quote $quote): int
    {
        $basePremium = $this->calculateBasePremium($quote);
        $totalDiscount = 0;

        if ($quote->hasAnnualPayment()) {
            $totalDiscount += (int) round($basePremium * 0.10);
        }

        if ($quote->getPolicyTerm() >= 20) {
            $totalDiscount += (int) round($basePremium * 0.05);
        }

        if ($quote->hasNoClaimsBonus()) {
            $totalDiscount += (int) round($basePremium * 0.15);
        }

        if ($quote->isBundledWithAuto()) {
            $totalDiscount += (int) round($basePremium * 0.12);
        }

        return $totalDiscount;
    }
}
