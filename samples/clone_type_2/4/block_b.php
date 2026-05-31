<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Entity\Store;
use App\Repository\StoreRepository;
use App\Service\StockCalculator;
use Psr\Log\LoggerInterface;

final class StoreStockService
{
    public function __construct(
        private readonly StoreRepository $storeRepository,
        private readonly StockCalculator $stockCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function getAvailableStock(int $storeId, int $productId): int
    {
        $store = $this->storeRepository->findById($storeId);

        if ($store === null) {
            throw new \RuntimeException("Store {$storeId} not found");
        }

        $totalStock = $store->getTotalStock($productId);
        $reservedStock = $store->getReservedStock($productId);

        return $totalStock - $reservedStock;
    }

    public function allocateStock(int $storeId, int $productId, int $quantity): bool
    {
        $store = $this->storeRepository->findById($storeId);

        if ($store === null) {
            throw new \RuntimeException("Store {$storeId} not found");
        }

        $available = $this->getAvailableStock($storeId, $productId);

        if ($available < $quantity) {
            $this->logger->warning('Insufficient stock for allocation', [
                'store_id' => $storeId,
                'product_id' => $productId,
                'requested' => $quantity,
                'available' => $available,
            ]);
            return false;
        }

        $store->reserveStock($productId, $quantity);
        $this->storeRepository->save($store);

        $this->logger->info('Stock allocated', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function releaseStock(int $storeId, int $productId, int $quantity): bool
    {
        $store = $this->storeRepository->findById($storeId);

        if ($store === null) {
            throw new \RuntimeException("Store {$storeId} not found");
        }

        $store->releaseReservedStock($productId, $quantity);
        $this->storeRepository->save($store);

        $this->logger->info('Stock released', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function transferStock(int $fromStoreId, int $toStoreId, int $productId, int $quantity): bool
    {
        $fromStore = $this->storeRepository->findById($fromStoreId);
        $toStore = $this->storeRepository->findById($toStoreId);

        if ($fromStore === null || $toStore === null) {
            throw new \RuntimeException('Store not found');
        }

        $available = $this->getAvailableStock($fromStoreId, $productId);

        if ($available < $quantity) {
            return false;
        }

        $fromStore->releaseReservedStock($productId, $quantity);
        $toStore->addStock($productId, $quantity);

        $this->storeRepository->save($fromStore);
        $this->storeRepository->save($toStore);

        $this->logger->info('Stock transferred', [
            'from_store' => $fromStoreId,
            'to_store' => $toStoreId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }
}
