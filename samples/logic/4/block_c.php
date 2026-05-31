<?php

declare(strict_types=1);

namespace App\DocumentWorkflow;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Event\DocumentStatusChangedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DocumentStatusService
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function submitForReview(int $documentId): Document
    {
        $document = $this->documentRepository->findById($documentId);

        if ($document === null) {
            throw new \RuntimeException('Document not found');
        }

        if ($document->getStatus() !== 'draft') {
            throw new \InvalidArgumentException('Only draft documents can be submitted for review');
        }

        if (trim($document->getTitle()) === '') {
            throw new \InvalidArgumentException('Document must have a title');
        }

        if ($document->getAuthorId() === null) {
            throw new \InvalidArgumentException('Document must have an author');
        }

        $document->setStatus('pending_review');
        $document->setSubmittedAt(new \DateTimeImmutable());

        $this->documentRepository->save($document);

        $this->eventDispatcher->dispatch(
            new DocumentStatusChangedEvent($document, 'draft', 'pending_review'),
            DocumentStatusChangedEvent::NAME
        );

        $this->logger->info('Document submitted for review', [
            'document_id' => $documentId,
        ]);

        return $document;
    }

    public function approveDocument(int $documentId, int $reviewerId): Document
    {
        $document = $this->documentRepository->findById($documentId);

        if ($document === null) {
            throw new \RuntimeException('Document not found');
        }

        if ($document->getStatus() !== 'pending_review') {
            throw new \InvalidArgumentException('Only pending documents can be approved');
        }

        if ($document->getAuthorId() === $reviewerId) {
            throw new \InvalidArgumentException('Author cannot approve their own document');
        }

        $document->setStatus('approved');
        $document->setApprovedAt(new \DateTimeImmutable());
        $document->setApprovedBy($reviewerId);

        $this->documentRepository->save($document);

        $this->eventDispatcher->dispatch(
            new DocumentStatusChangedEvent($document, 'pending_review', 'approved'),
            DocumentStatusChangedEvent::NAME
        );

        $this->logger->info('Document approved', [
            'document_id' => $documentId,
            'reviewer_id' => $reviewerId,
        ]);

        return $document;
    }

    public function publishDocument(int $documentId): Document
    {
        $document = $this->documentRepository->findById($documentId);

        if ($document === null) {
            throw new \RuntimeException('Document not found');
        }

        if ($document->getStatus() !== 'approved') {
            throw new \InvalidArgumentException('Only approved documents can be published');
        }

        if ($document->getApprovedBy() === null) {
            throw new \InvalidArgumentException('Document must be approved before publishing');
        }

        $document->setStatus('published');
        $document->setPublishedAt(new \DateTimeImmutable());

        $this->documentRepository->save($document);

        $this->eventDispatcher->dispatch(
            new DocumentStatusChangedEvent($document, 'approved', 'published'),
            DocumentStatusChangedEvent::NAME
        );

        $this->logger->info('Document published', [
            'document_id' => $documentId,
        ]);

        return $document;
    }

    public function archiveDocument(int $documentId, string $reason): Document
    {
        $document = $this->documentRepository->findById($documentId);

        if ($document === null) {
            throw new \RuntimeException('Document not found');
        }

        if (in_array($document->getStatus(), ['archived', 'deleted'], true)) {
            throw new \InvalidArgumentException('Document is already archived or deleted');
        }

        if ($document->getStatus() === 'published' && !$document->canBeRetracted()) {
            throw new \InvalidArgumentException('Published documents cannot be archived without special permission');
        }

        $document->setStatus('archived');
        $document->setArchivedAt(new \DateTimeImmutable());
        $document->setArchiveReason($reason);

        $this->documentRepository->save($document);

        $this->eventDispatcher->dispatch(
            new DocumentStatusChangedEvent($document, $document->getStatus(), 'archived'),
            DocumentStatusChangedEvent::NAME
        );

        $this->logger->info('Document archived', [
            'document_id' => $documentId,
            'reason' => $reason,
        ]);

        return $document;
    }
}
