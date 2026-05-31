<?php

declare(strict_types=1);

namespace Acme\Warehouse\Allocation;

use Acme\Warehouse\Allocation\Dto\InventoryLot;
use Acme\Warehouse\Allocation\Dto\AllocationLine;

final class FifoInventoryAllocator
{
    /**
     * @param InventoryLot[] $lots
     * @return AllocationLine[]
     */
    public function allocate(array $lots, int $demandUnits, string $orderRef): array
    {
        usort(
            $lots,
            static fn(InventoryLot $a, InventoryLot $b): int => $a->receivedAt <=> $b->receivedAt,
        );

        $allocations = [];
        $remaining = $demandUnits;

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            if ($lot->availableUnits <= 0) {
                continue;
            }

            $take = min($remaining, $lot->availableUnits);
            $remaining -= $take;

            $allocations[] = new AllocationLine(
                lotId: $lot->id,
                allocated: $take,
                reference: $orderRef,
            );
        }

        if ($remaining > 0) {
            throw new \RuntimeException(
                "Insufficient stock: short by {$remaining} units for order {$orderRef}",
            );
        }

        return $allocations;
    }
}
