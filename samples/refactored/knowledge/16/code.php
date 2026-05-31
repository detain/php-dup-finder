<?php
declare(strict_types=1);

namespace App\Tax\Policy;

final class TaxPolicy
{
    public const DEFAULT_RATE = 0.20;
    public const REDUCED_RATE = 0.10;
    public const ZERO_RATE = 0.0;

    public function __construct(
        public readonly float $standardRate = self::DEFAULT_RATE,
        public readonly float $reducedRate = self::REDUCED_RATE,
        public readonly float $zeroRate = self::ZERO_RATE,
        public readonly array $exemptCategories = [],
        public readonly array $digitalCategories = [],
        public readonly array $countryOverrides = []
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self(
            standardRate: $config['standard_rate'] ?? self::DEFAULT_RATE,
            reducedRate: $config['reduced_rate'] ?? self::REDUCED_RATE,
            exemptCategories: $config['exempt_categories'] ?? [],
            digitalCategories: $config['digital_categories'] ?? [],
            countryOverrides: $config['country_overrides'] ?? []
        );
    }

    public function getRateForProduct(string $category, string $countryCode): float
    {
        if ($this->isExempt($category)) {
            return $this->zeroRate;
        }

        if ($this->isDigital($category) && $countryCode === 'US') {
            return $this->zeroRate;
        }

        if ($this->isReducedRate($category)) {
            return $this->reducedRate;
        }

        return $this->standardRate;
    }

    public function isExempt(string $category): bool
    {
        return in_array($category, $this->exemptCategories, true);
    }

    public function isDigital(string $category): bool
    {
        return in_array($category, $this->digitalCategories, true);
    }

    public function isReducedRate(string $category): bool
    {
        $reducedCategories = ['books', 'newspapers', 'cultural_events'];
        return in_array($category, $reducedCategories, true);
    }

    public function calculate(float $amount, string $category, string $countryCode): TaxCalculation
    {
        $rate = $this->getRateForProduct($category, $countryCode);
        $tax = round($amount * $rate, 2);

        return new TaxCalculation(
            grossAmount: $amount,
            rate: $rate,
            taxAmount: $tax,
            netAmount: $amount + $tax
        );
    }
}
