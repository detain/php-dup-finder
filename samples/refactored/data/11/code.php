<?php
declare(strict_types=1);

namespace Shared\Tax;

use Psr\Log\LoggerInterface;

final class UsStateTaxRateProvider
{
    private const STATE_TAX_RATES = [
        'CA' => 0.0725,
        'TX' => 0.0625,
        'NY' => 0.08,
        'FL' => 0.06,
        'WA' => 0.065,
        'IL' => 0.0625,
        'PA' => 0.06,
        'OH' => 0.0575,
        'GA' => 0.04,
        'NC' => 0.0475,
        'MI' => 0.06,
        'NJ' => 0.066,
        'VA' => 0.053,
        'AZ' => 0.056,
        'MA' => 0.0625,
        'TN' => 0.07,
        'IN' => 0.07,
        'MO' => 0.04225,
        'MD' => 0.06,
        'WI' => 0.05,
    ];

    private const CATEGORY_EXEMPT_STATES = [
        'OR' => true,
        'MT' => true,
        'NH' => true,
        'DE' => true,
    ];

    public function getRateForState(string $stateCode): ?float
    {
        return self::STATE_TAX_RATES[strtoupper(trim($stateCode))] ?? null;
    }

    public function isExemptState(string $stateCode): bool
    {
        return isset(self::CATEGORY_EXEMPT_STATES[strtoupper(trim($stateCode))]);
    }

    public function getDefaultRate(): float
    {
        return 0.05;
    }

    public function getAllRates(): array
    {
        return self::STATE_TAX_RATES;
    }
}

interface TaxCalculatorInterface
{
    public function calculate(mixed $entity, string $stateCode): array;
}

trait TaxCalculationLogic
{
    private UsStateTaxRateProvider $rateProvider;
    private LoggerInterface $logger;

    protected function computeTax(
        float $baseAmount,
        string $stateCode,
        float $additionalRate = 0.0,
    ): array {
        $stateCode = strtoupper(trim($stateCode));

        if ($this->rateProvider->isExemptState($stateCode)) {
            return $this->buildTaxResult($baseAmount, $stateCode, 0.0);
        }

        $baseRate = $this->rateProvider->getRateForState($stateCode) ?? $this->rateProvider->getDefaultRate();
        $finalRate = $baseRate + $additionalRate;
        $taxAmount = round($baseAmount * $finalRate, 2);

        return $this->buildTaxResult($baseAmount, $stateCode, $taxAmount, $finalRate);
    }

    protected function buildTaxResult(float $baseAmount, string $state, float $taxAmount, ?float $rate = null): array
    {
        return [
            'base_amount' => $baseAmount,
            'state' => $state,
            'tax_rate' => $rate ?? 0.0,
            'tax_amount' => $taxAmount,
            'total' => $baseAmount + $taxAmount,
            'calculated_at' => date('c'),
        ];
    }
}
