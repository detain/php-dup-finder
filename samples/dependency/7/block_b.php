<?php

declare(strict_types=1);

namespace App\Domain\Shipping;

use App\Infrastructure\EventDispatcher\EventDispatcherInterface;

/**
 * Shipping management service.
 * The EventDispatcherInterface is manually injected here, duplicated from
 * BillingService and other services.
 */
class ShippingService
{
    private EventDispatcherInterface $eventDispatcher;
    private ShipmentRepositoryInterface $shipmentRepository;
    private CarrierServiceInterface $carrierService;

    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        CarrierServiceInterface $carrierService,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->carrierService = $carrierService;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function createShipment(string $orderId, string $warehouseId, array $items, string $shippingMethod): Shipment
    {
        $shipment = Shipment::create(
            orderId: $orderId,
            warehouseId: $warehouseId,
            items: $items,
            shippingMethod: $shippingMethod,
        );

        $rate = $this->carrierService->getRate($shippingMethod, $items);

        $shipment->setShippingCost($rate->getCost());
        $shipment->setEstimatedDelivery($rate->getEstimatedDelivery());

        $savedShipment = $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentCreatedEvent($savedShipment));

        return $savedShipment;
    }

    public function labelGenerated(string $shipmentId, string $trackingNumber): void
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new ShipmentNotFoundException("Shipment not found: {$shipmentId}");
        }

        $shipment->setTrackingNumber($trackingNumber);
        $shipment->markAsLabelGenerated();

        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentLabelGeneratedEvent($shipment));
    }

    public function pickedUp(string $shipmentId): void
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new ShipmentNotFoundException("Shipment not found: {$shipmentId}");
        }

        $shipment->markAsPickedUp();
        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentPickedUpEvent($shipment));
    }

    public function inTransit(string $shipmentId, array $location): void
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new ShipmentNotFoundException("Shipment not found: {$shipmentId}");
        }

        $shipment->addTrackingEvent(
            new TrackingEvent(
                status: 'in_transit',
                location: $location,
                timestamp: new \DateTimeImmutable(),
            )
        );

        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentInTransitEvent($shipment, $location));
    }

    public function outForDelivery(string $shipmentId): void
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new ShipmentNotFoundException("Shipment not found: {$shipmentId}");
        }

        $shipment->markAsOutForDelivery();
        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentOutForDeliveryEvent($shipment));
    }

    public function delivered(string $shipmentId): void
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new ShipmentNotFoundException("Shipment not found: {$shipmentId}");
        }

        $shipment->markAsDelivered();
        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentDeliveredEvent($shipment));
    }

    public function exception(string $shipmentId, string $reason, array $details): void
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if ($shipment === null) {
            throw new ShipmentNotFoundException("Shipment not found: {$shipmentId}");
        }

        $shipment->addException($reason, $details);
        $this->shipmentRepository->save($shipment);

        $this->eventDispatcher->dispatch(new ShipmentExceptionEvent($shipment, $reason, $details));
    }
}
