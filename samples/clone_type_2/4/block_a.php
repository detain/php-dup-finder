<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Entity\Warehouse;
use App\Repository\WarehouseRepository;
use App\Service\StockCalculator;
use Psr\Log\LoggerInterface;

final class WarehouseStockService
{
    public function __construct(
        private readonly WarehouseRepository $warehouseRepository,
        private readonly StockCalculator $stockCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function getAvailableStock(int $warehouseId, int $productId): int
    {
        $warehouse = $this->warehouseRepository->findById($warehouseId);

        if ($warehouse === null) {
            throw new \RuntimeException("Warehouse {$warehouseId} not found");
        }

        $totalStock = $warehouse->getTotalStock($productId);
        $reservedStock = $warehouse->getReservedStock($productId);

        return $totalStock - $reservedStock;
    }

    public function allocateStock(int $warehouseId, int $productId, int $quantity): bool
    {
        $warehouse = $this->warehouseRepository->findById($warehouseId);

        if ($warehouse === null) {
            throw new \RuntimeException("Warehouse {$warehouseId} not found");
        }

        $available = $this->getAvailableStock($warehouseId, $productId);

        if ($available < $quantity) {
            $this->logger->warning('Insufficient stock for allocation', [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'requested' => $quantity,
                'available' => $available,
            ]);
            return false;
        }

        $warehouse->reserveStock($productId, $quantity);
        $this->warehouseRepository->save($warehouse);

        $this->logger->info('Stock allocated', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function releaseStock(int $warehouseId, int $productId, int $quantity): bool
    {
        $warehouse = $this->warehouseRepository->findById($warehouseId);

        if ($warehouse === null) {
            throw new \RuntimeException("Warehouse {$warehouseId} not found");
        }

        $warehouse->releaseReservedStock($productId, $quantity);
        $this->warehouseRepository->save($warehouse);

        $this->logger->info('Stock released', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function transferStock(int $fromWarehouseId, int $toWarehouseId, int $productId, int $quantity): bool
    {
        $fromWarehouse = $this->warehouseRepository->findById($fromWarehouseId);
        $toWarehouse = $this->warehouseRepository->findById($toWarehouseId);

        if ($fromWarehouse === null || $toWarehouse === null) {
            throw new \RuntimeException('Warehouse not found');
        }

        $available = $this->getAvailableStock($fromWarehouseId, $productId);

        if ($available < $quantity) {
            return false;
        }

        $fromWarehouse->releaseReservedStock($productId, $quantity);
        $toWarehouse->addStock($productId, $quantity);

        $this->warehouseRepository->save($fromWarehouse);
        $this->warehouseRepository->save($toWarehouse);

        $this->logger->info('Stock transferred', [
            'from_warehouse' => $fromWarehouseId,
            'to_warehouse' => $toWarehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }
}
