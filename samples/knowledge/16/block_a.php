<?php
declare(strict_types=1);

namespace App\Shipping\Service;

use App\Shipping\Repository\ShippingRateRepository;
use App\Shipping\Entity\ShippingRate;
use App\Shipping\Exception\ShippingException;
use Psr\Log\LoggerInterface;

final class ShippingCalculationService
{
    public const DEFAULT_TAX_RATE = 0.20;
    public const REDUCED_TAX_RATE = 0.10;
    public const ZERO_RATED_TAX = 0.0;

    public const TAX_EXEMPT_CATEGORIES = [
        'food',
        'medicine',
        'books',
        'children_clothing'
    ];

    public const DIGITAL_PRODUCT_CATEGORIES = [
        'software',
        'ebooks',
        'music',
        'video',
        'subscriptions'
    ];

    private ShippingRateRepository $rateRepo;
    private LoggerInterface $logger;

    public function __construct(
        ShippingRateRepository $rateRepo,
        LoggerInterface $logger
    ) {
        $this->rateRepo = $rateRepo;
        $this->logger = $logger;
    }

    public function calculateShipping(array $items, string $countryCode, string $postalCode): ShippingQuote
    {
        $subtotal = $this->calculateSubtotal($items);

        $baseShipping = $this->calculateBaseShipping($items, $countryCode);

        $weightSurcharge = $this->calculateWeightSurcharge($items);

        $remoteSurcharge = $this->calculateRemoteAreaSurcharge($countryCode, $postalCode);

        $subtotalWithShipping = $subtotal + $baseShipping + $weightSurcharge + $remoteSurcharge;

        $taxRate = $this->determineTaxRate($items, $countryCode);
        $taxAmount = $subtotalWithShipping * $taxRate;

        $total = $subtotalWithShipping + $taxAmount;

        $this->logger->info('Shipping calculated', [
            'subtotal' => $subtotal,
            'base_shipping' => $baseShipping,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total
        ]);

        return new ShippingQuote([
            'subtotal' => $subtotal,
            'base_shipping' => $baseShipping,
            'weight_surcharge' => $weightSurcharge,
            'remote_surcharge' => $remoteSurcharge,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'currency' => 'USD'
        ]);
    }

    public function determineTaxRate(array $items, string $countryCode): float
    {
        foreach ($items as $item) {
            $category = $item['category'] ?? 'standard';

            if (in_array($category, self::TAX_EXEMPT_CATEGORIES, true)) {
                return self::ZERO_RATED_TAX;
            }

            if (in_array($category, self::DIGITAL_PRODUCT_CATEGORIES, true)) {
                if ($countryCode === 'US') {
                    return self::ZERO_RATED_TAX;
                }
            }
        }

        if ($countryCode === 'DE') {
            return self::REDUCED_TAX_RATE;
        }

        return self::DEFAULT_TAX_RATE;
    }

    public function isTaxExempt(array $items): bool
    {
        foreach ($items as $item) {
            $category = $item['category'] ?? 'standard';

            if (in_array($category, self::TAX_EXEMPT_CATEGORIES, true)) {
                return true;
            }
        }

        return false;
    }

    public function isDigitalOnly(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            $category = $item['category'] ?? 'standard';

            if (!in_array($category, self::DIGITAL_PRODUCT_CATEGORIES, true)) {
                return false;
            }
        }

        return true;
    }

    private function calculateSubtotal(array $items): float
    {
        return array_reduce($items, function (float $sum, array $item) {
            return $sum + ($item['price'] * ($item['quantity'] ?? 1));
        }, 0.0);
    }

    private function calculateBaseShipping(array $items, string $countryCode): float
    {
        $totalWeight = $this->calculateTotalWeight($items);

        $baseRates = [
            'US' => 5.00,
            'CA' => 8.00,
            'GB' => 10.00,
            'DE' => 12.00,
            'FR' => 12.00,
            'AU' => 15.00,
        ];

        $baseRate = $baseRates[$countryCode] ?? 20.00;

        if ($totalWeight > 10) {
            $baseRate *= 1.5;
        }

        return round($baseRate, 2);
    }

    private function calculateWeightSurcharge(array $items): float
    {
        $totalWeight = $this->calculateTotalWeight($items);

        if ($totalWeight <= 5) {
            return 0.0;
        }

        if ($totalWeight <= 20) {
            return ($totalWeight - 5) * 0.50;
        }

        return ($totalWeight - 5) * 1.00;
    }

    private function calculateRemoteAreaSurcharge(string $countryCode, string $postalCode): float
    {
        $remotePostcodes = [
            'GB' => ['JE', 'GY', 'IM', 'BT', 'ZE', 'HS'],
            'DE' => ['27498', '27499'],
        ];

        foreach ($remotePostcodes[$countryCode] ?? [] as $prefix) {
            if (str_starts_with($postalCode, $prefix)) {
                return 15.00;
            }
        }

        return 0.0;
    }

    private function calculateTotalWeight(array $items): float
    {
        return array_reduce($items, function (float $sum, array $item) {
            return $sum + (($item['weight'] ?? 1) * ($item['quantity'] ?? 1));
        }, 0.0);
    }
}
