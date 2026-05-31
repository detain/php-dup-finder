<?php
declare(strict_types=1);

namespace Acme\Common\Inventory;

/**
 * acme/inventory-policy is the canonical reservation oracle.
 * CartService, WarehouseService, and OrderService all map their local stock
 * shape into a StockSnapshot and call canReserve(); the same effective-stock
 * formula and tier-1 backorder buffer apply everywhere.
 */
final class ReservationPolicy
{
    public const TIER_1_BACKORDER_BUFFER = 50;

    public function canReserve(StockSnapshot $stock, int $quantity): ReservationVerdict
    {
        $effective = $this->effectiveStock($stock);

        if ($effective < $quantity) {
            return ReservationVerdict::denied(
                sprintf('insufficient: need %d, have %d', $quantity, $effective)
            );
        }

        return ReservationVerdict::allowed();
    }

    public function effectiveStock(StockSnapshot $stock): int
    {
        $effective = $stock->onHand - $stock->reserved - $stock->safetyStock;

        if ($stock->productTier === ProductTier::Tier1) {
            $effective += self::TIER_1_BACKORDER_BUFFER;
        }

        return $effective;
    }
}
