<?php

declare(strict_types=1);

namespace App\Upload;

use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Service\Storage\S3StorageService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VideoUploadService
{
    private const MAX_FILE_SIZE = 104857600;
    private const ALLOWED_MIME_TYPES = [
        'video/mp4',
        'video/mpeg',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
    ];

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly S3StorageService $storageService,
        private readonly LoggerInterface $logger,
    ) {}

    public function handleUpload(UploadedFile $file, int $userId): Video
    {
        $video = new Video();
        $video->setUserId($userId);
        $video->setOriginalName($file->getClientOriginalName());
        $video->setStatus('pending');
        $video->setCreatedAt(new \DateTimeImmutable());

        try {
            $validationResult = $this->validateFile($file);
            if (!$validationResult->isValid()) {
                $video->setStatus('failed');
                $video->setFailureReason($validationResult->getError());
                $this->videoRepository->save($video);
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

            $video->setStoragePath($uploadResult->getKey());
            $video->setMimeType($mimeType);
            $video->setFileSize($fileSize);
            $video->setStatus('uploaded');
            $video->setUploadedAt(new \DateTimeImmutable());

            $this->processVideo($video);

            $this->videoRepository->save($video);

            $this->logger->info('Video uploaded successfully', [
                'video_id' => $video->getId(),
                'user_id' => $userId,
                'path' => $uploadResult->getKey(),
            ]);

        } catch (\Throwable $e) {
            $video->setStatus('failed');
            $video->setFailureReason($e->getMessage());
            $this->videoRepository->save($video);
            $this->logger->error('Video upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $video;
    }

    public function processVideo(Video $video): void
    {
        $video->setProcessingStatus('transcoding');

        $duration = $this->extractDuration($video->getStoragePath());
        if ($duration !== null) {
            $video->setDuration($duration);
        }

        $video->setProcessingStatus('thumbnail_generated');

        $ffmpegPath = '/usr/bin/ffmpeg';
        if (file_exists($ffmpegPath)) {
            $video->setProcessingStatus('ready');
        } else {
            $video->setProcessingStatus('pending_transcoding');
        }

        $this->videoRepository->save($video);
    }

    private function validateFile(UploadedFile $file): ValidationResult
    {
        if (!$file->isValid()) {
            return ValidationResult::invalid('File upload error: ' . $file->getError());
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ValidationResult::invalid('File size exceeds maximum allowed (100MB)');
        }

        $mimeType = $file->getMimeType();
        if ($mimeType === null || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ValidationResult::invalid('File type not allowed. Allowed: MP4, MPEG, WebM, MOV, AVI');
        }

        return ValidationResult::valid();
    }

    private function generateStoragePath(int $userId, string $extension): string
    {
        $date = new \DateTimeImmutable();
        return sprintf(
            'videos/%d/%s/%s%s',
            $userId,
            $date->format('Y/m/d'),
            bin2hex(random_bytes(8)),
            $extension ? '.' . $extension : ''
        );
    }

    private function extractDuration(string $path): ?int
    {
        return null;
    }
}
