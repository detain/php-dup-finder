<?php
declare(strict_types=1);

namespace App\Inventory\Warehouse\Mapper;

use App\Domain\Entity\InventoryItem;
use App\Inventory\Warehouse\DTO\WarehouseItemDTO;
use App\Inventory\Warehouse\DTO\WarehouseAlertDTO;
use App\Inventory\Warehouse\DTO\WarehouseReportDTO;

final readonly class WarehouseInventoryMapper
{
    public function toWarehouseItemDTO(InventoryItem $item): WarehouseItemDTO
    {
        $dto = new WarehouseItemDTO();
        $dto->id = $item->getId()->toString();
        $dto->productId = $item->getProductId()->toString();
        $dto->sku = $item->getSku();
        $dto->productName = $item->getProductName();
        $dto->warehouseId = $item->getWarehouseId()->toString();
        $dto->warehouseName = $item->getWarehouseName();
        $dto->locationCode = $item->getLocationCode();
        $dto->zone = $item->getZone();
        $dto->aisle = $item->getAisle();
        $dto->rack = $item->getRack();
        $dto->shelf = $item->getShelf();
        $dto->bin = $item->getBin();
        $dto->quantityOnHand = $item->getQuantityOnHand();
        $dto->quantityReserved = $item->getQuantityReserved();
        $dto->quantityAvailable = $item->getQuantityAvailable();
        $dto->quantityOnOrder = $item->getQuantityOnOrder();
        $dto->quantityInTransit = $item->getQuantityInTransit();
        $dto->reorderPoint = $item->getReorderPoint();
        $dto->reorderQuantity = $item->getReorderQuantity();
        $dto->leadTimeDays = $item->getLeadTimeDays();
        $dto->lastReceivedAt = $item->getLastReceivedAt()?->format(\DateTimeInterface::ATOM);
        $dto->lastShippedAt = $item->getLastShippedAt()?->format(\DateTimeInterface::ATOM);
        $dto->expiryDate = $item->getExpiryDate()?->format('Y-m-d');
        $dto->status = $item->getStatus()->value;

        return $dto;
    }

    public function toWarehouseAlertDTO(InventoryItem $item): WarehouseAlertDTO
    {
        $dto = new WarehouseAlertDTO();
        $dto->id = $item->getId()->toString();
        $dto->productId = $item->getProductId()->toString();
        $dto->sku = $item->getSku();
        $dto->productName = $item->getProductName();
        $dto->warehouseId = $item->getWarehouseId()->toString();
        $dto->warehouseName = $item->getWarehouseName();
        $dto->locationCode = $item->getLocationCode();
        $dto->zone = $item->getZone();
        $dto->aisle = $item->getAisle();
        $dto->rack = $item->getRack();
        $dto->shelf = $item->getShelf();
        $dto->bin = $item->getBin();
        $dto->quantityOnHand = $item->getQuantityOnHand();
        $dto->quantityReserved = $item->getQuantityReserved();
        $dto->quantityAvailable = $item->getQuantityAvailable();
        $dto->quantityOnOrder = $item->getQuantityOnOrder();
        $dto->quantityInTransit = $item->getQuantityInTransit();
        $dto->reorderPoint = $item->getReorderPoint();
        $dto->reorderQuantity = $item->getReorderQuantity();
        $dto->leadTimeDays = $item->getLeadTimeDays();
        $dto->lastReceivedAt = $item->getLastReceivedAt()?->format(\DateTimeInterface::ATOM);
        $dto->lastShippedAt = $item->getLastShippedAt()?->format(\DateTimeInterface::ATOM);
        $dto->expiryDate = $item->getExpiryDate()?->format('Y-m-d');
        $dto->status = $item->getStatus()->value;
        $dto->alertType = $this->determineAlertType($item);
        $dto->alertSeverity = $this->determineSeverity($item);

        return $dto;
    }

    public function toWarehouseReportDTO(InventoryItem $item): WarehouseReportDTO
    {
        $dto = new WarehouseReportDTO();
        $dto->id = $item->getId()->toString();
        $dto->productId = $item->getProductId()->toString();
        $dto->sku = $item->getSku();
        $dto->productName = $item->getProductName();
        $dto->warehouseId = $item->getWarehouseId()->toString();
        $dto->warehouseName = $item->getWarehouseName();
        $dto->locationCode = $item->getLocationCode();
        $dto->zone = $item->getZone();
        $dto->aisle = $item->getAisle();
        $dto->rack = $item->getRack();
        $dto->shelf = $item->getShelf();
        $dto->bin = $item->getBin();
        $dto->quantityOnHand = $item->getQuantityOnHand();
        $dto->quantityReserved = $item->getQuantityReserved();
        $dto->quantityAvailable = $item->getQuantityAvailable();
        $dto->quantityOnOrder = $item->getQuantityOnOrder();
        $dto->quantityInTransit = $item->getQuantityInTransit();
        $dto->reorderPoint = $item->getReorderPoint();
        $dto->reorderQuantity = $item->getReorderQuantity();
        $dto->leadTimeDays = $item->getLeadTimeDays();
        $dto->lastReceivedAt = $item->getLastReceivedAt()?->format(\DateTimeInterface::ATOM);
        $dto->lastShippedAt = $item->getLastShippedAt()?->format(\DateTimeInterface::ATOM);
        $dto->expiryDate = $item->getExpiryDate()?->format('Y-m-d');
        $dto->status = $item->getStatus()->value;
        $dto->turnoverRate = $this->calculateTurnover($item);
        $dto->daysOfSupply = $this->calculateDaysOfSupply($item);

        return $dto;
    }

    private function determineAlertType(InventoryItem $item): string
    {
        if ($item->getQuantityAvailable() <= 0) {
            return 'out_of_stock';
        }
        if ($item->getQuantityAvailable() < $item->getReorderPoint()) {
            return 'below_reorder_point';
        }
        if ($item->isExpiringSoon()) {
            return 'expiring_soon';
        }
        return 'none';
    }

    private function determineSeverity(InventoryItem $item): string
    {
        if ($item->getQuantityAvailable() <= 0) {
            return 'critical';
        }
        if ($item->getQuantityAvailable() < ($item->getReorderPoint() * 0.5)) {
            return 'high';
        }
        return 'medium';
    }

    private function calculateTurnover(InventoryItem $item): float
    {
        $annualUsage = $item->getAnnualUsage() ?? 0;
        $avgInventory = ($item->getQuantityOnHand() + $item->getReorderPoint()) / 2;
        return $avgInventory > 0 ? $annualUsage / $avgInventory : 0;
    }

    private function calculateDaysOfSupply(InventoryItem $item): int
    {
        $dailyUsage = $item->getDailyUsage() ?? 0;
        return $dailyUsage > 0 ? (int)($item->getQuantityAvailable() / $dailyUsage) : 999;
    }
}
