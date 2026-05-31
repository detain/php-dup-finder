<?php
declare(strict_types=1);

namespace SalesComp\Shared;

final class TierThresholds
{
    public const TIER_1 = 0;
    public const TIER_2 = 10000;
    public const TIER_3 = 25000;
    public const TIER_4 = 50000;
    public const TIER_5 = 100000;

    public const RATES = [
        'tier_1' => 0.05,
        'tier_2' => 0.07,
        'tier_3' => 0.10,
        'tier_4' => 0.12,
        'tier_5' => 0.15,
    ];
}

final class TierBonusMultipliers
{
    public const GOLD = 1.1;
    public const PLATINUM = 1.2;
    public const DIAMOND = 1.3;

    public static function getForTier(string $tier): float
    {
        return match ($tier) {
            'gold' => self::GOLD,
            'platinum' => self::PLATINUM,
            'diamond' => self::DIAMOND,
            default => 1.0,
        };
    }
}

interface CommissionCalculationStrategy
{
    public function calculate(float $totalSales, string $tier, array $context = []): float;
}

final class TieredCommissionStrategy implements CommissionCalculationStrategy
{
    public function calculate(float $totalSales, string $tier, array $context = []): float
    {
        $commission = 0.0;
        $remaining = $totalSales;

        $tiers = [
            ['min' => TierThresholds::TIER_5, 'rate' => TierThresholds::RATES['tier_5']],
            ['min' => TierThresholds::TIER_4, 'rate' => TierThresholds::RATES['tier_4']],
            ['min' => TierThresholds::TIER_3, 'rate' => TierThresholds::RATES['tier_3']],
            ['min' => TierThresholds::TIER_2, 'rate' => TierThresholds::RATES['tier_2']],
            ['min' => TierThresholds::TIER_1, 'rate' => TierThresholds::RATES['tier_1']],
        ];

        $previousMin = 0;
        foreach ($tiers as $t) {
            if ($remaining <= 0) {
                break;
            }
            $eligibleInTier = max(0, min($remaining, $totalSales - $t['min']));
            if ($totalSales > $t['min'] && $eligibleInTier > 0) {
                $commission += $eligibleInTier * $t['rate'];
                $remaining -= $eligibleInTier;
            }
            $previousMin = $t['min'];
        }

        return $commission;
    }
}

final class FlatCommissionStrategy implements CommissionCalculationStrategy
{
    public function calculate(float $totalSales, string $tier, array $context = []): float
    {
        $rate = $this->getRateForSales($totalSales);
        return $totalSales * $rate;
    }

    private function getRateForSales(float $totalSales): float
    {
        if ($totalSales >= TierThresholds::TIER_5) {
            return TierThresholds::RATES['tier_5'];
        }
        if ($totalSales >= TierThresholds::TIER_4) {
            return TierThresholds::RATES['tier_4'];
        }
        if ($totalSales >= TierThresholds::TIER_3) {
            return TierThresholds::RATES['tier_3'];
        }
        if ($totalSales >= TierThresholds::TIER_2) {
            return TierThresholds::RATES['tier_2'];
        }
        return TierThresholds::RATES['tier_1'];
    }
}

abstract class BaseCommissionCalculator
{
    protected CommissionCalculationStrategy $strategy;
    protected LoggerInterface $logger;

    protected const FLOOR = 100.00;
    protected const MAX_RATE = 0.25;

    public function calculateCommission(array $salesData, SalesPerson $person): CommissionResult
    {
        $totalSales = (float)$salesData['total_sales'];
        $tier = $person->getTier();

        $grossCommission = $this->strategy->calculate($totalSales, $tier, $salesData);
        $bonusMultiplier = TierBonusMultipliers::getForTier($tier);
        $beforeBonus = $grossCommission;
        $grossCommission *= $bonusMultiplier;

        $adjustment = $this->calculateAdjustment($grossCommission, $totalSales, $salesData);
        $netCommission = $grossCommission + $adjustment;

        if ($netCommission < self::FLOOR && $totalSales > 0) {
            $netCommission = self::FLOOR;
        }

        $effectiveRate = min($netCommission / $totalSales, self::MAX_RATE);

        return new CommissionResult(
            grossCommission: $beforeBonus,
            retentionHoldback: abs($adjustment),
            netCommission: $netCommission,
            effectiveRate: $effectiveRate,
            tierAchieved: $this->determineTier($totalSales),
            bonusMultiplierApplied: $bonusMultiplier,
        );
    }

    abstract protected function calculateAdjustment(float $gross, float $sales, array $data): float;
    abstract protected function determineTier(float $totalSales): string;
}

final class TieredCommissionCalculator extends BaseCommissionCalculator
{
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->strategy = new TieredCommissionStrategy();
    }

    protected function calculateAdjustment(float $gross, float $sales, array $data): float
    {
        return -($gross * 0.05);
    }

    protected function determineTier(float $totalSales): string
    {
        if ($totalSales >= TierThresholds::TIER_5) return 'tier_5';
        if ($totalSales >= TierThresholds::TIER_4) return 'tier_4';
        if ($totalSales >= TierThresholds::TIER_3) return 'tier_3';
        if ($totalSales >= TierThresholds::TIER_2) return 'tier_2';
        return 'tier_1';
    }
}
