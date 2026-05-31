<?php
declare(strict_types=1);

namespace App\Ecommerce\Service;

use App\Ecommerce\Repository\DiscountRepository;
use App\Ecommerce\Entity\Discount;
use App\Ecommerce\Entity\Cart;
use Psr\Log\LoggerInterface;

final class DiscountCalculationService
{
    public const STANDARD_DISCOUNT_PERCENT = 10.0;
    public const PREMIUM_DISCOUNT_PERCENT = 15.0;
    public const VIP_DISCOUNT_PERCENT = 20.0;

    public const QUANTITY_THRESHOLD_FOR_BULK = 10;
    public const BULK_DISCOUNT_PERCENT = 5.0;

    public const MIN_ORDER_FOR_DISCOUNT = 50.00;
    public const MIN_ORDER_FOR_FREE_SHIPPING = 100.00;

    public const REFERRAL_DISCOUNT_AMOUNT = 10.00;
    public const FIRST_ORDER_DISCOUNT_PERCENT = 15.0;

    private DiscountRepository $discountRepo;
    private LoggerInterface $logger;

    public function __construct(
        DiscountRepository $discountRepo,
        LoggerInterface $logger
    ) {
        $this->discountRepo = $discountRepo;
        $this->logger = $logger;
    }

    public function calculateCartDiscounts(Cart $cart, array $context = []): DiscountSummary
    {
        $subtotal = $cart->getSubtotal();
        $discounts = [];
        $totalDiscount = 0.0;

        $tierDiscount = $this->calculateTierDiscount($cart, $context);
        if ($tierDiscount > 0) {
            $discounts[] = ['type' => 'tier', 'amount' => $tierDiscount];
            $totalDiscount += $tierDiscount;
        }

        $bulkDiscount = $this->calculateBulkDiscount($cart);
        if ($bulkDiscount > 0) {
            $discounts[] = ['type' => 'bulk', 'amount' => $bulkDiscount];
            $totalDiscount += $bulkDiscount;
        }

        $loyaltyDiscount = $this->calculateLoyaltyDiscount($cart, $context);
        if ($loyaltyDiscount > 0) {
            $discounts[] = ['type' => 'loyalty', 'amount' => $loyaltyDiscount];
            $totalDiscount += $loyaltyDiscount;
        }

        $referralDiscount = $this->calculateReferralDiscount($cart, $context);
        if ($referralDiscount > 0) {
            $discounts[] = ['type' => 'referral', 'amount' => $referralDiscount];
            $totalDiscount += $referralDiscount;
        }

        $firstOrderDiscount = $this->calculateFirstOrderDiscount($cart, $context);
        if ($firstOrderDiscount > 0) {
            $discounts[] = ['type' => 'first_order', 'amount' => $firstOrderDiscount];
            $totalDiscount += $firstOrderDiscount;
        }

        $finalTotal = max(0, $subtotal - $totalDiscount);

        $this->logger->info('Discounts calculated', [
            'cart_id' => $cart->getId(),
            'subtotal' => $subtotal,
            'total_discount' => $totalDiscount,
            'final_total' => $finalTotal,
            'discount_count' => count($discounts)
        ]);

        return new DiscountSummary([
            'subtotal' => $subtotal,
            'discounts' => $discounts,
            'total_discount' => $totalDiscount,
            'final_total' => $finalTotal
        ]);
    }

    public function calculateTierDiscount(Cart $cart, array $context): float
    {
        $customerTier = $context['customer_tier'] ?? 'standard';

        if ($cart->getSubtotal() < self::MIN_ORDER_FOR_DISCOUNT) {
            return 0.0;
        }

        $discountPercent = match ($customerTier) {
            'premium' => self::PREMIUM_DISCOUNT_PERCENT,
            'vip' => self::VIP_DISCOUNT_PERCENT,
            default => self::STANDARD_DISCOUNT_PERCENT,
        };

        return round($cart->getSubtotal() * ($discountPercent / 100), 2);
    }

    public function calculateBulkDiscount(Cart $cart): float
    {
        $totalQuantity = $cart->getTotalQuantity();

        if ($totalQuantity < self::QUANTITY_THRESHOLD_FOR_BULK) {
            return 0.0;
        }

        return round($cart->getSubtotal() * (self::BULK_DISCOUNT_PERCENT / 100), 2);
    }

    public function calculateLoyaltyDiscount(Cart $cart, array $context): float
    {
        $loyaltyPoints = $context['loyalty_points'] ?? 0;

        if ($loyaltyPoints < 100) {
            return 0.0;
        }

        $maxRedeemablePoints = min($loyaltyPoints, 1000);
        return round($maxRedeemablePoints / 100 * 1.00, 2);
    }

    public function calculateReferralDiscount(Cart $cart, array $context): float
    {
        $hasReferralCode = $context['referral_code'] ?? null;

        if ($hasReferralCode === null) {
            return 0.0;
        }

        return self::REFERRAL_DISCOUNT_AMOUNT;
    }

    public function calculateFirstOrderDiscount(Cart $cart, array $context): float
    {
        $orderCount = $context['previous_order_count'] ?? 0;

        if ($orderCount !== 0) {
            return 0.0;
        }

        if ($cart->getSubtotal() < 30.00) {
            return 0.0;
        }

        return round($cart->getSubtotal() * (self::FIRST_ORDER_DISCOUNT_PERCENT / 100), 2);
    }

    public function getFreeShippingEligible(Cart $cart): bool
    {
        return $cart->getSubtotal() >= self::MIN_ORDER_FOR_FREE_SHIPPING;
    }
}
