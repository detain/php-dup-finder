<?php
declare(strict_types=1);

namespace App\Inventory\Api\Mapper;

use App\Domain\Entity\InventoryItem;
use App\Inventory\Api\DTO\InventoryItemDTO;
use App\Inventory\Api\DTO\InventorySummaryDTO;

final readonly class InventoryApiMapper
{
    public function toItemDTO(InventoryItem $item): InventoryItemDTO
    {
        $dto = new InventoryItemDTO();
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

    public function toSummaryDTO(array $items): InventorySummaryDTO
    {
        $dto = new InventorySummaryDTO();
        $dto->items = array_map(fn($item) => $this->toItemDTO($item), $items);
        $dto->totalQuantityOnHand = array_sum(array_map(fn($i) => $i->getQuantityOnHand(), $items));
        $dto->totalQuantityReserved = array_sum(array_map(fn($i) => $i->getQuantityReserved(), $items));
        $dto->totalQuantityAvailable = array_sum(array_map(fn($i) => $i->getQuantityAvailable(), $items));
        $dto->totalQuantityOnOrder = array_sum(array_map(fn($i) => $i->getQuantityOnOrder(), $items));
        $dto->itemCount = count($items);
        $dto->lowStockCount = count(array_filter($items, fn($i) => $i->getQuantityAvailable() < $i->getReorderPoint()));
        $dto->outOfStockCount = count(array_filter($items, fn($i) => $i->getQuantityAvailable() <= 0));

        return $dto;
    }
}
