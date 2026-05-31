<?php
declare(strict_types=1);

namespace Uber\Eats\Delivery\Service;

use Uber\Eats\Delivery\Repository\DriverAssignmentRepository;
use Uber\Eats\Delivery\Repository\DeliveryRepository;
use Uber\Eats\Delivery\Entity\Driver;
use Uber\Eats\Delivery\Entity\DeliveryTask;
use Uber\Eats\Delivery\Entity\LocationUpdate;
use Uber\Eats\Core\Messaging\EventDispatcher;
use Psr\Log\LoggerInterface;

final class DeliveryAssignmentService
{
    private DriverAssignmentRepository $assignmentRepo;
    private DeliveryRepository $deliveryRepo;
    private EventDispatcher $events;
    private LoggerInterface $logger;

    public function __construct(
        DriverAssignmentRepository $assignmentRepo,
        DeliveryRepository $deliveryRepo,
        EventDispatcher $events,
        LoggerInterface $logger
    ) {
        $this->assignmentRepo = $assignmentRepo;
        $this->deliveryRepo = $deliveryRepo;
        $this->events = $events;
        $this->logger = $logger;
    }

    public function assignDriverToDelivery(string $deliveryId, string $driverId): DeliveryAssignmentResult
    {
        $this->logger->info('Attempting driver assignment', [
            'delivery_id' => $deliveryId,
            'driver_id' => $driverId
        ]);

        $driver = $this->assignmentRepo->findDriver($driverId);
        if ($driver === null) {
            throw new \InvalidArgumentException("Driver not found: {$driverId}");
        }

        if ($driver->getStatus() !== Driver::STATUS_AVAILABLE) {
            throw new \RuntimeException(
                "Driver {$driverId} is not available for assignment (current status: {$driver->getStatus()})"
            );
        }

        $this->assignmentRepo->reserveDriver($driverId);
        $this->logger->debug('Driver reserved', ['driver_id' => $driverId]);

        try {
            $delivery = $this->deliveryRepo->findDeliveryForAssignment($deliveryId);
            if ($delivery === null) {
                throw new \RuntimeException("Delivery not found or not assignable: {$deliveryId}");
            }

            $assignedTask = $this->assignmentRepo->createAssignment($driverId, $deliveryId, [
                'assigned_at' => new \DateTimeImmutable(),
                'estimated_pickup_time' => (new \DateTimeImmutable())->modify('+15 minutes'),
                'pickup_address' => $delivery->getPickupAddress(),
                'dropoff_address' => $delivery->getDropoffAddress()
            ]);

            $this->deliveryRepo->updateDeliveryStatus($deliveryId, DeliveryTask::STATUS_ASSIGNED, [
                'driver_id' => $driverId,
                'assigned_at' => (new \DateTimeImmutable())->format('c')
            ]);

            $this->assignmentRepo->updateDriverStatus($driverId, Driver::STATUS_ON_DELIVERY);

            $this->events->dispatch(new \Uber\Eats\Delivery\Event\DriverAssignedEvent(
                $deliveryId,
                $driverId,
                $assignedTask->getId()
            ));

            $this->logger->info('Driver successfully assigned', [
                'delivery_id' => $deliveryId,
                'driver_id' => $driverId,
                'task_id' => $assignedTask->getId()
            ]);

            return new DeliveryAssignmentResult([
                'success' => true,
                'task_id' => $assignedTask->getId(),
                'estimated_arrival' => $assignedTask->getEstimatedPickupTime()->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->assignmentRepo->updateDriverStatus($driverId, Driver::STATUS_AVAILABLE);
            $this->logger->error('Driver assignment failed, driver status reverted', [
                'delivery_id' => $deliveryId,
                'driver_id' => $driverId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
