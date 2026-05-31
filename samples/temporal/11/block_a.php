<?php
declare(strict_types=1);

namespace Ebay\Inventory\Service;

use Ebay\Inventory\Repository\InventoryLockRepository;
use Ebay\Inventory\Repository\ProductRepository;
use Ebay\Inventory\Entity\InventoryLock;
use Ebay\Inventory\Entity\StockMovement;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;

final class OrderFulfillmentService
{
    private InventoryLockRepository $lockRepository;
    private ProductRepository $productRepository;
    private Connection $connection;
    private LoggerInterface $logger;

    public function __construct(
        InventoryLockRepository $lockRepository,
        ProductRepository $productRepository,
        Connection $connection,
        LoggerInterface $logger
    ) {
        $this->lockRepository = $lockRepository;
        $this->productRepository = $productRepository;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    public function fulfillOrder(string $orderId, array $items): array
    {
        $this->logger->info('Starting order fulfillment', ['order_id' => $orderId]);
        $startTime = microtime(true);

        try {
            $this->connection->beginTransaction();

            $lockedItems = [];
            foreach ($items as $item) {
                $sku = $item['sku'];
                $quantity = $item['quantity'];

                $lock = $this->lockRepository->acquireLock($sku, $quantity, $orderId);
                if ($lock === null) {
                    throw new \RuntimeException(
                        "Failed to acquire inventory lock for SKU: {$sku}"
                    );
                }
                $lockedItems[] = $lock;
                $this->logger->debug('Acquired lock for item', [
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'lock_id' => $lock->getId()
                ]);
            }

            foreach ($items as $item) {
                $sku = $item['sku'];
                $quantity = $item['quantity'];
                $warehouseId = $item['warehouse_id'] ?? 'DEFAULT';

                $updated = $this->productRepository->decrementStock(
                    $sku,
                    $quantity,
                    $warehouseId
                );

                if ($updated === false) {
                    throw new \RuntimeException(
                        "Insufficient stock for SKU: {$sku}"
                    );
                }

                $movement = new StockMovement([
                    'sku' => $sku,
                    'quantity' => -$quantity,
                    'warehouse_id' => $warehouseId,
                    'order_id' => $orderId,
                    'movement_type' => 'FULFILLMENT',
                    'created_at' => new \DateTimeImmutable()
                ]);
                $this->productRepository->recordMovement($movement);

                $this->logger->info('Stock decremented', [
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'warehouse' => $warehouseId
                ]);
            }

            foreach ($lockedItems as $lock) {
                $this->lockRepository->releaseLock($lock->getId());
                $this->logger->debug('Released lock', ['lock_id' => $lock->getId()]);
            }

            $this->connection->commit();

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('Order fulfillment completed', [
                'order_id' => $orderId,
                'items_count' => count($items),
                'duration_ms' => $elapsed
            ]);

            return [
                'success' => true,
                'order_id' => $orderId,
                'fulfilled_at' => (new \DateTimeImmutable())->format('c')
            ];

        } catch (\Throwable $e) {
            $this->connection->rollBack();

            foreach ($lockedItems ?? [] as $lock) {
                try {
                    $this->lockRepository->releaseLock($lock->getId());
                } catch (\Throwable $releaseError) {
                    $this->logger->error('Failed to release lock during rollback', [
                        'lock_id' => $lock->getId(),
                        'error' => $releaseError->getMessage()
                    ]);
                }
            }

            $this->logger->error('Order fulfillment failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
