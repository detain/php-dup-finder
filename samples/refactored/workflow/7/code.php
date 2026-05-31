<?php
declare(strict_types=1);

namespace App\Core\Document\Generation;

use Psr\Log\LoggerInterface;

interface DocumentEntityInterface
{
    public function getId(): string;
    public function getTemplate(): ?string;
    public function setTemplateData(array $data): void;
    public function setRenderedHtml(string $html): void;
    public function setPdfData(string $data): void;
    public function setPageCount(int $count): void;
    public function setDocumentUrl(string $url): void;
}

interface DocumentGenerationStepInterface
{
    public function execute(DocumentEntityInterface $entity): void;
    public function getName(): string;
}

abstract class BaseDocumentGenerationWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function generate(string $entityId): void
    {
        $entity = $this->findEntity($entityId);
        $this->validateEntity($entity);
        $this->logger->info("Starting document generation", ['entity_id' => $entityId]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $entity);
        }

        $this->completeGeneration($entity);
        $this->logger->info("Document generation completed", ['entity_id' => $entityId]);
    }

    protected function executeStep(DocumentGenerationStepInterface $step, DocumentEntityInterface $entity): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['entity_id' => $entity->getId()]);
        $step->execute($entity);
    }

    protected function recordAuditEvent(DocumentEntityInterface $entity, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'entity_id' => $entity->getId(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    abstract protected function findEntity(string $entityId): DocumentEntityInterface;
    abstract protected function validateEntity(DocumentEntityInterface $entity): void;
    abstract protected function getSteps(): array;
    abstract protected function completeGeneration(DocumentEntityInterface $entity): void;
}

final class InvoiceGenerationWorkflow extends BaseDocumentGenerationWorkflow
{
    protected function findEntity(string $entityId): DocumentEntityInterface { throw new \RuntimeException('Not implemented'); }
    protected function validateEntity(DocumentEntityInterface $entity): void { }
    protected function getSteps(): array { return []; }
    protected function completeGeneration(DocumentEntityInterface $entity): void { }
}
final class ContractGenerationWorkflow extends BaseDocumentGenerationWorkflow
{
    protected function findEntity(string $entityId): DocumentEntityInterface { throw new \RuntimeException('Not implemented'); }
    protected function validateEntity(DocumentEntityInterface $entity): void { }
    protected function getSteps(): array { return []; }
    protected function completeGeneration(DocumentEntityInterface $entity): void { }
}
final class ReportGenerationWorkflow extends BaseDocumentGenerationWorkflow
{
    protected function findEntity(string $entityId): DocumentEntityInterface { throw new \RuntimeException('Not implemented'); }
    protected function validateEntity(DocumentEntityInterface $entity): void { }
    protected function getSteps(): array { return []; }
    protected function completeGeneration(DocumentEntityInterface $entity): void { }
}
