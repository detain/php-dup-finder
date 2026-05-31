<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Psr\Log\LoggerInterface;

/**
 * Inventory management service.
 * The LoggerInterface is manually injected here, duplicated across
 * all inventory-related services.
 */
class InventoryService
{
    private LoggerInterface $logger;
    private InventoryRepositoryInterface $inventoryRepository;
    private StockAlertService $alertService;

    public function __construct(
        InventoryRepositoryInterface $inventoryRepository,
        StockAlertService $alertService,
        LoggerInterface $logger
    ) {
        $this->inventoryRepository = $inventoryRepository;
        $this->alertService = $alertService;
        $this->logger = $logger;
    }

    public function adjustStock(string $productId, int $quantity, string $reason): StockAdjustment
    {
        $this->logger->info('Adjusting inventory stock', [
            'product_id' => $productId,
            'quantity_change' => $quantity,
            'reason' => $reason,
        ]);

        $currentStock = $this->inventoryRepository->getStockLevel($productId);

        if ($currentStock === null) {
            $this->logger->error('Product not found for stock adjustment', [
                'product_id' => $productId,
            ]);
            throw new ProductNotFoundException("Product not found: {$productId}");
        }

        $newQuantity = $currentStock->getQuantity() + $quantity;

        if ($newQuantity < 0) {
            $this->logger->warning('Stock adjustment would result in negative inventory', [
                'product_id' => $productId,
                'current_quantity' => $currentStock->getQuantity(),
                'requested_adjustment' => $quantity,
            ]);
            throw new InsufficientStockException(
                "Cannot reduce stock by {$quantity}. Current stock is {$currentStock->getQuantity()}"
            );
        }

        $adjustment = new StockAdjustment(
            productId: $productId,
            previousQuantity: $currentStock->getQuantity(),
            newQuantity: $newQuantity,
            adjustmentQuantity: $quantity,
            reason: $reason,
            adjustedAt: new \DateTimeImmutable(),
        );

        $this->inventoryRepository->saveAdjustment($adjustment);
        $this->inventoryRepository->updateStockLevel($productId, $newQuantity);

        $this->logger->info('Stock adjusted successfully', [
            'product_id' => $productId,
            'previous_quantity' => $currentStock->getQuantity(),
            'new_quantity' => $newQuantity,
        ]);

        if ($newQuantity <= $currentStock->getLowStockThreshold()) {
            $this->alertService->sendLowStockAlert($productId, $newQuantity);
        }

        return $adjustment;
    }

    public function reserveStock(string $productId, int $quantity, string $orderId): Reservation
    {
        $this->logger->info('Reserving stock', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'order_id' => $orderId,
        ]);

        $currentStock = $this->inventoryRepository->getStockLevel($productId);

        if ($currentStock === null) {
            throw new ProductNotFoundException("Product not found: {$productId}");
        }

        $available = $currentStock->getQuantity() - $currentStock->getReservedQuantity();

        if ($available < $quantity) {
            $this->logger->warning('Insufficient stock for reservation', [
                'product_id' => $productId,
                'requested' => $quantity,
                'available' => $available,
            ]);
            throw new InsufficientStockException(
                "Only {$available} units available, but {$quantity} requested"
            );
        }

        $reservation = new Reservation(
            id: bin2hex(random_bytes(16)),
            productId: $productId,
            orderId: $orderId,
            quantity: $quantity,
            status: 'reserved',
            createdAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+15 minutes'),
        );

        $this->inventoryRepository->saveReservation($reservation);

        $newReserved = $currentStock->getReservedQuantity() + $quantity;
        $this->inventoryRepository->updateReservedQuantity($productId, $newReserved);

        $this->logger->info('Stock reserved successfully', [
            'reservation_id' => $reservation->getId(),
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);

        return $reservation;
    }

    public function releaseReservation(string $reservationId): void
    {
        $this->logger->info('Releasing stock reservation', [
            'reservation_id' => $reservationId,
        ]);

        $reservation = $this->inventoryRepository->findReservation($reservationId);

        if ($reservation === null) {
            $this->logger->warning('Reservation not found', [
                'reservation_id' => $reservationId,
            ]);
            throw new ReservationNotFoundException("Reservation not found: {$reservationId}");
        }

        if ($reservation->getStatus() !== 'reserved') {
            $this->logger->warning('Reservation already processed', [
                'reservation_id' => $reservationId,
                'status' => $reservation->getStatus(),
            ]);
            return;
        }

        $reservation->release();

        $currentStock = $this->inventoryRepository->getStockLevel($reservation->getProductId());
        $newReserved = $currentStock->getReservedQuantity() - $reservation->getQuantity();

        $this->inventoryRepository->updateReservedQuantity(
            $reservation->getProductId(),
            $newReserved
        );

        $this->logger->info('Reservation released successfully', [
            'reservation_id' => $reservationId,
        ]);
    }
}
