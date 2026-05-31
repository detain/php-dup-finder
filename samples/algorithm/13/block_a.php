<?php
declare(strict_types=1);

namespace SalesComp\Commission;

use Psr\Log\LoggerInterface;

final class TieredCommissionCalculator
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
    private const RETENTION_HOLDBACK_PERCENTAGE = 0.05;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateCommission(array $salesData, SalesPerson $salesPerson): CommissionResult
    {
        $this->logger->debug('Calculating tiered commission', [
            'salesperson_id' => $salesPerson->getId(),
            'sales_amount' => $salesData['total_sales'],
        ]);

        $totalSales = (float)$salesData['total_sales'];
        $tieredCommission = $this->calculateTieredAmount($totalSales);
        $bonusMultiplier = $this->getBonusMultiplier($salesPerson->getTier());
        $preliminaryCommission = $tieredCommission * $bonusMultiplier;

        $retentionHoldback = $preliminaryCommission * self::RETENTION_HOLDBACK_PERCENTAGE;
        $netCommission = $preliminaryCommission - $retentionHoldback;

        if ($netCommission < self::BASE_COMMISSION_FLOOR && $totalSales > 0) {
            $netCommission = self::BASE_COMMISSION_FLOOR;
        }

        $effectiveRate = $totalSales > 0 ? $netCommission / $totalSales : 0;
        if ($effectiveRate > self::MAX_COMMISSION_RATE) {
            $effectiveRate = self::MAX_COMMISSION_RATE;
            $netCommission = $totalSales * $effectiveRate;
        }

        $this->logger->info('Tiered commission calculated', [
            'tiered_amount' => $tieredCommission,
            'bonus_multiplier' => $bonusMultiplier,
            'retention_holdback' => $retentionHoldback,
            'net_commission' => $netCommission,
            'effective_rate' => $effectiveRate,
        ]);

        return new CommissionResult(
            grossCommission: $preliminaryCommission,
            retentionHoldback: $retentionHoldback,
            netCommission: $netCommission,
            effectiveRate: $effectiveRate,
            tierAchieved: $this->determineTier($totalSales),
            bonusMultiplierApplied: $bonusMultiplier,
        );
    }

    private function calculateTieredAmount(float $totalSales): float
    {
        $commission = 0.0;
        $remainingSales = $totalSales;

        $tiers = [
            ['threshold' => self::TIER_5_THRESHOLD, 'rate' => self::TIER_5_RATE, 'next_threshold' => PHP_INT_MAX],
            ['threshold' => self::TIER_4_THRESHOLD, 'rate' => self::TIER_4_RATE, 'next_threshold' => self::TIER_5_THRESHOLD],
            ['threshold' => self::TIER_3_THRESHOLD, 'rate' => self::TIER_3_RATE, 'next_threshold' => self::TIER_4_THRESHOLD],
            ['threshold' => self::TIER_2_THRESHOLD, 'rate' => self::TIER_2_RATE, 'next_threshold' => self::TIER_3_THRESHOLD],
            ['threshold' => self::TIER_1_THRESHOLD, 'rate' => self::TIER_1_RATE, 'next_threshold' => self::TIER_2_THRESHOLD],
        ];

        $previousThreshold = 0;
        foreach ($tiers as $tier) {
            if ($remainingSales <= 0) {
                break;
            }

            $tierSales = min($remainingSales, $tier['next_threshold'] - $previousThreshold);
            if ($totalSales > $tier['threshold']) {
                $eligibleSales = min($tierSales, $totalSales - $tier['threshold']);
                if ($eligibleSales > 0) {
                    $commission += $eligibleSales * $tier['rate'];
                    $remainingSales -= $eligibleSales;
                }
            }
            $previousThreshold = $tier['threshold'];
        }

        return $commission;
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
