<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Entity\Product;
use App\Repository\DiscountRepository;
use Psr\Log\LoggerInterface;

final class FixedDiscountCalculator
{
    public function __construct(
        private readonly DiscountRepository $discountRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Calculates the discounted price using fixed amount discount.
     *
     * This approach subtracts a fixed amount from the original price.
     * For example, $15 off a $100 product yields $85.
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
        $fixedDiscountAmount = $discount->getFixedAmount();

        if ($fixedDiscountAmount <= 0) {
            $this->logger->warning('Invalid fixed discount amount', [
                'fixed_amount' => $fixedDiscountAmount,
            ]);
            return $originalPrice;
        }

        $finalPrice = max(0, $originalPrice - $fixedDiscountAmount);

        $this->logger->debug('Fixed discount calculated', [
            'product_id' => $product->getId(),
            'original_price' => $originalPrice,
            'fixed_discount' => $fixedDiscountAmount,
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
