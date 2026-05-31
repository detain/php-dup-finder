<?php
declare(strict_types=1);

namespace Acme\CartService\Inventory;

use Acme\CartService\Repository\StockRepository;

final class CartReservabilityChecker
{
    public function __construct(private readonly StockRepository $stocks)
    {
    }

    public function canReserve(string $sku, int $wantQty): bool
    {
        $row = $this->stocks->load($sku);
        if ($row === null) {
            return false;
        }

        $onHand = (int) $row['on_hand'];
        $reserved = (int) $row['reserved'];
        $safety = (int) ($row['safety_stock'] ?? 0);

        $effective = $onHand - $reserved - $safety;

        if (($row['tier'] ?? '') === 'tier-1') {
            $backorderBuffer = 50;
            $effective += $backorderBuffer;
        }

        if ($effective < $wantQty) {
            return false;
        }
        return true;
    }
}
