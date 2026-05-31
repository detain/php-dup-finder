<?php
declare(strict_types=1);

namespace App\Inventory\Analytics\Mapper;

use App\Domain\Entity\InventoryItem;
use App\Inventory\Analytics\DTO\InventoryTrendDTO;
use App\Inventory\Analytics\DTO\InventoryForecastDTO;

final readonly class InventoryAnalyticsMapper
{
    public function toTrendDTO(InventoryItem $item, array $historicalData): InventoryTrendDTO
    {
        $dto = new InventoryTrendDTO();
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
        $dto->historicalData = $historicalData;
        $dto->trendDirection = $this->calculateTrendDirection($historicalData);

        return $dto;
    }

    public function toForecastDTO(InventoryItem $item, array $forecastData): InventoryForecastDTO
    {
        $dto = new InventoryForecastDTO();
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
        $dto->forecastData = $forecastData;
        $dto->recommendedReorderDate = $this->calculateReorderDate($item);

        return $dto;
    }

    private function calculateTrendDirection(array $historicalData): string
    {
        if (count($historicalData) < 2) {
            return 'stable';
        }
        $first = $historicalData[0]['quantity'] ?? 0;
        $last = $historicalData[count($historicalData) - 1]['quantity'] ?? 0;
        if ($last > $first * 1.1) {
            return 'increasing';
        }
        if ($last < $first * 0.9) {
            return 'decreasing';
        }
        return 'stable';
    }

    private function calculateReorderDate(InventoryItem $item): string
    {
        $dailyUsage = $item->getDailyUsage() ?? 0;
        if ($dailyUsage <= 0) {
            return (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        }
        $daysUntilReorder = (int)(($item->getQuantityAvailable() - $item->getReorderPoint()) / $dailyUsage);
        return (new \DateTimeImmutable())->modify("+{$daysUntilReorder} days")->format('Y-m-d');
    }
}
