<?php
declare(strict_types=1);

namespace App\Core\Workflow;

use Psr\Log\LoggerInterface;

interface WorkflowStepInterface
{
    public function execute(mixed $context): void;
    public function getName(): string;
    public function getRollbackContext(): ?string;
}

abstract class BaseWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function execute(mixed $entity): void
    {
        $this->validateEntity($entity);
        $this->logger->info("Starting {$this->getWorkflowName()}", ['entity_id' => $this->getEntityId($entity)]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $entity);
        }

        $this->onComplete($entity);
        $this->logger->info("{$this->getWorkflowName()} completed", ['entity_id' => $this->getEntityId($entity)]);
    }

    protected function executeStep(WorkflowStepInterface $step, mixed $entity): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['entity_id' => $this->getEntityId($entity)]);

        try {
            $step->execute($entity);
            $this->recordAuditEvent($entity, $step->getName() . '_executed');
        } catch (\Throwable $e) {
            $this->handleStepFailure($step, $entity, $e);
            throw $e;
        }
    }

    protected function handleStepFailure(WorkflowStepInterface $step, mixed $entity, \Throwable $e): void
    {
        $this->recordAuditEvent($entity, $step->getName() . '_failed', [
            'error' => $e->getMessage(),
            'rollback_step' => $step->getRollbackContext(),
        ]);
    }

    abstract protected function getWorkflowName(): string;
    abstract protected function getEntityId(mixed $entity): string;
    abstract protected function validateEntity(mixed $entity): void;
    abstract protected function getSteps(): array;
    abstract protected function onComplete(mixed $entity): void;
    abstract protected function recordAuditEvent(mixed $entity, string $event, array $data = []): void;
}

final class OrderFulfillmentWorkflow extends BaseWorkflow
{
    protected function getWorkflowName(): string { return 'OrderFulfillment'; }
    protected function getEntityId(mixed $entity): string { return ''; }
    protected function validateEntity(mixed $entity): void { }
    protected function getSteps(): array { return []; }
    protected function onComplete(mixed $entity): void { }
    protected function recordAuditEvent(mixed $entity, string $event, array $data = []): void { }
}
final class SubscriptionActivationWorkflow extends BaseWorkflow
{
    protected function getWorkflowName(): string { return 'SubscriptionActivation'; }
    protected function getEntityId(mixed $entity): string { return ''; }
    protected function validateEntity(mixed $entity): void { }
    protected function getSteps(): array { return []; }
    protected function onComplete(mixed $entity): void { }
    protected function recordAuditEvent(mixed $entity, string $event, array $data = []): void { }
}
final class RefundProcessingWorkflow extends BaseWorkflow
{
    protected function getWorkflowName(): string { return 'RefundProcessing'; }
    protected function getEntityId(mixed $entity): string { return ''; }
    protected function validateEntity(mixed $entity): void { }
    protected function getSteps(): array { return []; }
    protected function onComplete(mixed $entity): void { }
    protected function recordAuditEvent(mixed $entity, string $event, array $data = []): void { }
}
