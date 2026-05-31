<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Log\LoggerInterface;

interface FeeCalculationStrategyInterface
{
    public function calculate(int $amount, array $factors = []): int;
    public function getName(): string;
}

abstract class AbstractFeeCalculator
{
    protected array $tierMultipliers = [];
    protected array $tierRates = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function getRateForAmount(int $amount, array $brackets): float
    {
        foreach ($brackets as $bracket) {
            if ($amount <= $bracket['max']) {
                return $bracket['rate'];
            }
        }

        return end($brackets)['rate'];
    }

    protected function applyTierMultiplier(float $rate, string $tier): float
    {
        $multiplier = $this->tierMultipliers[$tier] ?? 1.0;
        return $rate * $multiplier;
    }
}

final class TieredRateStrategy extends AbstractFeeCalculator implements FeeCalculationStrategyInterface
{
    private array $rateBrackets;

    public function __construct(array $rateBrackets, array $tierMultipliers, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->rateBrackets = $rateBrackets;
        $this->tierMultipliers = $tierMultipliers;
    }

    public function calculate(int $amount, array $factors = []): int
    {
        $rate = $this->getRateForAmount($amount, $this->rateBrackets);
        $tier = $factors['tier'] ?? 'standard';

        $rate = $this->applyTierMultiplier($rate, $tier);

        return (int) round($amount * $rate);
    }

    public function getName(): string
    {
        return 'tiered_rate';
    }
}

final class FlatFeeStrategy implements FeeCalculationStrategyInterface
{
    private array $amountThresholds;
    private array $tierMultipliers;

    public function __construct(array $amountThresholds, array $tierMultipliers)
    {
        $this->amountThresholds = $amountThresholds;
        $this->tierMultipliers = $tierMultipliers;
    }

    public function calculate(int $amount, array $factors = []): int
    {
        $flatFee = $this->getFlatFeeForAmount($amount);
        $tier = $factors['tier'] ?? 'standard';

        $multiplier = $this->tierMultipliers[$tier] ?? 1.0;

        return (int) round($flatFee * $multiplier);
    }

    private function getFlatFeeForAmount(int $amount): int
    {
        foreach ($this->amountThresholds as $threshold) {
            if ($amount <= $threshold['max']) {
                return $threshold['fee'];
            }
        }

        return end($this->amountThresholds)['fee'];
    }

    public function getName(): string
    {
        return 'flat_fee';
    }
}

final class FeeCalculatorOrchestrator
{
    /** @var FeeCalculationStrategyInterface[] */
    private array $strategies = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerStrategy(FeeCalculationStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
    }

    public function calculate(string $strategyName, int $amount, array $factors = []): int
    {
        $strategy = $this->strategies[$strategyName] ?? null;

        if ($strategy === null) {
            throw new \InvalidArgumentException("Unknown strategy: {$strategyName}");
        }

        return $strategy->calculate($amount, $factors);
    }
}
