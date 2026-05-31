<?php
declare(strict_types=1);

namespace Audit\Logging;

use Psr\Log\LoggerInterface;

final class ProductAuditLogger
{
    private const BUFFER_SIZE = 100;
    private const FLUSH_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AuditEventSerializer $serializer,
        private readonly AuditEventRepository $repository,
    ) {}

    public function log(AuditEntry $entry): void
    {
        $context = $this->prepareContext($entry);
        $this->logger->info('Audit event', $context);

        $serializedEntry = $this->serializer->serialize($entry);
        $this->bufferEvent($serializedEntry);
    }

    public function logBatch(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->log($entry);
        }
    }

    public function logProductCreated(Product $product, ProductContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'product.created',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'product',
            entityId: $product->getId(),
            metadata: [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'category' => $product->getCategory(),
            ],
        ));
    }

    public function logProductUpdated(Product $product, array $changes, ProductContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'product.updated',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'product',
            entityId: $product->getId(),
            metadata: [
                'sku' => $product->getSku(),
                'changes' => $changes,
            ],
        ));
    }

    public function logProductPriceChanged(Product $product, float $oldPrice, float $newPrice, ProductContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'product.price_changed',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'product',
            entityId: $product->getId(),
            metadata: [
                'sku' => $product->getSku(),
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'price_change_percent' => $oldPrice > 0 ? (($newPrice - $oldPrice) / $oldPrice) * 100 : 0,
            ],
        ));
    }

    public function logProductInventoryAdjusted(Product $product, int $oldQuantity, int $newQuantity, string $reason, ProductContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'product.inventory_adjusted',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'product',
            entityId: $product->getId(),
            metadata: [
                'sku' => $product->getSku(),
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'adjustment' => $newQuantity - $oldQuantity,
                'reason' => $reason,
            ],
        ));
    }

    public function logProductDiscontinued(Product $product, ProductContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'product.discontinued',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'product',
            entityId: $product->getId(),
            metadata: [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'reason' => $context->getDiscontinuationReason(),
            ],
        ));
    }

    private function prepareContext(AuditEntry $entry): array
    {
        return [
            'event_type' => $entry->eventType,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'actor_id' => $entry->actorId,
            'timestamp' => $entry->timestamp->format(\DateTimeInterface::ISO8601),
        ];
    }

    private function bufferEvent(string $serializedEntry): void
    {
        static $buffer = [];
        static $lastFlush = 0;

        $buffer[] = $serializedEntry;

        if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flushBuffer($buffer);
            $buffer = [];
            $lastFlush = time();
        }
    }

    private function flushBuffer(array $buffer): void
    {
        foreach ($buffer as $serializedEntry) {
            try {
                $entry = $this->serializer->deserialize($serializedEntry);
                $this->repository->persist($entry);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist audit entry', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
