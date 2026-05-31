<?php
declare(strict_types=1);

namespace App\Core\Inventory\Mapper;

use App\Domain\Entity\InventoryItem;
use App\Core\DTO\DTOInterface;

interface InventoryMappingStrategy
{
    public function getExtraFields(): array;
    public function shouldIncludeLocation(): bool;
}

abstract class BaseInventoryMapper
{
    public function map(InventoryItem $item, DTOInterface $dto, ?InventoryMappingStrategy $strategy = null): DTOInterface
    {
        $dto->id = $item->getId()->toString();
        $dto->productId = $item->getProductId()->toString();
        $dto->sku = $item->getSku();
        $dto->productName = $item->getProductName();
        $dto->warehouseId = $item->getWarehouseId()->toString();
        $dto->warehouseName = $item->getWarehouseName();
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

        if ($strategy === null || $strategy->shouldIncludeLocation()) {
            $dto->locationCode = $item->getLocationCode();
            $dto->zone = $item->getZone();
            $dto->aisle = $item->getAisle();
            $dto->rack = $item->getRack();
            $dto->shelf = $item->getShelf();
            $dto->bin = $item->getBin();
        }

        if ($strategy !== null) {
            foreach ($strategy->getExtraFields() as $field => $value) {
                $dto->{$field} = $value;
            }
        }

        return $dto;
    }
}

final class WarehouseInventoryMapper extends BaseInventoryMapper {}
final class InventoryApiMapper extends BaseInventoryMapper {}
final class InventoryAnalyticsMapper extends BaseInventoryMapper {}
