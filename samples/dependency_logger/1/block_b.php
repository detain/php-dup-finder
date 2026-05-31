<?php
declare(strict_types=1);

namespace Inventory\Stock;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class StockManagementService
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly WarehouseClient $warehouseApi,
        private readonly StockAlertService $alerts
    ) {}

    public function updateStockLevel(Request $request): StockUpdateResult
    {
        $productId = $request->request->getInt('product_id');
        $warehouseId = $request->request->getInt('warehouse_id');
        $quantityChange = $request->request->getInt('quantity');
        $reason = $request->request->get('reason', 'manual_adjustment');

        $this->logger->info('Updating stock level', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity_change' => $quantityChange,
            'reason' => $reason
        ]);

        $product = $this->entityManager->find(Product::class, $productId);
        if ($product === null) {
            $this->logger->error('Product not found for stock update', [
                'product_id' => $productId
            ]);
            return StockUpdateResult::failure('Product not found');
        }

        $warehouse = $this->entityManager->find(Warehouse::class, $warehouseId);
        if ($warehouse === null) {
            $this->logger->error('Warehouse not found', [
                'warehouse_id' => $warehouseId
            ]);
            return StockUpdateResult::failure('Warehouse not found');
        }

        // Find or create stock record
        $stockRecord = $this->entityManager
            ->getRepository(StockLevel::class)
            ->findOneBy(['product' => $product, 'warehouse' => $warehouse]);

        if ($stockRecord === null) {
            $stockRecord = new StockLevel();
            $stockRecord->setProduct($product);
            $stockRecord->setWarehouse($warehouse);
            $stockRecord->setQuantity(0);
        }

        $previousQuantity = $stockRecord->getQuantity();
        $newQuantity = $previousQuantity + $quantityChange;

        if ($newQuantity < 0) {
            $this->logger->warning('Stock would go negative', [
                'product_id' => $productId,
                'current' => $previousQuantity,
                'change' => $quantityChange
            ]);
            return StockUpdateResult::failure('Insufficient stock');
        }

        $stockRecord->setQuantity($newQuantity);
        $stockRecord->setLastUpdatedAt(new \DateTimeImmutable());

        // Record the adjustment
        $adjustment = new StockAdjustment();
        $adjustment->setProduct($product);
        $adjustment->setWarehouse($warehouse);
        $adjustment->setPreviousQuantity($previousQuantity);
        $adjustment->setNewQuantity($newQuantity);
        $adjustment->setChange($quantityChange);
        $adjustment->setReason($reason);
        $adjustment->setAdjustedAt(new \DateTimeImmutable());

        $this->entityManager->persist($adjustment);
        $this->entityManager->flush();

        $this->logger->info('Stock level updated', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'previous' => $previousQuantity,
            'new' => $newQuantity
        ]);

        // Check for low stock alert
        if ($newQuantity <= $product->getLowStockThreshold()) {
            $this->logger->warning('Low stock alert triggered', [
                'product_id' => $productId,
                'current_quantity' => $newQuantity,
                'threshold' => $product->getLowStockThreshold()
            ]);
            $this->alerts->sendLowStockAlert($product, $warehouse, $newQuantity);
        }

        return StockUpdateResult::success($previousQuantity, $newQuantity, $quantityChange);
    }

    public function syncWithWarehouse(int $warehouseId): SyncResult
    {
        $this->logger->info('Starting warehouse stock sync', [
            'warehouse_id' => $warehouseId
        ]);

        $warehouse = $this->entityManager->find(Warehouse::class, $warehouseId);
        if ($warehouse === null) {
            return SyncResult::failure('Warehouse not found');
        }

        try {
            $externalStock = $this->warehouseApi->getStockLevels($warehouseId);

            $synced = 0;
            $failed = 0;
            $now = new \DateTimeImmutable();

            foreach ($externalStock as $item) {
                $productId = $item['product_id'];
                $externalQty = $item['quantity'];

                $stockRecord = $this->entityManager
                    ->getRepository(StockLevel::class)
                    ->findOneBy(['product' => $productId, 'warehouse' => $warehouse]);

                if ($stockRecord === null) {
                    $product = $this->entityManager->find(Product::class, $productId);
                    if ($product === null) {
                        $failed++;
                        continue;
                    }

                    $stockRecord = new StockLevel();
                    $stockRecord->setProduct($product);
                    $stockRecord->setWarehouse($warehouse);
                    $stockRecord->setQuantity($externalQty);
                    $stockRecord->setLastUpdatedAt($now);

                    $this->entityManager->persist($stockRecord);
                    $synced++;
                } else {
                    $stockRecord->setQuantity($externalQty);
                    $stockRecord->setLastUpdatedAt($now);
                    $synced++;
                }
            }

            $this->entityManager->flush();

            $this->logger->info('Warehouse sync completed', [
                'warehouse_id' => $warehouseId,
                'synced' => $synced,
                'failed' => $failed
            ]);

            return SyncResult::success($synced, $failed);

        } catch (\Exception $e) {
            $this->logger->error('Warehouse sync failed', [
                'warehouse_id' => $warehouseId,
                'error' => $e->getMessage()
            ]);
            return SyncResult::failure('Sync failed: ' . $e->getMessage());
        }
    }

    public function getAvailableStock(int $productId, int $warehouseId = null): int
    {
        $qb = $this->entityManager
            ->getRepository(StockLevel::class)
            ->createQueryBuilder('s')
            ->select('SUM(s.quantity) as total');

        $qb->where('s.product = :productId')
            ->setParameter('productId', $productId);

        if ($warehouseId !== null) {
            $qb->andWhere('s.warehouse = :warehouseId')
                ->setParameter('warehouseId', $warehouseId);
        }

        $result = $qb->getQuery()->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
