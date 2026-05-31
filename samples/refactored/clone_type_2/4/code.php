<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Entity\StockLocationInterface;
use App\Repository\StockLocationRepositoryInterface;
use Psr\Log\LoggerInterface;

interface StockLocationServiceInterface
{
    public function getAvailableStock(int $locationId, int $productId): int;
    public function allocateStock(int $locationId, int $productId, int $quantity): bool;
    public function releaseStock(int $locationId, int $productId, int $quantity): bool;
    public function transferStock(int $fromLocationId, int $toLocationId, int $productId, int $quantity): bool;
}

abstract class AbstractStockService implements StockLocationServiceInterface
{
    public function __construct(
        protected readonly StockLocationRepositoryInterface $repository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function getAvailableStock(int $locationId, int $productId): int
    {
        $location = $this->repository->findById($locationId);

        if ($location === null) {
            throw new \RuntimeException("Location {$locationId} not found");
        }

        $totalStock = $location->getTotalStock($productId);
        $reservedStock = $location->getReservedStock($productId);

        return $totalStock - $reservedStock;
    }

    public function allocateStock(int $locationId, int $productId, int $quantity): bool
    {
        $location = $this->repository->findById($locationId);

        if ($location === null) {
            throw new \RuntimeException("Location {$locationId} not found");
        }

        $available = $this->getAvailableStock($locationId, $productId);

        if ($available < $quantity) {
            $this->logger->warning('Insufficient stock for allocation', [
                'location_id' => $locationId,
                'product_id' => $productId,
                'requested' => $quantity,
                'available' => $available,
            ]);
            return false;
        }

        $location->reserveStock($productId, $quantity);
        $this->repository->save($location);

        $this->logger->info('Stock allocated', [
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function releaseStock(int $locationId, int $productId, int $quantity): bool
    {
        $location = $this->repository->findById($locationId);

        if ($location === null) {
            throw new \RuntimeException("Location {$locationId} not found");
        }

        $location->releaseReservedStock($productId, $quantity);
        $this->repository->save($location);

        $this->logger->info('Stock released', [
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function transferStock(int $fromLocationId, int $toLocationId, int $productId, int $quantity): bool
    {
        $fromLocation = $this->repository->findById($fromLocationId);
        $toLocation = $this->repository->findById($toLocationId);

        if ($fromLocation === null || $toLocation === null) {
            throw new \RuntimeException('Location not found');
        }

        $available = $this->getAvailableStock($fromLocationId, $productId);

        if ($available < $quantity) {
            return false;
        }

        $fromLocation->releaseReservedStock($productId, $quantity);
        $toLocation->addStock($productId, $quantity);

        $this->repository->save($fromLocation);
        $this->repository->save($toLocation);

        $this->logger->info('Stock transferred', [
            'from_location' => $fromLocationId,
            'to_location' => $toLocationId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }
}
