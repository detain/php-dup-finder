<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Entity\DistributionCenter;
use App\Repository\DistributionCenterRepository;
use App\Service\StockCalculator;
use Psr\Log\LoggerInterface;

final class DistributionCenterStockService
{
    public function __construct(
        private readonly DistributionCenterRepository $distributionCenterRepository,
        private readonly StockCalculator $stockCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function getAvailableStock(int $centerId, int $productId): int
    {
        $center = $this->distributionCenterRepository->findById($centerId);

        if ($center === null) {
            throw new \RuntimeException("Distribution center {$centerId} not found");
        }

        $totalStock = $center->getTotalStock($productId);
        $reservedStock = $center->getReservedStock($productId);

        return $totalStock - $reservedStock;
    }

    public function allocateStock(int $centerId, int $productId, int $quantity): bool
    {
        $center = $this->distributionCenterRepository->findById($centerId);

        if ($center === null) {
            throw new \RuntimeException("Distribution center {$centerId} not found");
        }

        $available = $this->getAvailableStock($centerId, $productId);

        if ($available < $quantity) {
            $this->logger->warning('Insufficient stock for allocation', [
                'center_id' => $centerId,
                'product_id' => $productId,
                'requested' => $quantity,
                'available' => $available,
            ]);
            return false;
        }

        $center->reserveStock($productId, $quantity);
        $this->distributionCenterRepository->save($center);

        $this->logger->info('Stock allocated', [
            'center_id' => $centerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function releaseStock(int $centerId, int $productId, int $quantity): bool
    {
        $center = $this->distributionCenterRepository->findById($centerId);

        if ($center === null) {
            throw new \RuntimeException("Distribution center {$centerId} not found");
        }

        $center->releaseReservedStock($productId, $quantity);
        $this->distributionCenterRepository->save($center);

        $this->logger->info('Stock released', [
            'center_id' => $centerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }

    public function transferStock(int $fromCenterId, int $toCenterId, int $productId, int $quantity): bool
    {
        $fromCenter = $this->distributionCenterRepository->findById($fromCenterId);
        $toCenter = $this->distributionCenterRepository->findById($toCenterId);

        if ($fromCenter === null || $toCenter === null) {
            throw new \RuntimeException('Distribution center not found');
        }

        $available = $this->getAvailableStock($fromCenterId, $productId);

        if ($available < $quantity) {
            return false;
        }

        $fromCenter->releaseReservedStock($productId, $quantity);
        $toCenter->addStock($productId, $quantity);

        $this->distributionCenterRepository->save($fromCenter);
        $this->distributionCenterRepository->save($toCenter);

        $this->logger->info('Stock transferred', [
            'from_center' => $fromCenterId,
            'to_center' => $toCenterId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return true;
    }
}
