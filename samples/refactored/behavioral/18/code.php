<?php

declare(strict_types=1);

namespace App\Upload;

use App\Entity\UploadedFile as EntityUploadedFile;
use App\Repository\UploadedFileRepository;
use App\Service\Storage\StorageServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class GenericUploadHandler
{
    /** @var array<string, UploadConfig> */
    private array $uploadConfigs = [];

    public function __construct(
        private readonly StorageServiceInterface $storageService,
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeConfigs();
    }

    private function initializeConfigs(): void
    {
        $this->uploadConfigs['document'] = new UploadConfig(
            maxSize: 10485760,
            allowedMimeTypes: [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'text/plain',
            ],
            storagePathPattern: 'documents/{userId}/{date}/{filename}.{ext}',
            processorClass: DocumentProcessor::class,
        );

        $this->uploadConfigs['image'] = new UploadConfig(
            maxSize: 5242880,
            allowedMimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
            storagePathPattern: 'images/{userId}/{date}/{filename}.{ext}',
            processorClass: ImageProcessor::class,
        );

        $this->uploadConfigs['video'] = new UploadConfig(
            maxSize: 104857600,
            allowedMimeTypes: ['video/mp4', 'video/mpeg', 'video/webm', 'video/quicktime', 'video/x-msvideo'],
            storagePathPattern: 'videos/{userId}/{date}/{filename}.{ext}',
            processorClass: VideoProcessor::class,
        );
    }

    public function handleUpload(UploadedFile $file, int $userId, string $type): EntityUploadedFile
    {
        $config = $this->uploadConfigs[$type] ?? null;

        if ($config === null) {
            throw new \InvalidArgumentException("Unknown upload type: {$type}");
        }

        $uploadedFile = $this->createFileEntity($type, $userId, $file);
        $uploadedFile->setOriginalName($file->getClientOriginalName());
        $uploadedFile->setStatus('pending');
        $uploadedFile->setCreatedAt(new \DateTimeImmutable());

        try {
            $validationResult = $this->validateFile($file, $config);
            if (!$validationResult->isValid()) {
                $uploadedFile->markFailed($validationResult->getError());
                $this->save($uploadedFile);
                throw new \InvalidArgumentException($validationResult->getError());
            }

            $storagePath = $this->generateStoragePath($config->storagePathPattern, $userId, $file);
            $uploadResult = $this->storageService->upload($file, $storagePath);

            if (!$uploadResult->isSuccess()) {
                throw new \RuntimeException('Storage upload failed: ' . $uploadResult->getError());
            }

            $uploadedFile->setStoragePath($uploadResult->getKey());
            $uploadedFile->setMimeType($file->getMimeType() ?? 'application/octet-stream');
            $uploadedFile->setFileSize($file->getSize());
            $uploadedFile->setStatus('uploaded');
            $uploadedFile->setUploadedAt(new \DateTimeImmutable());

            $this->processFile($uploadedFile, $config->processorClass);

            $this->save($uploadedFile);

            $this->logger->info("{$type} uploaded successfully", [
                'file_id' => $uploadedFile->getId(),
                'user_id' => $userId,
                'path' => $uploadResult->getKey(),
            ]);

        } catch (\Throwable $e) {
            $uploadedFile->markFailed($e->getMessage());
            $this->save($uploadedFile);
            $this->logger->error("{$type} upload failed", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $uploadedFile;
    }

    private function validateFile(UploadedFile $file, UploadConfig $config): ValidationResult
    {
        if (!$file->isValid()) {
            return ValidationResult::invalid('File upload error: ' . $file->getError());
        }

        if ($file->getSize() > $config->maxSize) {
            return ValidationResult::invalid("File size exceeds maximum allowed (" . ($config->maxSize / 1024 / 1024) . "MB)");
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || !in_array($mimeType, $config->allowedMimeTypes, true)) {
            return ValidationResult::invalid('File type not allowed');
        }

        return ValidationResult::valid();
    }

    private function generateStoragePath(string $pattern, int $userId, UploadedFile $file): string
    {
        $date = new \DateTimeImmutable();
        $replacements = [
            '{userId}' => (string) $userId,
            '{date}' => $date->format('Y/m/d'),
            '{filename}' => bin2hex(random_bytes(8)),
            '{ext}' => $file->getClientOriginalExtension() ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $pattern);
    }

    private function processFile(EntityUploadedFile $uploadedFile, string $processorClass): void
    {
        $processor = new $processorClass();
        $processor->process($uploadedFile);
    }

    private function createFileEntity(string $type, int $userId, UploadedFile $file): EntityUploadedFile
    {
        $entityClass = match ($type) {
            'document' => \App\Entity\Document::class,
            'image' => \App\Entity\Image::class,
            'video' => \App\Entity\Video::class,
            default => \App\Entity\UploadedFile::class,
        };

        return new $entityClass();
    }

    private function save(EntityUploadedFile $file): void
    {
        $repository = match (true) {
            $file instanceof \App\Entity\Document => new DocumentRepository(),
            $file instanceof \App\Entity\Image => new ImageRepository(),
            $file instanceof \App\Entity\Video => new VideoRepository(),
            default => new UploadedFileRepository(),
        };

        $repository->save($file);
    }
}

final class UploadConfig
{
    public function __construct(
        public readonly int $maxSize,
        public readonly array $allowedMimeTypes,
        public readonly string $storagePathPattern,
        public readonly string $processorClass,
    ) {}
}
