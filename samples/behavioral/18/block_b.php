<?php

declare(strict_types=1);

namespace App\Upload;

use App\Entity\Image;
use App\Repository\ImageRepository;
use App\Service\Storage\S3StorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageUploadService
{
    private const MAX_FILE_SIZE = 5242880;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    public function __construct(
        private readonly ImageRepository $imageRepository,
        private readonly S3StorageService $storageService,
        private readonly LoggerInterface $logger,
    ) {}

    public function handleUpload(UploadedFile $file, int $userId): Image
    {
        $image = new Image();
        $image->setUserId($userId);
        $image->setOriginalName($file->getClientOriginalName());
        $image->setStatus('pending');
        $image->setCreatedAt(new \DateTimeImmutable());

        try {
            $validationResult = $this->validateFile($file);
            if (!$validationResult->isValid()) {
                $image->setStatus('failed');
                $image->setFailureReason($validationResult->getError());
                $this->imageRepository->save($image);
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

            $image->setStoragePath($uploadResult->getKey());
            $image->setMimeType($mimeType);
            $image->setFileSize($fileSize);
            $image->setStatus('uploaded');
            $image->setUploadedAt(new \DateTimeImmutable());

            $this->processImage($image);

            $this->imageRepository->save($image);

            $this->logger->info('Image uploaded successfully', [
                'image_id' => $image->getId(),
                'user_id' => $userId,
                'path' => $uploadResult->getKey(),
            ]);

        } catch (\Throwable $e) {
            $image->setStatus('failed');
            $image->setFailureReason($e->getMessage());
            $this->imageRepository->save($image);
            $this->logger->error('Image upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $image;
    }

    public function processImage(Image $image): void
    {
        $dimensions = getimagesize($image->getStoragePath());
        if ($dimensions !== false) {
            $image->setWidth($dimensions[0]);
            $image->setHeight($dimensions[1]);
        }

        $image->setProcessingStatus('thumbnail_generated');

        if ($image->getFileSize() > 1048576) {
            $image->setProcessingStatus('optimized');
        } else {
            $image->setProcessingStatus('completed');
        }

        $this->imageRepository->save($image);
    }

    private function validateFile(UploadedFile $file): ValidationResult
    {
        if (!$file->isValid()) {
            return ValidationResult::invalid('File upload error: ' . $file->getError());
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ValidationResult::invalid('File size exceeds maximum allowed (5MB)');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ValidationResult::invalid('File type not allowed. Allowed: JPEG, PNG, GIF, WebP, SVG');
        }

        return ValidationResult::valid();
    }

    private function generateStoragePath(int $userId, string $extension): string
    {
        $date = new \DateTimeImmutable();
        return sprintf(
            'images/%d/%s/%s%s',
            $userId,
            $date->format('Y/m/d'),
            bin2hex(random_bytes(8)),
            $extension ? '.' . $extension : ''
        );
    }
}
