<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Entity\Product;
use App\Repository\DiscountRepository;
use Psr\Log\LoggerInterface;

final class PercentageDiscountCalculator
{
    public function __construct(
        private readonly DiscountRepository $discountRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculates the discounted price using percentage-based discount.
     *
     * This approach applies a percentage off the original price.
     * For example, 20% off a $100 product yields $80.
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
        $discountPercentage = $discount->getPercentage();

        if ($discountPercentage <= 0 || $discountPercentage > 100) {
            $this->logger->warning('Invalid discount percentage', [
                'discount_percentage' => $discountPercentage,
            ]);
            return $originalPrice;
        }

        $discountAmount = (int) round($originalPrice * ($discountPercentage / 100));
        $finalPrice = $originalPrice - $discountAmount;

        $this->logger->debug('Percentage discount calculated', [
            'product_id' => $product->getId(),
            'original_price' => $originalPrice,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
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
