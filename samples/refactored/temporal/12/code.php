<?php
declare(strict_types=1);

namespace App\Service\Pattern;

use App\Contract\SyncableInterface;
use App\Contract\CheckpointManagerInterface;
use App\Contract\ResourceLockInterface;

trait TemporalOperationTrait
{
    protected array $locks = [];

    protected function withLock(string $resourceId, callable $operation): mixed
    {
        $lock = $this->acquireLock($resourceId);
        $this->locks[] = $lock;

        try {
            return $operation();
        } finally {
            $this->releaseLock($lock);
            $this->locks = array_filter($this->locks, fn($l) => $l !== $lock);
        }
    }

    protected function withCheckpoint(string $entityType, callable $operation): mixed
    {
        $checkpoint = $this->checkpointManager->getCheckpoint($entityType)
            ?? $this->checkpointManager->createInitialCheckpoint($entityType);

        try {
            return $operation($checkpoint);
        } finally {
            $this->checkpointManager->updateCheckpoint(
                $entityType,
                new \DateTimeImmutable()
            );
        }
    }

    abstract protected function acquireLock(string $resourceId): ResourceLockInterface;
    abstract protected function releaseLock(ResourceLockInterface $lock): void;
}

final class DeliveryAssignmentService extends BaseService
{
    use TemporalOperationTrait;

    public function assignDriverToDelivery(string $deliveryId, string $driverId): DeliveryAssignmentResult
    {
        return $this->withLock("driver:{$driverId}", function () use ($deliveryId, $driverId) {
            return $this->performAssignment($deliveryId, $driverId);
        });
    }

    private function performAssignment(string $deliveryId, string $driverId): DeliveryAssignmentResult
    {
        $driver = $this->driverRepository->findAvailableDriver($driverId);
        $task = $this->assignmentRepository->createAssignment($driverId, $deliveryId);

        $this->eventDispatcher->dispatch(new DriverAssignedEvent($deliveryId, $driverId));

        return new DeliveryAssignmentResult(['success' => true, 'task_id' => $task->getId()]);
    }

    protected function acquireLock(string $resourceId): ResourceLockInterface
    {
        return new LockHandle($resourceId);
    }

    protected function releaseLock(ResourceLockInterface $lock): void
    {
        $lock->release();
    }
}
