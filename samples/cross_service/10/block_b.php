<?php
declare(strict_types=1);

namespace Acme\WarehouseService\Inventory;

use Acme\WarehouseService\Source\WmsStockReader;

final class WarehouseAllocationGuard
{
    public function __construct(private readonly WmsStockReader $wms)
    {
    }

    public function authorizeAllocation(string $skuCode, int $requested): array
    {
        $stock = $this->wms->byCode($skuCode);
        if (empty($stock)) {
            return ['allowed' => false, 'reason' => 'no_record'];
        }

        $onHand = (int) $stock['on_hand_qty'];
        $reserved = (int) $stock['reserved_qty'];
        $safety = (int) ($stock['safety_stock'] ?? 0);

        $available = $onHand - $reserved - $safety;

        $tier = (string) ($stock['product_tier'] ?? '');
        if ($tier === 'tier-1') {
            $available += 50;
        }

        if ($available < $requested) {
            return ['allowed' => false, 'reason' => 'insufficient'];
        }
        return ['allowed' => true, 'reason' => null];
    }
}
