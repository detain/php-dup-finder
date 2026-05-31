<?php
declare(strict_types=1);

namespace App\Core\Fraud\Workflow;

use Psr\Log\LoggerInterface;

interface FraudWorkflowStepInterface
{
    public function execute(mixed $entity): void;
    public function getName(): string;
}

abstract class BaseFraudWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function execute(string $entityId): void
    {
        $entity = $this->findEntity($entityId);
        $this->validateEntity($entity);
        $this->logger->info("Starting fraud workflow", ['entity_id' => $entityId]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $entity);
        }

        $this->completeWorkflow($entity);
        $this->logger->info("Fraud workflow completed", ['entity_id' => $entityId]);
    }

    protected function executeStep(FraudWorkflowStepInterface $step, mixed $entity): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['entity_id' => $this->getEntityId($entity)]);
        $step->execute($entity);
    }

    protected function recordAuditEvent(mixed $entity, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'entity_id' => $this->getEntityId($entity),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    abstract protected function findEntity(string $entityId): mixed;
    abstract protected function validateEntity(mixed $entity): void;
    abstract protected function getEntityId(mixed $entity): string;
    abstract protected function getSteps(): array;
    abstract protected function completeWorkflow(mixed $entity): void;
}

final class OrderFraudDetectionWorkflow extends BaseFraudWorkflow
{
    protected function findEntity(string $entityId): mixed { throw new \RuntimeException('Not implemented'); }
    protected function validateEntity(mixed $entity): void { }
    protected function getEntityId(mixed $entity): string { return ''; }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(mixed $entity): void { }
}
final class ManualReviewWorkflow extends BaseFraudWorkflow
{
    protected function findEntity(string $entityId): mixed { throw new \RuntimeException('Not implemented'); }
    protected function validateEntity(mixed $entity): void { }
    protected function getEntityId(mixed $entity): string { return ''; }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(mixed $entity): void { }
}
final class ChargebackWorkflow extends BaseFraudWorkflow
{
    protected function findEntity(string $entityId): mixed { throw new \RuntimeException('Not implemented'); }
    protected function validateEntity(mixed $entity): void { }
    protected function getEntityId(mixed $entity): string { return ''; }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(mixed $entity): void { }
}
