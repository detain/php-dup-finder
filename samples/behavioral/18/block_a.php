<?php

declare(strict_types=1);

namespace App\Upload;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Service\Storage\S3StorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentUploadService
{
    private const MAX_FILE_SIZE = 10485760;
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
    ];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly S3StorageService $storageService,
        private readonly LoggerInterface $logger,
    ) {}

    public function handleUpload(UploadedFile $file, int $userId): Document
    {
        $document = new Document();
        $document->setUserId($userId);
        $document->setOriginalName($file->getClientOriginalName());
        $document->setStatus('pending');
        $document->setCreatedAt(new \DateTimeImmutable());

        try {
            $validationResult = $this->validateFile($file);
            if (!$validationResult->isValid()) {
                $document->setStatus('failed');
                $document->setFailureReason($validationResult->getError());
                $this->documentRepository->save($document);
                throw new \InvalidArgumentException($validationResult->getError());
            }

            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            $extension = $file->getClientOriginalExtension() ?? '';
            $fileSize = $file->getSize();

            $storagePath = $this->generateStoragePath($userId, $extension);

            $uploadResult = $this->storageService->upload($file, $storagePath);

            if (!$uploadResult->isSuccess()) {
                throw new \RuntimeException('Storage upload failed: ' . $uploadResult->getError());
            }

            $document->setStoragePath($uploadResult->getKey());
            $document->setMimeType($mimeType);
            $document->setFileSize($fileSize);
            $document->setStatus('uploaded');
            $document->setUploadedAt(new \DateTimeImmutable());

            $this->processDocument($document);

            $this->documentRepository->save($document);

            $this->logger->info('Document uploaded successfully', [
                'document_id' => $document->getId(),
                'user_id' => $userId,
                'path' => $uploadResult->getKey(),
            ]);

        } catch (\Throwable $e) {
            $document->setStatus('failed');
            $document->setFailureReason($e->getMessage());
            $this->documentRepository->save($document);
            $this->logger->error('Document upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $document;
    }

    public function processDocument(Document $document): void
    {
        if ($document->getMimeType() === 'application/pdf') {
            $document->setProcessingStatus('pdf_indexed');
        } elseif (str_contains($document->getMimeType(), 'word')) {
            $document->setProcessingStatus('text_extracted');
        } else {
            $document->setProcessingStatus('completed');
        }

        $this->documentRepository->save($document);
    }

    private function validateFile(UploadedFile $file): ValidationResult
    {
        if (!$file->isValid()) {
            return ValidationResult::invalid('File upload error: ' . $file->getError());
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ValidationResult::invalid('File size exceeds maximum allowed (10MB)');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ValidationResult::invalid('File type not allowed. Allowed: PDF, DOC, DOCX, TXT');
        }

        return ValidationResult::valid();
    }

    private function generateStoragePath(int $userId, string $extension): string
    {
        $date = new \DateTimeImmutable();
        return sprintf(
            'documents/%d/%s/%s%s',
            $userId,
            $date->format('Y/m/d'),
            bin2hex(random_bytes(8)),
            $extension ? '.' . $extension : ''
        );
    }
}
