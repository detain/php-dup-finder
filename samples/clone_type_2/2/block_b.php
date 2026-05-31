<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Entity\Shipment;
use App\Repository\ShipmentRepository;
use App\Service\WarehouseService;
use App\Service\CarrierGateway;
use App\Event\ShipmentDispatchedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ShipmentDispatchService
{
    public function __construct(
        private readonly ShipmentRepository $shipmentRepository,
        private readonly WarehouseService $warehouseService,
        private readonly CarrierGateway $carrierGateway,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatchShipment(int $shipmentId): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new \RuntimeException("Shipment {$shipmentId} not found");
        }

        if ($shipment->getStatus() !== 'ready') {
            throw new \RuntimeException("Shipment {$shipmentId} cannot be dispatched - invalid status");
        }

        $packages = $shipment->getPackages();
        foreach ($packages as $package) {
            $inStock = $this->warehouseService->checkStock(
                $package->getSku(),
                $package->getQuantity()
            );

            if (!$inStock) {
                $this->logger->warning('Product not in stock for shipment', [
                    'shipment_id' => $shipmentId,
                    'sku' => $package->getSku(),
                    'requested' => $package->getQuantity(),
                ]);
                throw new \RuntimeException("Product {$package->getSku()} not in stock");
            }
        }

        foreach ($packages as $package) {
            $this->warehouseService->allocateStock(
                $package->getSku(),
                $package->getQuantity()
            );
        }

        $trackingNumber = $this->carrierGateway->createLabel(
            $shipment->getSenderId(),
            $shipment->getTotalWeight(),
            $shipment->getDestination()
        );

        if ($trackingNumber === null) {
            foreach ($packages as $package) {
                $this->warehouseService->deallocateStock(
                    $package->getSku(),
                    $package->getQuantity()
                );
            }
            throw new \RuntimeException("Carrier label creation failed for shipment {$shipmentId}");
        }

        $shipment->setStatus('in_transit');
        $shipment->setTrackingNumber($trackingNumber);
        $shipment->setDispatchedAt(new \DateTimeImmutable());
        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(
            new ShipmentDispatchedEvent($shipment),
            ShipmentDispatchedEvent::NAME
        );

        $this->logger->info('Shipment dispatched successfully', [
            'shipment_id' => $shipmentId,
            'tracking_number' => $trackingNumber,
        ]);

        return $shipment;
    }
}
