<?php
declare(strict_types=1);

namespace App\Billing;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\PdfService;
use App\Service\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractBillingDocumentHandler
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly QueueService $queueService,
        protected readonly PdfService $pdfService,
        protected readonly StorageService $storageService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    protected function executeWithTransaction(callable $operations, array $context): void
    {
        $this->logger->info('Processing billing document event', $context);

        $this->entityManager->beginTransaction();
        try {
            $operations();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Billing document processing failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    protected function generateAndStorePdf(object $document, string $template, array $data): string
    {
        $pdfContent = $this->pdfService->generate($template, $data);
        $path = $this->storageService->store($this->getStoragePath($document), $pdfContent, 'application/pdf');

        return $path;
    }

    protected function createDocumentRecord(
        object $document,
        string $type,
        string $fileName,
        string $filePath
    ): void {
        $doc = new \App\Entity\Document();
        $doc->setType($type);
        $doc->setReferenceType($this->getDocumentType());
        $doc->setReferenceId($this->getDocumentId($document));
        $doc->setFileName($fileName);
        $doc->setFilePath($filePath);
        $doc->setMimeType('application/pdf');
        $doc->setCustomerId($this->getCustomerId($document));
        $doc->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($doc);
    }

    protected function recordAnalytics(string $eventName, int $customerId, array $payload): void
    {
        $event = new AnalyticsEvent();
        $event->setEventName($eventName);
        $event->setCustomerId($customerId);
        $event->setPayload($payload);
        $event->setOccurredAt(new \DateTimeImmutable());
        $this->entityManager->persist($event);
    }

    protected function createAuditEntry(string $action, object $document): void
    {
        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setEntityType($this->getDocumentType());
        $entry->setEntityId($this->getDocumentId($document));
        $entry->setUserId($this->getCustomerId($document));
        $entry->setMetadata($this->getAuditMetadata($document));
        $entry->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($entry);
    }

    abstract protected function getDocumentType(): string;
    abstract protected function getDocumentId(object $document): int;
    abstract protected function getCustomerId(object $document): int;
    abstract protected function getStoragePath(object $document): string;
    abstract protected function getAuditMetadata(object $document): array;
}
