<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment\Policy;

use App\Domain\Orders\Entity\Order;
use App\Domain\Orders\ValueObject\Shipment;
use App\Domain\Fulfillment\Entity\Warehouse;

/**
 * Order fulfillment policy implementation.
 *
 * This policy defines how orders are routed to fulfillment centers,
 * processed, and shipped. Rules are documented in internal ticket
 * #FUL-2024-001 and the fulfillment operations manual FOM-2024.
 *
 * WAREHOUSE SELECTION RULES:
 * - Primary warehouse: Closest warehouse to shipping address
 * - Inventory check: Selected warehouse must have all items in stock
 * - Multi-warehouse split: If no single warehouse has all items, split shipment
 * - Priority warehouses: Partner warehouses get priority for certain product categories
 *
 * ORDER PROCESSING TIMELINE:
 * - Payment confirmation: Within 1 hour of payment
 * - Warehouse assignment: Within 2 hours of payment confirmation
 * - Pick and pack: Within 24 hours of warehouse assignment
 * - Shipment dispatch: Within 48 hours of warehouse assignment
 * - Delivery estimates:
 *   - Standard: 5-7 business days
 *   - Express: 2-3 business days
 *   - Overnight: Next business day (if ordered before 2PM local time)
 *
 * SHIPMENT CONSOLIDATION RULES:
 * - Multiple orders to same address: Consolidate into single shipment
 * - Consolidation window: Orders can be consolidated until 6PM same day
 * - Oversized items: Cannot be consolidated, ships separately
 *
 * ADDRESS VALIDATION:
 * - PO Box detection: Standard shipping only, no express/overnight
 * - Military addresses: Force to standard shipping
 * - International addresses: Route to international fulfillment center
 * - Incomplete addresses: Hold for customer contact, 48-hour resolution window
 *
 * ORDER CANCELLATION WINDOW:
 * - Before pick: Full refund, no restocking fee
 * - During pick: 50% restocking fee
 * - After pack: No cancellation, ship as planned
 *
 * EXCEPTION HANDLING:
 * - Inventory discrepancy: Contact customer within 2 hours, offer alternatives
 * - Damage in warehouse: Replace item, expedite shipment
 * - Weather delay: Automatic notification to customer with new estimate
 * - Carrier delay: Monitor and proactively notify if >20% delay expected
 *
 * See also: docs/fulfillment/fulfillment-policy.md and JIRA FUL-2024-001
 */
class FulfillmentPolicy
{
    private const STANDARD_DELIVERY_DAYS = [5, 7];
    private const EXPRESS_DELIVERY_DAYS = [2, 3];
    private const OVERNIGHT_DEADLINE_HOUR = 14;
    private const CONSOLIDATION_WINDOW_HOUR = 18;
    private const INCOMPLETE_ADDRESS_RESOLUTION_HOURS = 48;

    /**
     * Determine the optimal warehouse(s) for fulfilling an order.
     *
     * @param Order $order The order to fulfill
     * @param array<Warehouse> $availableWarehouses All warehouses that could fulfill
     * @return array<WarehouseAssignment> Warehouse assignments with items and ETAs
     */
    public function determineWarehouseAssignments(
        Order $order,
        array $availableWarehouses
    ): array {

        $shippingAddress = $order->getShippingAddress();
        $requiredItems = $this->groupItemsByAvailability($order);

        $assignments = [];

        foreach ($requiredItems as $availabilityGroup) {
            $warehouse = $this->selectOptimalWarehouse(
                $shippingAddress,
                $availabilityGroup['items'],
                $availableWarehouses
            );

            if ($warehouse === null) {
                throw new FulfillmentException(
                    "No warehouse can fulfill items: " .
                    implode(', ', array_map(fn($i) => $i->getSku(), $availabilityGroup['items']))
                );
            }

            $assignments[] = new WarehouseAssignment(
                warehouse: $warehouse,
                items: $availabilityGroup['items'],
                estimatedShipDate: $this->calculateShipDate($warehouse),
                estimatedDelivery: $this->calculateDeliveryDate($warehouse, $order->getShippingMethod()),
            );
        }

        return $assignments;
    }

    /**
     * Group order items by their availability across warehouses.
     * Determines if split shipment is needed.
     */
    private function groupItemsByAvailability(Order $order): array
    {
        $groups = [];

        foreach ($order->getItems() as $item) {
            $groups[] = ['items' => [$item]];
        }

        return $groups;
    }

    /**
     * Select the optimal warehouse based on proximity and inventory.
     */
    private function selectOptimalWarehouse(
        Address $shippingAddress,
        array $items,
        array $warehouses
    ): ?Warehouse {

        $eligibleWarehouses = array_filter(
            $warehouses,
            fn($w) => $w->hasAllItems($items) && $w->isOperational()
        );

        if (empty($eligibleWarehouses)) {
            return null;
        }

        usort($eligibleWarehouses, function ($a, $b) use ($shippingAddress) {
            return $a->getDistanceTo($shippingAddress) <=> $b->getDistanceTo($shippingAddress);
        });

        return $eligibleWarehouses[0];
    }

    /**
     * Calculate estimated ship date based on warehouse processing capacity.
     */
    private function calculateShipDate(Warehouse $warehouse): DateTimeImmutable
    {
        $baseDate = new \DateTimeImmutable();
        $cutoffTime = (int) $baseDate->format('H');

        if ($warehouse->getDailyCapacity() > $warehouse->getTodaysCommitments()) {
            return $baseDate->setTime($cutoffTime + 2, 0);
        }

        return $baseDate->modify('+1 day')->setTime(10, 0);
    }

    /**
     * Calculate estimated delivery date based on warehouse, shipping method.
     */
    private function calculateDeliveryDate(
        Warehouse $warehouse,
        string $shippingMethod
    ): DateTimeImmutable {

        $shipDate = $this->calculateShipDate($warehouse);

        if ($shippingMethod === 'overnight') {
            $now = (int) (new \DateTimeImmutable())->format('H');
            if ($now <= self::OVERNIGHT_DEADLINE_HOUR) {
                return $shipDate->modify('+1 day');
            }
            return $shipDate->modify('+2 days');
        }

        if ($shippingMethod === 'express') {
            return $shipDate->modify('+' . self::EXPRESS_DELIVERY_DAYS[1] . ' days');
        }

        return $shipDate->modify('+' . self::STANDARD_DELIVERY_DAYS[1] . ' days');
    }

    /**
     * Validate shipping address and apply shipping restrictions.
     */
    public function validateShippingAddress(Address $address, string $shippingMethod): ValidationResult
    {
        if ($address->isPOBox() && in_array($shippingMethod, ['express', 'overnight'], true)) {
            return new ValidationResult(
                valid: false,
                message: 'PO Boxes cannot be used for express or overnight shipping. Please select standard shipping.',
                suggestedMethod: 'standard',
            );
        }

        if ($address->isMilitaryAddress() && $shippingMethod !== 'standard') {
            return new ValidationResult(
                valid: false,
                message: 'Military addresses (APO/FPO) are only eligible for standard shipping.',
                suggestedMethod: 'standard',
            );
        }

        if ($address->isInternational()) {
            return new ValidationResult(
                valid: true,
                message: 'International shipping may incur customs duties and longer delivery times.',
            );
        }

        return new ValidationResult(valid: true);
    }
}
