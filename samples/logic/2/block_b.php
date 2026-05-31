<?php

declare(strict_types=1);

namespace App\Warehouse;

use App\Entity\Warehouse;
use App\Repository\WarehouseRepository;
use App\Service\CapacityCalculator;
use Psr\Log\LoggerInterface;

final class WarehouseCapacityService
{
    public function __construct(
        private readonly WarehouseRepository $warehouseRepository,
        private readonly CapacityCalculator $capacityCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function allocateSpace(int $warehouseId, int $pallets, string $reason): Warehouse
    {
        $warehouse = $this->warehouseRepository->findById($warehouseId);

        if ($warehouse === null) {
            throw new \RuntimeException('Warehouse not found');
        }

        if ($pallets <= 0) {
            throw new \InvalidArgumentException('Pallet count must be positive');
        }

        if ($pallets > 500) {
            throw new \InvalidArgumentException('Cannot allocate more than 500 pallets at once');
        }

        if (!$this->isValidReason($reason)) {
            throw new \InvalidArgumentException('Invalid allocation reason');
        }

        if ($warehouse->getStatus() === 'closed') {
            throw new \InvalidArgumentException('Cannot allocate space in closed warehouse');
        }

        if ($warehouse->getStatus() === 'maintenance') {
            throw new \InvalidArgumentException('Warehouse is under maintenance');
        }

        $currentUsed = $warehouse->getUsedCapacity();
        $maxCapacity = $warehouse->getMaxCapacity();
        $availableSpace = $maxCapacity - $currentUsed;

        if ($availableSpace < $pallets) {
            throw new \InvalidArgumentException('Insufficient warehouse space available');
        }

        $newUsed = $currentUsed + $pallets;
        $warehouse->setUsedCapacity($newUsed);
        $warehouse->setLastAllocation(new \DateTimeImmutable());

        $this->warehouseRepository->save($warehouse);

        $this->logger->info('Space allocated successfully', [
            'warehouse_id' => $warehouseId,
            'pallets' => $pallets,
            'new_used' => $newUsed,
            'reason' => $reason,
        ]);

        return $warehouse;
    }

    public function releaseSpace(int $warehouseId, int $pallets, string $reason): Warehouse
    {
        $warehouse = $this->warehouseRepository->findById($warehouseId);

        if ($warehouse === null) {
            throw new \RuntimeException('Warehouse not found');
        }

        if ($pallets <= 0) {
            throw new \InvalidArgumentException('Pallet count must be positive');
        }

        if ($pallets > 500) {
            throw new \InvalidArgumentException('Cannot release more than 500 pallets at once');
        }

        if (!$this->isValidReason($reason)) {
            throw new \InvalidArgumentException('Invalid release reason');
        }

        if ($warehouse->getStatus() === 'closed') {
            throw new \InvalidArgumentException('Cannot release space in closed warehouse');
        }

        if ($warehouse->getStatus() === 'maintenance') {
            throw new \InvalidArgumentException('Warehouse is under maintenance');
        }

        $currentUsed = $warehouse->getUsedCapacity();

        if ($currentUsed < $pallets) {
            throw new \InvalidArgumentException('Cannot release more space than currently used');
        }

        $newUsed = $currentUsed - $pallets;
        $warehouse->setUsedCapacity($newUsed);
        $warehouse->setLastAllocation(new \DateTimeImmutable());

        $this->warehouseRepository->save($warehouse);

        $this->logger->info('Space released successfully', [
            'warehouse_id' => $warehouseId,
            'pallets' => $pallets,
            'new_used' => $newUsed,
            'reason' => $reason,
        ]);

        return $warehouse;
    }

    private function isValidReason(string $reason): bool
    {
        $validReasons = [
            'inventory_transfer',
            'receiving',
            'shipping',
            'reorganization',
            'cross_docking',
            'temp_storage',
        ];

        return in_array($reason, $validReasons, true);
    }
}
