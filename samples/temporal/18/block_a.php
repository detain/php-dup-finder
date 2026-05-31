<?php
declare(strict_types=1);

namespace Shopify\Inventory\Service;

use Shopify\Inventory\Repository\ReservationRepository;
use Shopify\Inventory\Repository\StockLevelRepository;
use Shopify\Inventory\Repository\TransferRepository;
use Shopify\Inventory\Entity\Reservation;
use Shopify\Inventory\Entity\StockLevel;
use Shopify\Inventory\Entity\Transfer;
use Shopify\Inventory\Exception\InventoryException;
use Shopify\Inventory\Service\AllocationStrategy;
use Shopify\Inventory\Service\AuditLogger;
use Psr\Log\LoggerInterface;

final class InventoryAllocationService
{
    private ReservationRepository $reservationRepo;
    private StockLevelRepository $stockLevelRepo;
    private TransferRepository $transferRepo;
    private AllocationStrategy $allocationStrategy;
    private AuditLogger $auditLogger;
    private LoggerInterface $logger;

    public function __construct(
        ReservationRepository $reservationRepo,
        StockLevelRepository $stockLevelRepo,
        TransferRepository $transferRepo,
        AllocationStrategy $allocationStrategy,
        AuditLogger $auditLogger,
        LoggerInterface $logger
    ) {
        $this->reservationRepo = $reservationRepo;
        $this->stockLevelRepo = $stockLevelRepo;
        $this->transferRepo = $transferRepo;
        $this->allocationStrategy = $allocationStrategy;
        $this->auditLogger = $auditLogger;
        $this->logger = $logger;
    }

    public function allocateInventory(string $orderReference, array $lineItems): AllocationResult
    {
        $this->logger->info('Starting inventory allocation', [
            'order_reference' => $orderReference,
            'line_items_count' => count($lineItems)
        ]);

        $reservationId = $this->reservationRepo->createReservation($orderReference);
        $this->logger->debug('Reservation record created', ['reservation_id' => $reservationId]);

        $allocations = [];
        $errors = [];

        foreach ($lineItems as $item) {
            $sku = $item['sku'];
            $requestedQuantity = $item['quantity'];

            $availableLocations = $this->stockLevelRepo->findAvailableLocations($sku, $requestedQuantity);

            if (count($availableLocations) === 0) {
                $errors[] = [
                    'sku' => $sku,
                    'requested' => $requestedQuantity,
                    'available' => 0,
                    'error' => 'insufficient_stock'
                ];
                $this->logger->warning('Insufficient stock for SKU', [
                    'sku' => $sku,
                    'requested' => $requestedQuantity
                ]);
                continue;
            }

            $allocationPlan = $this->allocationStrategy->planAllocation(
                $availableLocations,
                $requestedQuantity
            );

            foreach ($allocationPlan as $allocation) {
                $this->stockLevelRepo->reserveStock(
                    $allocation['location_id'],
                    $sku,
                    $allocation['quantity']
                );

                $allocations[] = [
                    'sku' => $sku,
                    'location_id' => $allocation['location_id'],
                    'quantity' => $allocation['quantity'],
                    'reservation_id' => $reservationId
                ];
            }

            $this->logger->debug('Allocated inventory for SKU', [
                'sku' => $sku,
                'allocations_count' => count($allocationPlan)
            ]);
        }

        if (count($errors) > 0 && count($allocations) === 0) {
            $this->reservationRepo->cancelReservation($reservationId);
            throw new InventoryException('Allocation failed: insufficient stock for all items');
        }

        $this->reservationRepo->confirmReservation($reservationId, $allocations);

        $this->auditLogger->logAllocation($orderReference, $allocations, $errors);

        $this->logger->info('Inventory allocation completed', [
            'reservation_id' => $reservationId,
            'allocations_count' => count($allocations),
            'errors_count' => count($errors)
        ]);

        return new AllocationResult([
            'success' => count($errors) === 0,
            'reservation_id' => $reservationId,
            'allocations' => $allocations,
            'errors' => $errors
        ]);
    }

    public function commitAllocation(string $reservationId): CommitResult
    {
        $reservation = $this->reservationRepo->findById($reservationId);
        if ($reservation === null) {
            throw new InventoryException("Reservation not found: {$reservationId}");
        }

        if ($reservation->getStatus() !== 'confirmed') {
            throw new InventoryException("Cannot commit reservation in status: {$reservation->getStatus()}");
        }

        $commitLock = $this->reservationRepo->acquireCommitLock($reservationId);
        if ($commitLock === null) {
            throw new InventoryException("Could not acquire commit lock for reservation: {$reservationId}");
        }

        $this->logger->debug('Commit lock acquired', ['reservation_id' => $reservationId]);

        try {
            $allocations = $this->reservationRepo->getAllocations($reservationId);

            foreach ($allocations as $allocation) {
                $this->stockLevelRepo->convertReservationToDeduction(
                    $allocation['location_id'],
                    $allocation['sku'],
                    $allocation['quantity']
                );

                $transfer = Transfer::create([
                    'from_location_id' => $allocation['location_id'],
                    'to_type' => 'order',
                    'to_reference' => $reservation->getOrderReference(),
                    'sku' => $allocation['sku'],
                    'quantity' => $allocation['quantity'],
                    'status' => 'completed',
                    'completed_at' => new \DateTimeImmutable()
                ]);

                $this->transferRepo->save($transfer);
            }

            $this->reservationRepo->markAsCommitted($reservationId);

            $this->auditLogger->logCommit($reservationId, count($allocations));

            $this->reservationRepo->releaseCommitLock($commitLock);

            $this->logger->info('Allocation committed successfully', [
                'reservation_id' => $reservationId,
                'transfers_count' => count($allocations)
            ]);

            return new CommitResult([
                'success' => true,
                'reservation_id' => $reservationId,
                'committed_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->reservationRepo->releaseCommitLock($commitLock);
            $this->logger->error('Allocation commit failed', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function rollbackAllocation(string $reservationId): RollbackResult
    {
        $reservation = $this->reservationRepo->findById($reservationId);
        if ($reservation === null) {
            throw new InventoryException("Reservation not found: {$reservationId}");
        }

        if (!in_array($reservation->getStatus(), ['confirmed', 'committed'])) {
            throw new InventoryException("Cannot rollback reservation in status: {$reservation->getStatus()}");
        }

        $allocations = $this->reservationRepo->getAllocations($reservationId);

        foreach ($allocations as $allocation) {
            if ($reservation->getStatus() === 'committed') {
                $this->stockLevelRepo->restoreStockFromDeduction(
                    $allocation['location_id'],
                    $allocation['sku'],
                    $allocation['quantity']
                );
            } else {
                $this->stockLevelRepo->releaseReservation(
                    $allocation['location_id'],
                    $allocation['sku'],
                    $allocation['quantity']
                );
            }
        }

        $this->reservationRepo->markAsRolledBack($reservationId);

        $this->auditLogger->logRollback($reservationId, count($allocations));

        $this->logger->info('Allocation rolled back successfully', [
            'reservation_id' => $reservationId,
            'released_allocations' => count($allocations)
        ]);

        return new RollbackResult([
            'success' => true,
            'reservation_id' => $reservationId,
            'rolled_back_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }
}
