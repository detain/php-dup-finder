<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Entity\Product;
use App\Repository\DiscountRepository;
use Psr\Log\LoggerInterface;

final class MultiplierDiscountCalculator
{
    public function __construct(
        private readonly DiscountRepository $discountRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculates the discounted price using a price multiplier.
     *
     * This approach multiplies the original price by a factor less than 1.
     * For example, 0.8x multiplier on $100 yields $80 (20% off).
     */
    public function calculateDiscountedPrice(Product $product, string $discountCode): int
    {
        $discount = $this->discountRepository->findByCode($discountCode);

        if ($discount === null) {
            $this->logger->warning('Discount code not found', [
                'discount_code' => $discountCode,
                'product_id' => $product->getId(),
            ]);
            return $product->getPrice();
        }

        if (!$discount->isActive()) {
            $this->logger->debug('Discount code is inactive', [
                'discount_code' => $discountCode,
            ]);
            return $product->getPrice();
        }

        $originalPrice = $product->getPrice();
        $multiplier = $discount->getMultiplier();

        if ($multiplier <= 0 || $multiplier >= 1) {
            $this->logger->warning('Invalid discount multiplier', [
                'multiplier' => $multiplier,
            ]);
            return $originalPrice;
        }

        $finalPrice = (int) round($originalPrice * $multiplier);

        $this->logger->debug('Multiplier discount calculated', [
            'product_id' => $product->getId(),
            'original_price' => $originalPrice,
            'multiplier' => $multiplier,
            'final_price' => $finalPrice,
        ]);

        return $finalPrice;
    }

    public function getDiscountAmount(Product $product, string $discountCode): int
    {
        $originalPrice = $product->getPrice();
        $discountedPrice = $this->calculateDiscountedPrice($product, $discountCode);
        return $originalPrice - $discountedPrice;
    }
}
