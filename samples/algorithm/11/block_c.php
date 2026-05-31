<?php
declare(strict_types=1);

namespace MarketingEngine\Offers;

use Psr\Log\LoggerInterface;

final class PromotionalOfferCalculator
{
    private const SPRING_SEASON_START = '03-01';
    private const SPRING_SEASON_END = '05-31';
    private const SUMMER_SEASON_START = '06-01';
    private const SUMMER_SEASON_END = '08-31';
    private const FALL_SEASON_START = '09-01';
    private const FALL_SEASON_END = '11-30';
    private const WINTER_SEASON_START = '12-01';
    private const WINTER_SEASON_END = '02-28';

    private const SPRING_CASHBACK_PERCENTAGE = 0.05;
    private const SUMMER_CASHBACK_PERCENTAGE = 0.03;
    private const FALL_CASHBACK_PERCENTAGE = 0.05;
    private const WINTER_CASHBACK_PERCENTAGE = 0.08;
    private const BLACK_FRIDAY_CASHBACK_PERCENTAGE = 0.15;
    private const CYBER_MONDAY_CASHBACK_PERCENTAGE = 0.12;

    private const MINIMUM_PURCHASE_FOR_CASHBACK = 50.00;
    private const MAXIMUM_CASHBACK_AMOUNT = 200.00;
    private const NEW_CUSTOMER_BONUS = 25.00;
    private const VIP_CUSTOMER_BONUS = 50.00;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateCashback(array $orderItems, \DateTimeInterface $orderDate, array $customerProfile = []): CashbackResult
    {
        $this->logger->debug('Calculating promotional cashback', [
            'item_count' => count($orderItems),
            'customer_tier' => $customerProfile['tier'] ?? 'standard',
        ]);

        $subtotal = $this->calculateSubtotal($orderItems);
        if ($subtotal < self::MINIMUM_PURCHASE_FOR_CASHBACK) {
            $this->logger->info('Purchase below minimum for cashback', ['subtotal' => $subtotal]);
            return new CashbackResult(0.0, 0.0, $subtotal, 'below_minimum');
        }

        $baseCashback = $this->calculateBaseCashback($subtotal, $orderDate);
        $isBlackFriday = $this->isBlackFriday($orderDate);
        $isCyberMonday = $this->isCyberMonday($orderDate);

        if ($isBlackFriday) {
            $baseCashback = $subtotal * self::BLACK_FRIDAY_CASHBACK_PERCENTAGE;
        } elseif ($isCyberMonday) {
            $baseCashback = $subtotal * self::CYBER_MONDAY_CASHBACK_PERCENTAGE;
        }

        $bonusCashback = $this->calculateBonusCashback($customerProfile);
        $totalCashback = $baseCashback + $bonusCashback;

        if ($totalCashback > self::MAXIMUM_CASHBACK_AMOUNT) {
            $totalCashback = self::MAXIMUM_CASHBACK_AMOUNT;
        }

        $this->logger->info('Cashback calculated', [
            'base_cashback' => $baseCashback,
            'bonus_cashback' => $bonusCashback,
            'total_cashback' => $totalCashback,
        ]);

        return new CashbackResult(
            cashbackAmount: $totalCashback,
            cashbackPercentage: ($baseCashback / $subtotal) * 100,
            purchaseAmount: $subtotal,
            appliedOffers: $this->getAppliedOffers($isBlackFriday, $isCyberMonday, $bonusCashback > 0),
        );
    }

    private function calculateBaseCashback(float $subtotal, \DateTimeInterface $date): float
    {
        $percentage = $this->getSeasonalCashbackPercentage($date);
        return $subtotal * $percentage;
    }

    private function getSeasonalCashbackPercentage(\DateTimeInterface $date): float
    {
        $monthDay = $date->format('m-d');

        if ($monthDay >= self::SPRING_SEASON_START && $monthDay <= self::SPRING_SEASON_END) {
            return self::SPRING_CASHBACK_PERCENTAGE;
        }

        if ($monthDay >= self::SUMMER_SEASON_START && $monthDay <= self::SUMMER_SEASON_END) {
            return self::SUMMER_CASHBACK_PERCENTAGE;
        }

        if ($monthDay >= self::FALL_SEASON_START && $monthDay <= self::FALL_SEASON_END) {
            return self::FALL_CASHBACK_PERCENTAGE;
        }

        if ($monthDay >= self::WINTER_SEASON_START || $monthDay <= self::WINTER_SEASON_END) {
            return self::WINTER_CASHBACK_PERCENTAGE;
        }

        return 0.0;
    }

    private function calculateBonusCashback(array $customerProfile): float
    {
        $bonusCashback = 0.0;

        $isNewCustomer = $customerProfile['is_new'] ?? false;
        $customerTier = $customerProfile['tier'] ?? 'standard';

        if ($isNewCustomer) {
            $bonusCashback += self::NEW_CUSTOMER_BONUS;
        }

        if ($customerTier === 'vip') {
            $bonusCashback += self::VIP_CUSTOMER_BONUS;
        }

        return $bonusCashback;
    }

    private function calculateSubtotal(array $orderItems): float
    {
        $subtotal = 0.0;
        foreach ($orderItems as $item) {
            $price = $item['unit_price'] ?? 0.0;
            $quantity = $item['quantity'] ?? 1;
            $subtotal += $price * $quantity;
        }
        return $subtotal;
    }

    private function isBlackFriday(\DateTimeInterface $date): bool
    {
        $monthDay = $date->format('m-d');
        return $monthDay === '11-25' || $monthDay === '11-26';
    }

    private function isCyberMonday(\DateTimeInterface $date): bool
    {
        return $date->format('m-d') === '11-28';
    }

    private function getAppliedOffers(bool $isBlackFriday, bool $isCyberMonday, bool $hasBonus): array
    {
        $offers = [];
        if ($isBlackFriday) {
            $offers[] = 'black_friday_cashback';
        } elseif ($isCyberMonday) {
            $offers[] = 'cyber_monday_cashback';
        } else {
            $offers[] = 'seasonal_cashback';
        }

        if ($hasBonus) {
            $offers[] = 'customer_bonus';
        }

        return $offers;
    }
}
