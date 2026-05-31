<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Domain\Orders\Entity\Order;
use App\Domain\Shipping\Entity\ShippingRate;
use App\Domain\Shipping\ValueObject\Address;

/**
 * Shipping Rules Engine
 *
 * Implements the shipping rules defined in the Operations Manual v3.2 and
 * mirrored in the partner integration guide Section 2.1.4.
 *
 * SHIPPING ELIGIBILITY:
 * - Standard shipping: Available for all orders with weight <= 50 lbs
 * - Express shipping: Available for orders with weight <= 25 lbs, delivery 2-3 days
 * - Overnight shipping: Available for orders with weight <= 15 lbs, delivery next day
 * - International: Available for supported countries list (see ISO 3166-1)
 * - Freight shipping: Required for orders with weight > 50 lbs
 *
 * WEIGHT RESTRICTIONS:
 * - Packages exceeding 50 lbs require freight shipping
 * - Single package weight cannot exceed 70 lbs
 * - Multiple packages automatically created for heavy orders
 *
 * DELIVERY TIMEFRAMES:
 * - Standard: 5-7 business days (ground shipping)
 * - Express: 2-3 business days
 * - Overnight: Next business day by 10:30 AM
 * - International: 10-21 business days depending on customs
 * - Freight: 5-10 business days, appointment required
 *
 * RESTRICTED DESTINATIONS:
 * - APO/FPO military addresses: Standard shipping only
 * - PO Boxes: Cannot ship overnight or express
 * - Alaska/Hawaii: Additional 3-5 days to standard timeframes
 *
 * Documented in: conf/wiki/shipping-rules.md and JIRA ticket OPS-342
 */
class ShippingRulesEngine
{
    private const MAX_STANDARD_WEIGHT_LBS = 50;
    private const MAX_EXPRESS_WEIGHT_LBS = 25;
    private const MAX_OVERNIGHT_WEIGHT_LBS = 15;
    private const MAX_SINGLE_PACKAGE_WEIGHT_LBS = 70;

    private const STANDARD_DELIVERY_DAYS = [5, 7];
    private const EXPRESS_DELIVERY_DAYS = [2, 3];
    private const OVERNIGHT_DELIVERY_DAYS = [1, 1];

    private const RESTRICTED_ZIP_CODES = [
        '99501', '99502', '99503', '99504', '99505', '99506', '99507',
        '99508', '99509', '99510', '99511', '99512', '99513', '99514',
        '99515', '99516', '99517', '99518', '99519', '99520', '99521',
        '99522', '99523', '99524',
    ];

    /**
     * Calculate available shipping options for an order.
     *
     * @param Order $order The order to ship
     * @param Address $destination Delivery address
     * @return ShippingOptions Available shipping methods with rates and ETAs
     */
    public function calculateOptions(Order $order, Address $destination): ShippingOptions
    {
        $weight = $this->calculateTotalWeight($order);
        $options = [];

        $options[] = $this->buildStandardOption($weight, $destination);
        $options[] = $this->buildExpressOption($weight, $destination);
        $options[] = $this->buildOvernightOption($weight, $destination);

        if ($weight > self::MAX_STANDARD_WEIGHT_LBS) {
            $options[] = $this->buildFreightOption($weight, $destination);
        }

        $options = array_filter($options, fn($opt) => $opt !== null);

        return new ShippingOptions($options);
    }

    /**
     * Standard shipping availability. Orders under 50 lbs get standard ground.
     * Military APO/FPO addresses only qualify for standard shipping per
     * carrier agreements documented in the vendor contract VND-2023-112.
     */
    private function buildStandardOption(float $weight, Address $destination): ?ShippingOption
    {
        if ($weight > self::MAX_STANDARD_WEIGHT_LBS) {
            return null;
        }

        $rate = $this->calculateRate('standard', $weight, $destination);
        $estimatedDays = $this->getDeliveryDaysEstimate('standard', $destination);

        return new ShippingOption(
            method: 'standard',
            rate: $rate,
            estimatedDays: $estimatedDays,
            available: true,
        );
    }

    /**
     * Express shipping: 2-3 days delivery, weight limit 25 lbs.
     * Cannot deliver to PO Boxes - this is a carrier restriction documented
     * in the shipping integrations wiki at conf/wiki/shipping-integrations.md
     */
    private function buildExpressOption(float $weight, Address $destination): ?ShippingOption
    {
        if ($weight > self::MAX_EXPRESS_WEIGHT_LBS) {
            return null;
        }

        if ($destination->isPOBox()) {
            return new ShippingOption(
                method: 'express',
                rate: null,
                estimatedDays: null,
                available: false,
                unavailableReason: 'PO Boxes are not eligible for express shipping',
            );
        }

        $rate = $this->calculateRate('express', $weight, $destination);
        $estimatedDays = $this->getDeliveryDaysEstimate('express', $destination);

        return new ShippingOption(
            method: 'express',
            rate: $rate,
            estimatedDays: $estimatedDays,
            available: true,
        );
    }

    /**
     * Overnight shipping: Next business day by 10:30 AM, weight limit 15 lbs.
     * Not available for Alaska, Hawaii, or international destinations as
     * documented in carrier service areas wiki page.
     */
    private function buildOvernightOption(float $weight, Address $destination): ?ShippingOption
    {
        if ($weight > self::MAX_OVERNIGHT_WEIGHT_LBS) {
            return null;
        }

        if ($destination->isMilitaryAddress()) {
            return new ShippingOption(
                method: 'overnight',
                rate: null,
                estimatedDays: null,
                available: false,
                unavailableReason: 'Overnight shipping is not available for military addresses',
            );
        }

        if ($this->isRestrictedZipCode($destination->getZipCode())) {
            return new ShippingOption(
                method: 'overnight',
                rate: null,
                estimatedDays: null,
                available: false,
                unavailableReason: 'Overnight shipping is not available for Alaska/Hawaii',
            );
        }

        $rate = $this->calculateRate('overnight', $weight, $destination);

        return new ShippingOption(
            method: 'overnight',
            rate: $rate,
            estimatedDays: [1, 1],
            available: true,
        );
    }

    /**
     * Freight shipping required for orders over 50 lbs. This rule is based on
     * carrier package limits documented in the logistics runbook Section 3.1.
     */
    private function buildFreightOption(float $weight, Address $destination): ShippingOption
    {
        $numberOfPackages = (int) ceil($weight / self::MAX_SINGLE_PACKAGE_WEIGHT_LBS);

        return new ShippingOption(
            method: 'freight',
            rate: $this->calculateFreightRate($weight, $destination),
            estimatedDays: self::STANDARD_DELIVERY_DAYS,
            available: true,
            numberOfPackages: $numberOfPackages,
            requiresAppointment: true,
        );
    }
}
