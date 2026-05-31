<?php
declare(strict_types=1);

namespace RetailConnect\Pricing\Tax;

use Psr\Log\LoggerInterface;
use RetailConnect\Pricing\Entities\Product;
use RetailConnect\Pricing\Repository\TaxRateRepository;

final class UsSalesTaxCalculator
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

    private const DIGITAL_GOODS_ADDITIONAL_TAX = 0.02;

    public function __construct(
        private readonly TaxRateRepository $taxRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateTax(Product $product, string $stateCode, bool $isDigitalGoods): array
    {
        $stateCode = strtoupper(trim($stateCode));

        if ($this->isExemptState($stateCode)) {
            $this->logger->info('Tax exemption applied for state', [
                'state' => $stateCode,
                'product_id' => $product->getId(),
            ]);
            return $this->buildTaxResult($product, $stateCode, 0.0);
        }

        $baseRate = $this->getStateTaxRate($stateCode);
        if ($baseRate === null) {
            $this->logger->warning('Unknown state code, using default rate', [
                'state' => $stateCode,
                'default_rate' => 0.05,
            ]);
            $baseRate = 0.05;
        }

        $finalRate = $isDigitalGoods ? $baseRate + self::DIGITAL_GOODS_ADDITIONAL_TAX : $baseRate;
        $taxAmount = round($product->getPrice() * $finalRate, 2);

        $this->logger->debug('Tax calculated', [
            'product_id' => $product->getId(),
            'state' => $stateCode,
            'base_rate' => $baseRate,
            'final_rate' => $finalRate,
            'tax_amount' => $taxAmount,
        ]);

        return $this->buildTaxResult($product, $stateCode, $taxAmount, $finalRate);
    }

    private function isExemptState(string $stateCode): bool
    {
        return isset(self::CATEGORY_EXEMPT_STATES[$stateCode]);
    }

    private function getStateTaxRate(string $stateCode): ?float
    {
        return self::STATE_TAX_RATES[$stateCode] ?? null;
    }

    private function buildTaxResult(Product $product, string $state, float $taxAmount, ?float $rate = null): array
    {
        return [
            'product_id' => $product->getId(),
            'state' => $state,
            'taxable_amount' => $product->getPrice(),
            'tax_rate' => $rate ?? 0.0,
            'tax_amount' => $taxAmount,
            'total_price' => $product->getPrice() + $taxAmount,
            'calculated_at' => date('c'),
        ];
    }
}
