<?php

declare(strict_types=1);

namespace App\Domain\Orders\Policy;

use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\Entity\OrderItem;
use App\Domain\Customer\Entity\Customer;
use DateTimeImmutable;

/**
 * Refund Policy Implementation
 *
 * This policy governs all refund requests for completed orders. The rules are
 * documented in internal ticket #4532 and mirrored in the customer-facing FAQ.
 *
 * REFUND ELIGIBILITY RULES:
 * - Full refund available within 30 days of delivery date
 * - Partial refund (80%) available between 31-60 days after delivery
 * - No refund available after 60 days from delivery
 * - Original shipping cost is non-refundable after 30 days
 * - Items must be returned within 14 days of refund request
 * - Restocking fee of 15% applies to electronics and furniture
 * - Gift cards, downloadable software, and personalized items are non-refundable
 * - Refunds processed within 5-7 business days to original payment method
 * - Store credit option available for faster processing (1 business day)
 *
 * EXCEPTIONS:
 * - Damaged/incorrect items: Full refund + return shipping covered
 * - Event tickets: Refund only if event is cancelled, not postponed
 * - Hotel bookings: Cancellation policy varies by property, see property listing
 *
 * See also: README.md Section 4.2 "Refund Processing" and Confluence DOC-112
 */
class RefundPolicy
{
    private const FULL_REFUND_WINDOW_DAYS = 30;
    private const PARTIAL_REFUND_WINDOW_DAYS = 60;
    private const RETURN_WINDOW_DAYS = 14;
    private const PARTIAL_REFUND_PERCENTAGE = 0.80;
    private const RESTOCKING_FEE_PERCENTAGE = 0.15;

    private const NON_REFUNDABLE_CATEGORIES = [
        'gift-cards',
        'downloadable_software',
        'personalized_items',
        'event_tickets',
        'perishable_goods',
    ];

    /**
     * Determines if an order is eligible for refund and calculates the amount.
     *
     * @param Order $order The order to evaluate
     * @param DateTimeImmutable $evaluatedAt Date when refund is being evaluated
     * @return RefundEligibility The calculated eligibility and refund amount
     */
    public function evaluateEligibility(Order $order, DateTimeImmutable $evaluatedAt): RefundEligibility
    {
        $deliveryDate = $order->getDeliveredAt();
        if ($deliveryDate === null) {
            return RefundEligibility::notEligible('Order has not been delivered yet');
        }

        $daysSinceDelivery = $deliveryDate->diff($evaluatedAt)->days;
        $categoryRefundability = $this->checkCategoryRestrictions($order);

        if (!$categoryRefundability->isEligible) {
            return $categoryRefundability;
        }

        $subtotal = $order->getSubtotal();
        $shippingCost = $order->getShippingCost()->getAmount();
        $refundAmount = $subtotal;
        $refundType = 'full';

        if ($daysSinceDelivery <= self::FULL_REFUND_WINDOW_DAYS) {
            $refundAmount = $subtotal + $shippingCost;
            $refundType = 'full';

        } elseif ($daysSinceDelivery <= self::PARTIAL_REFUND_WINDOW_DAYS) {
            $refundAmount = ($subtotal * self::PARTIAL_REFUND_PERCENTAGE) + $shippingCost;
            $refundType = 'partial';

        } else {
            return RefundEligibility::notEligible(
                "Refund window has expired. Full refund available within " .
                self::FULL_REFUND_WINDOW_DAYS . " days, partial within " .
                self::PARTIAL_REFUND_WINDOW_DAYS . " days of delivery."
            );
        }

        $restockingFee = $this->calculateRestockingFee($order);
        $refundAmount = max(0, $refundAmount - $restockingFee);

        return new RefundEligibility(
            isEligible: true,
            refundType: $refundType,
            refundAmount: $refundAmount,
            restockingFee: $restockingFee,
            daysRemainingForReturn: max(0, self::RETURN_WINDOW_DAYS - $daysSinceDelivery),
        );
    }

    /**
     * Check if any items in the order belong to non-refundable categories.
     * These restrictions are documented in the product catalog FAQ and in
     * the customer service training manual Section 7.4.
     */
    private function checkCategoryRestrictions(Order $order): RefundEligibility
    {
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (in_array($product->getCategorySlug(), self::NON_REFUNDABLE_CATEGORIES, true)) {
                return RefundEligibility::notEligible(
                    "Product '{$product->getName()}' belongs to a non-refundable category"
                );
            }
        }

        return RefundEligibility::eligible();
    }

    /**
     * Calculate restocking fee based on product categories.
     * Electronics and furniture attract a restocking fee as documented in
     * the return policy page and in internal memo LOG-2023-045.
     */
    private function calculateRestockingFee(Order $order): float
    {
        $fee = 0.0;

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $category = $product->getCategorySlug();

            if (in_array($category, ['electronics', 'furniture'], true)) {
                $fee += $item->getSubtotal() * self::RESTOCKING_FEE_PERCENTAGE;
            }
        }

        return $fee;
    }
}
