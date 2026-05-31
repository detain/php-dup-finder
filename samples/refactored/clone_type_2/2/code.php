<?php

declare(strict_types=1);

namespace App\Fulfillment;

use App\Entity\FulfillableInterface;
use App\Repository\FulfillmentRepository;
use App\Service\AvailabilityCheckerInterface;
use App\Service\FundManagerInterface;
use App\Service\ExternalGatewayInterface;
use App\Event\FulfillmentProcessedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class FulfillmentService
{
    public function __construct(
        private readonly FulfillmentRepository $fulfillmentRepository,
        private readonly AvailabilityCheckerInterface $availabilityChecker,
        private readonly FundManagerInterface $fundManager,
        private readonly ExternalGatewayInterface $externalGateway,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function fulfill(int $entityId): FulfillableInterface
    {
        $entity = $this->fulfillmentRepository->findById($entityId);

        if ($entity === null) {
            throw new \RuntimeException("Entity {$entityId} not found");
        }

        $this->validateCanFulfill($entity);

        $items = $entity->getItems();
        foreach ($items as $item) {
            $this->availabilityChecker->verify($item->getProductId(), $item->getQuantity());
        }

        $this->reserveInventory($items);

        $externalReference = $this->externalGateway->process(
            $entity->getCustomerId(),
            $entity->getTotalAmount(),
            $entity->getMetadata()
        );

        if ($externalReference === null) {
            $this->releaseInventory($items);
            throw new \RuntimeException("External processing failed for entity {$entityId}");
        }

        $entity->setStatus('processing');
        $entity->setExternalReference($externalReference);
        $entity->setProcessedAt(new \DateTimeImmutable());
        $this->fulfillmentRepository->save($entity);

        $this->eventDispatcher->dispatch(
            new FulfillmentProcessedEvent($entity),
            FulfillmentProcessedEvent::NAME
        );

        $this->logger->info('Entity fulfilled successfully', [
            'entity_id' => $entityId,
            'external_reference' => $externalReference,
        ]);

        return $entity;
    }

    private function validateCanFulfill(FulfillableInterface $entity): void
    {
        $validStatuses = $entity->getValidInitialStatuses();
        if (!in_array($entity->getStatus(), $validStatuses, true)) {
            throw new \RuntimeException(
                "Entity {$entity->getId()} cannot be processed - invalid status"
            );
        }
    }

    private function reserveInventory(iterable $items): void
    {
        foreach ($items as $item) {
            $this->availabilityChecker->reserve($item->getProductId(), $item->getQuantity());
        }
    }

    private function releaseInventory(iterable $items): void
    {
        foreach ($items as $item) {
            $this->availabilityChecker->release($item->getProductId(), $item->getQuantity());
        }
    }
}
