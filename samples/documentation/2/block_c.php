<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Domain\Orders\Entity\Order;
use App\Domain\Customer\Entity\Customer;
use App\Domain\Promotions\Entity\Coupon;
use DateTimeImmutable;

/**
 * Discount Eligibility Rules
 *
 * These rules implement the promotional discount policies documented in
 * the marketing campaign brief CAM-2024-Q1 and the partner discount guidelines.
 *
 * DISCOUNT TYPES AND ELIGIBILITY:
 *
 * NEW CUSTOMER DISCOUNT:
 * - 15% off first order, valid for 30 days after account creation
 * - Maximum discount: $50 per order
 * - Cannot be combined with other discounts
 * - Only valid for orders over $35
 * - Applies to regular price items only, not sale items
 *
 * LOYALTY TIER DISCOUNTS:
 * - Bronze: 5% discount on all orders
 * - Silver: 10% discount on all orders
 * - Gold: 15% discount + free standard shipping
 * - Platinum: 20% discount + 50% off express shipping
 * - Tier calculated based on lifetime spend (Bronze: $0, Silver: $500, Gold: $2000, Platinum: $5000)
 *
 * BULK ORDER DISCOUNTS:
 * - 10+ items same product: 8% off that product
 * - 25+ items same product: 15% off that product
 * - Order total > $500: Free standard shipping
 * - Order total > $1000: 5% off entire order
 *
 * SEASONAL/PROMOTIONAL CODES:
 * - Codes valid for single use unless marked multi-use
 * - Minimum order amounts specified per code
 * - Cannot apply to already-discounted items
 * - Stack rules vary by promotion (see individual code restrictions)
 *
 * STACKING RULES (from marketing doc MKT-303):
 * - Customer tier discount applies to all eligible items
 * - One coupon code can be applied per order
 * - Coupon cannot be combined with percentage-based tier discounts
 * - Fixed-amount coupons (e.g., "$10 off") can stack with tier discounts
 * - Free shipping promotions exclude express/overnight methods
 *
 * EXCLUDED CATEGORIES (per finance policy FIN-2023-12):
 * - Gift cards, digital products, tobacco, alcohol, pharmacy
 *
 * See also: README.md Section 3 "Discounts and Promotions"
 */
class DiscountEligibilityService
{
    private const MAX_NEW_CUSTOMER_DISCOUNT = 50.00;
    private const NEW_CUSTOMER_DISCOUNT_PERCENTAGE = 0.15;
    private const MIN_ORDER_FOR_NEW_CUSTOMER_DISCOUNT = 35.00;
    private const NEW_CUSTOMER_VALIDITY_DAYS = 30;

    private const TIER_THRESHOLDS = [
        'bronze' => 0,
        'silver' => 500,
        'gold' => 2000,
        'platinum' => 5000,
    ];

    private const TIER_DISCOUNT_PERCENTAGES = [
        'bronze' => 0.05,
        'silver' => 0.10,
        'gold' => 0.15,
        'platinum' => 0.20,
    ];

    private const BULK_THRESHOLDS = [
        10 => 0.08,
        25 => 0.15,
    ];

    private const ORDER_TOTAL_FREE_SHIPPING_THRESHOLD = 500.00;
    private const ORDER_TOTAL_PERCENTAGE_DISCOUNT_THRESHOLD = 1000.00;
    private const ORDER_TOTAL_PERCENTAGE_DISCOUNT = 0.05;

    private const EXCLUDED_CATEGORY_SLUGS = [
        'gift-cards',
        'digital-products',
        'tobacco',
        'alcohol',
        'pharmacy',
    ];

    /**
     * Determine all applicable discounts for an order.
     *
     * @param Order $order The order to evaluate
     * @param Customer $customer The customer placing the order
     * @param Coupon|null $coupon Optional coupon code
     * @return DiscountBreakdown Complete breakdown of all applicable discounts
     */
    public function calculateDiscounts(
        Order $order,
        Customer $customer,
        ?Coupon $coupon = null
    ): DiscountBreakdown {

        $discounts = [];

        $newCustomerDiscount = $this->evaluateNewCustomerDiscount($order, $customer);
        if ($newCustomerDiscount !== null) {
            $discounts[] = $newCustomerDiscount;
        }

        $tierDiscount = $this->evaluateTierDiscount($order, $customer);
        if ($tierDiscount !== null) {
            $discounts[] = $tierDiscount;
        }

        $bulkDiscounts = $this->evaluateBulkDiscounts($order);
        $discounts = array_merge($discounts, $bulkDiscounts);

        $orderTotalDiscount = $this->evaluateOrderTotalDiscount($order);
        if ($orderTotalDiscount !== null) {
            $discounts[] = $orderTotalDiscount;
        }

        if ($coupon !== null && $coupon->isValid()) {
            $couponDiscount = $this->evaluateCouponDiscount($order, $coupon, $discounts);
            if ($couponDiscount !== null) {
                $discounts[] = $couponDiscount;
            }
        }

        $freeShipping = $this->evaluateFreeShipping($order, $customer, $discounts);

        return new DiscountBreakdown(
            itemDiscounts: $discounts,
            freeShipping: $freeShipping,
        );
    }

    /**
     * New customer discount: 15% off first order within 30 days of signup.
     * Cannot combine with other discounts per marketing brief CAM-2024-Q1 Section 2.3.
     */
    private function evaluateNewCustomerDiscount(Order $order, Customer $customer): ?Discount
    {
        $accountAge = $customer->getCreatedAt()->diff(new DateTimeImmutable());

        if ($accountAge->days > self::NEW_CUSTOMER_VALIDITY_DAYS) {
            return null;
        }

        $orderTotal = $order->getSubtotal();
        if ($orderTotal < self::MIN_ORDER_FOR_NEW_CUSTOMER_DISCOUNT) {
            return null;
        }

        $discountAmount = min(
            $orderTotal * self::NEW_CUSTOMER_DISCOUNT_PERCENTAGE,
            self::MAX_NEW_CUSTOMER_DISCOUNT
        );

        return new Discount(
            type: 'new_customer',
            label: 'First Order Discount (15%)',
            amount: $discountAmount,
            stackable: false,
            appliesTo: 'entire_order',
        );
    }

    /**
     * Loyalty tier discount based on customer's lifetime spend.
     * Tier thresholds documented in the rewards program guide RPG-2023.
     */
    private function evaluateTierDiscount(Order $order, Customer $customer): ?Discount
    {
        $lifetimeSpend = $customer->getLifetimeSpend();
        $tier = $this->determineTier($lifetimeSpend);

        if ($tier === null) {
            return null;
        }

        $eligibleItemsTotal = $this->getEligibleItemsTotal($order);
        $discountAmount = $eligibleItemsTotal * self::TIER_DISCOUNT_PERCENTAGES[$tier];

        return new Discount(
            type: 'loyalty_tier',
            label: ucfirst($tier) . ' Member Discount (' . (self::TIER_DISCOUNT_PERCENTAGES[$tier] * 100) . '%)',
            amount: $discountAmount,
            stackable: true,
            stackableWith: ['fixed_amount_coupon'],
            appliesTo: 'eligible_items',
        );
    }

    /**
     * Bulk discounts apply when purchasing 10+ or 25+ of the same product.
     * These thresholds are documented in the volume pricing guide VPG-2023.
     */
    private function evaluateBulkDiscounts(Order $order): array
    {
        $discounts = [];

        foreach ($order->getItems() as $item) {
            $quantity = $item->getQuantity();

            foreach (self::BULK_THRESHOLDS as $threshold => $percentage) {
                if ($quantity >= $threshold) {
                    $discountAmount = $item->getUnitPrice() * $quantity * $percentage;

                    $discounts[] = new Discount(
                        type: 'bulk',
                        label: "Bulk Discount ({$threshold}+ items) - " . ($percentage * 100) . "% off",
                        amount: $discountAmount,
                        stackable: true,
                        appliesTo: 'specific_item',
                        appliesToItemId: $item->getProductId()->toString(),
                    );
                }
            }
        }

        return $discounts;
    }

    /**
     * Order-total based discounts: free shipping at $500, 5% off at $1000.
     * Documented in the promotional strategy document PSD-2024.
     */
    private function evaluateOrderTotalDiscount(Order $order): ?Discount
    {
        $orderTotal = $order->getSubtotal();

        if ($orderTotal >= self::ORDER_TOTAL_PERCENTAGE_DISCOUNT_THRESHOLD) {
            return new Discount(
                type: 'order_total',
                label: 'Large Order Discount (5%)',
                amount: $orderTotal * self::ORDER_TOTAL_PERCENTAGE_DISCOUNT,
                stackable: true,
                appliesTo: 'entire_order',
            );
        }

        return null;
    }
}
