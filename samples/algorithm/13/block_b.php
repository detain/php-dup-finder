<?php
declare(strict_types=1);

namespace SalesComp\Commission;

use Psr\Log\LoggerInterface;

final class FlatCommissionCalculator
{
    private const TIER_1_THRESHOLD = 0;
    private const TIER_1_RATE = 0.05;
    private const TIER_2_THRESHOLD = 10000;
    private const TIER_2_RATE = 0.07;
    private const TIER_3_THRESHOLD = 25000;
    private const TIER_3_RATE = 0.10;
    private const TIER_4_THRESHOLD = 50000;
    private const TIER_4_RATE = 0.12;
    private const TIER_5_THRESHOLD = 100000;
    private const TIER_5_RATE = 0.15;

    private const BONUS_MULTIPLIER_GOLD = 1.1;
    private const BONUS_MULTIPLIER_PLATINUM = 1.2;
    private const BONUS_MULTIPLIER_DIAMOND = 1.3;

    private const BASE_COMMISSION_FLOOR = 100.00;
    private const MAX_COMMISSION_RATE = 0.25;
    private const PROCESSING_FEE_FLAT = 25.00;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateCommission(array $salesData, SalesPerson $salesPerson): CommissionResult
    {
        $this->logger->debug('Calculating flat commission', [
            'salesperson_id' => $salesPerson->getId(),
            'sales_amount' => $salesData['total_sales'],
        ]);

        $totalSales = (float)$salesData['total_sales'];
        $flatCommission = $this->calculateFlatAmount($totalSales);
        $bonusMultiplier = $this->getBonusMultiplier($salesPerson->getTier());
        $grossCommission = $flatCommission * $bonusMultiplier;

        $processingFee = self::PROCESSING_FEE_FLAT;
        $netCommission = $grossCommission - $processingFee;

        if ($netCommission < self::BASE_COMMISSION_FLOOR && $totalSales > 0) {
            $netCommission = self::BASE_COMMISSION_FLOOR;
        }

        $effectiveRate = $totalSales > 0 ? $netCommission / $totalSales : 0;
        if ($effectiveRate > self::MAX_COMMISSION_RATE) {
            $effectiveRate = self::MAX_COMMISSION_RATE;
            $netCommission = $totalSales * $effectiveRate;
        }

        $this->logger->info('Flat commission calculated', [
            'flat_amount' => $flatCommission,
            'bonus_multiplier' => $bonusMultiplier,
            'processing_fee' => $processingFee,
            'net_commission' => $netCommission,
            'effective_rate' => $effectiveRate,
        ]);

        return new CommissionResult(
            grossCommission: $grossCommission,
            retentionHoldback: $processingFee,
            netCommission: $netCommission,
            effectiveRate: $effectiveRate,
            tierAchieved: $this->determineTier($totalSales),
            bonusMultiplierApplied: $bonusMultiplier,
        );
    }

    private function calculateFlatAmount(float $totalSales): float
    {
        $tier = $this->determineTier($totalSales);
        $rate = $this->getRateForTier($tier);
        return $totalSales * $rate;
    }

    private function getRateForTier(string $tier): float
    {
        return match ($tier) {
            'tier_5' => self::TIER_5_RATE,
            'tier_4' => self::TIER_4_RATE,
            'tier_3' => self::TIER_3_RATE,
            'tier_2' => self::TIER_2_RATE,
            default => self::TIER_1_RATE,
        };
    }

    private function getBonusMultiplier(string $tier): float
    {
        return match ($tier) {
            'gold' => self::BONUS_MULTIPLIER_GOLD,
            'platinum' => self::BONUS_MULTIPLIER_PLATINUM,
            'diamond' => self::BONUS_MULTIPLIER_DIAMOND,
            default => 1.0,
        };
    }

    private function determineTier(float $totalSales): string
    {
        if ($totalSales >= self::TIER_5_THRESHOLD) {
            return 'tier_5';
        }
        if ($totalSales >= self::TIER_4_THRESHOLD) {
            return 'tier_4';
        }
        if ($totalSales >= self::TIER_3_THRESHOLD) {
            return 'tier_3';
        }
        if ($totalSales >= self::TIER_2_THRESHOLD) {
            return 'tier_2';
        }
        return 'tier_1';
    }
}
