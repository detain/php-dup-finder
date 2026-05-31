<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Entity\Product;
use App\Repository\DiscountRepository;
use Psr\Log\LoggerInterface;

interface DiscountStrategyInterface
{
    public function calculate(int $originalPrice, Discount $discount): int;
    public function getType(): string;
}

final class PercentageDiscountStrategy implements DiscountStrategyInterface
{
    public function calculate(int $originalPrice, Discount $discount): int
    {
        $percentage = $discount->getPercentage();
        $discountAmount = (int) round($originalPrice * ($percentage / 100));
        return $originalPrice - $discountAmount;
    }

    public function getType(): string
    {
        return 'percentage';
    }
}

final class FixedDiscountStrategy implements DiscountStrategyInterface
{
    public function calculate(int $originalPrice, Discount $discount): int
    {
        $fixedAmount = $discount->getFixedAmount();
        return max(0, $originalPrice - $fixedAmount);
    }

    public function getType(): string
    {
        return 'fixed';
    }
}

final class MultiplierDiscountStrategy implements DiscountStrategyInterface
{
    public function calculate(int $originalPrice, Discount $discount): int
    {
        $multiplier = $discount->getMultiplier();
        return (int) round($originalPrice * $multiplier);
    }

    public function getType(): string
    {
        return 'multiplier';
    }
}

final class DiscountCalculator
{
    /** @var DiscountStrategyInterface[] */
    private array $strategies = [];

    public function __construct(
        private readonly DiscountRepository $discountRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function registerStrategy(DiscountStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getType()] = $strategy;
    }

    public function calculateDiscountedPrice(Product $product, string $discountCode): int
    {
        $discount = $this->discountRepository->findByCode($discountCode);

        if ($discount === null || !$discount->isActive()) {
            return $product->getPrice();
        }

        $strategy = $this->strategies[$discount->getType()] ?? null;

        if ($strategy === null) {
            $this->logger->warning('No strategy found for discount type', [
                'discount_type' => $discount->getType(),
            ]);
            return $product->getPrice();
        }

        return $strategy->calculate($product->getPrice(), $discount);
    }
}
