<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class TaxConfigLoader
{
    public const DEFAULT_TAX_RATE = 0.20;
    public const REDUCED_TAX_RATE = 0.10;
    public const ZERO_TAX_RATE = 0.0;

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getDefaultTaxRate(): float
    {
        return $this->config['tax']['default_rate'] ?? self::DEFAULT_TAX_RATE;
    }

    public function getReducedTaxRate(): float
    {
        return $this->config['tax']['reduced_rate'] ?? self::REDUCED_TAX_RATE;
    }

    public function getTaxExemptCategories(): array
    {
        return $this->config['tax']['exempt_categories'] ?? [
            'food',
            'medicine',
            'books',
            'children_clothing'
        ];
    }

    public function getDigitalProductCategories(): array
    {
        return $this->config['tax']['digital_categories'] ?? [
            'software',
            'ebooks',
            'music',
            'video',
            'subscriptions'
        ];
    }

    public function getTaxRateForProduct(string $category, string $countryCode): float
    {
        if ($this->isCategoryExempt($category)) {
            return self::ZERO_TAX_RATE;
        }

        if ($this->isDigitalProduct($category) && $countryCode === 'US') {
            return self::ZERO_TAX_RATE;
        }

        if ($this->isReducedRateCategory($category)) {
            return $this->getReducedTaxRate();
        }

        return $this->getDefaultTaxRate();
    }

    public function isCategoryExempt(string $category): bool
    {
        return in_array($category, $this->getTaxExemptCategories(), true);
    }

    public function isDigitalProduct(string $category): bool
    {
        return in_array($category, $this->getDigitalProductCategories(), true);
    }

    public function isReducedRateCategory(string $category): bool
    {
        $reducedRateCategories = [
            'reduced_books',
            'reduced_newspapers',
            'reduced_cultural_events'
        ];

        return in_array($category, $reducedRateCategories, true);
    }

    public function calculateTax(float $amount, string $category, string $countryCode): array
    {
        $rate = $this->getTaxRateForProduct($category, $countryCode);
        $taxAmount = round($amount * $rate, 2);
        $total = round($amount + $taxAmount, 2);

        return [
            'amount' => $amount,
            'rate' => $rate,
            'tax_amount' => $taxAmount,
            'total' => $total
        ];
    }

    public function getCountrySpecificRates(string $countryCode): array
    {
        $countryRates = $this->config['tax']['country_rates'] ?? [];

        return [
            'default' => $countryRates[$countryCode]['default'] ?? $this->getDefaultTaxRate(),
            'reduced' => $countryRates[$countryCode]['reduced'] ?? $this->getReducedTaxRate(),
        ];
    }

    public function requiresDigitalGoodsReporting(string $countryCode): bool
    {
        $reportingCountries = [
            'US', 'GB', 'DE', 'FR', 'IT', 'ES', 'PT', 'AU'
        ];

        return in_array($countryCode, $reportingCountries, true);
    }
}
