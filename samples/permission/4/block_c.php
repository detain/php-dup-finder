<?php
declare(strict_types=1);

namespace App\Document\Security;

use App\Domain\Entity\User;
use App\Domain\Entity\Document;
use App\Domain\Entity\DocumentVersion;
use App\Domain\Repository\DocumentRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class DocumentPermissionService
{
    public function __construct(
        private DocumentRepositoryInterface $documentRepository,
        private LoggerInterface $logger,
    ) {}

    public function canReadDocument(User $user, string $documentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Document read permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Document read permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return false;
        }

        $document = $this->documentRepository->findById($documentId);
        if ($document === null) {
            $this->logger->info('Document read permission denied: document not found', [
                'document_id' => $documentId,
            ]);
            return false;
        }

        if ($this->userIsDocumentOwner($user, $document)) {
            $this->logger->debug('Document read permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        if ($this->userHasDocumentPermission($user, $document, 'read')) {
            $this->logger->debug('Document read permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        if ($this->userSharesWorkspace($user, $document)) {
            $this->logger->debug('Document read permission granted: workspace access', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        $this->logger->info('Document read permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'document_id' => $documentId,
        ]);

        return false;
    }

    public function canWriteDocument(User $user, string $documentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Document write permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Document write permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return false;
        }

        $document = $this->documentRepository->findById($documentId);
        if ($document === null) {
            $this->logger->info('Document write permission denied: document not found', [
                'document_id' => $documentId,
            ]);
            return false;
        }

        if ($this->userIsDocumentOwner($user, $document)) {
            $this->logger->debug('Document write permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        if ($this->userHasDocumentPermission($user, $document, 'write')) {
            $this->logger->debug('Document write permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        if ($this->userSharesWorkspace($user, $document)) {
            $this->logger->debug('Document write permission granted: workspace access', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        $this->logger->info('Document write permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'document_id' => $documentId,
        ]);

        return false;
    }

    public function canDeleteDocument(User $user, string $documentId): bool
    {
        if ($user === null) {
            $this->logger->warning('Document delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Document delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return false;
        }

        $document = $this->documentRepository->findById($documentId);
        if ($document === null) {
            $this->logger->info('Document delete permission denied: document not found', [
                'document_id' => $documentId,
            ]);
            return false;
        }

        if ($this->userIsDocumentOwner($user, $document)) {
            $this->logger->debug('Document delete permission granted: owner', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        if ($this->userHasDocumentPermission($user, $document, 'delete')) {
            $this->logger->debug('Document delete permission granted: direct permission', [
                'user_id' => $user->getId()->toString(),
                'document_id' => $documentId,
            ]);
            return true;
        }

        $this->logger->info('Document delete permission denied: no access', [
            'user_id' => $user->getId()->toString(),
            'document_id' => $documentId,
        ]);

        return false;
    }

    private function userIsDocumentOwner(User $user, Document $document): bool
    {
        return $document->getOwnerId()->equals($user->getId());
    }

    private function userHasDocumentPermission(User $user, Document $document, string $action): bool
    {
        return false;
    }

    private function userSharesWorkspace(User $user, Document $document): bool
    {
        return false;
    }
}
