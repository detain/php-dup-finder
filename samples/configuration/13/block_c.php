<?php

declare(strict_types=1);

namespace App\Services\MediaUpload;

use App\Exceptions\MediaUploadException;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;

final class VideoUploadService
{
    private const MAX_FILE_SIZE = 104857600;
    private const ALLOWED_EXTENSIONS = ['mp4', 'mov', 'avi', 'wmv', 'flv', 'webm', 'mkv'];
    private const ALLOWED_MIME_TYPES = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-ms-wmv',
        'video/x-flv',
        'video/webm',
        'video/x-matroska',
    ];
    private const STORAGE_PATH = 'uploads/videos';
    private const UPLOAD_TIMEOUT = 120;
    private const CHUNK_SIZE = 65536;
    private const MAX_FILENAME_LENGTH = 255;
    private const TRANSCODED_FORMATS = ['h264', 'h265', 'vp9'];
    private const THUMBNAIL_TIMESTAMPS = [1, 5, 10];

    private string $storageBasePath;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $storageBasePath = '/var/www/uploads'
    ) {
        $this->storageBasePath = rtrim($storageBasePath, '/');
    }

    public function upload(UploadedFile $file, array $options = []): array
    {
        $this->validateFile($file);

        $originalName = $file->getClientOriginalName();
        $this->validateFilename($originalName);

        $extension = strtolower($file->getClientOriginalExtension());
        $this->validateExtension($extension);

        $mimeType = $file->getMimeType();
        $this->validateMimeType($mimeType);

        $fileSize = $file->getSize();
        $this->validateFileSize($fileSize);

        $storagePath = $this->buildStoragePath($options['path'] ?? '');
        $uniqueFilename = $this->generateUniqueFilename($originalName, $storagePath);
        $fullPath = $storagePath . '/' . $uniqueFilename;

        $this->ensureDirectoryExists($storagePath);

        try {
            $file->move($storagePath, $uniqueFilename);

            $result = [
                'path' => $fullPath,
                'url' => $this->generateUrl($fullPath),
                'filename' => $uniqueFilename,
                'original_name' => $originalName,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'duration' => $this->extractDuration($fullPath),
            ];

            if ($options['generate_thumbnails'] ?? false) {
                $thumbnails = $this->extractThumbnails($fullPath, $extension);
                $result['thumbnails'] = $thumbnails;
            }

            if ($options['transcode'] ?? false) {
                $transcoded = $this->transcodeVideo($fullPath, $options['formats'] ?? self::TRANSCODED_FORMATS);
                $result['transcoded'] = $transcoded;
            }

            $this->logger->info('Video uploaded successfully', [
                'original_name' => $originalName,
                'stored_as' => $uniqueFilename,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'path' => $fullPath,
                'max_size' => self::MAX_FILE_SIZE,
                'allowed_extensions' => self::ALLOWED_EXTENSIONS,
                'transcoded_formats' => self::TRANSCODED_FORMATS,
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Video upload failed', [
                'error' => $e->getMessage(),
                'original_name' => $originalName,
                'size' => $fileSize,
                'storage_path' => $storagePath,
                'timeout' => self::UPLOAD_TIMEOUT,
                'chunk_size' => self::CHUNK_SIZE,
            ]);
            throw new MediaUploadException('Failed to upload video: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uploadChunked(string $filename, string $data, int $chunkIndex, int $totalChunks): array
    {
        $this->validateFilename($filename);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->validateExtension($extension);

        $chunkPath = $this->storageBasePath . '/temp/video_chunks/' . sha1($filename);
        $this->ensureDirectoryExists($chunkPath);

        $chunkFile = $chunkPath . '/chunk_' . str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT);
        file_put_contents($chunkFile, $data);

        $this->logger->debug('Video chunk uploaded', [
            'filename' => $filename,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_size' => strlen($data),
            'max_filename_length' => self::MAX_FILENAME_LENGTH,
        ]);

        if ($chunkIndex === $totalChunks - 1) {
            return $this->assembleVideoChunks($filename, $chunkPath, $totalChunks);
        }

        return [
            'status' => 'partial',
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
        ];
    }

    private function assembleVideoChunks(string $filename, string $chunkPath, int $totalChunks): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $finalPath = $this->storageBasePath . '/' . self::STORAGE_PATH . '/' . $this->generateUniqueFilename($filename, $this->storageBasePath . '/' . self::STORAGE_PATH);

        $this->ensureDirectoryExists(dirname($finalPath));

        $outputFile = fopen($finalPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunkPath . '/chunk_' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            if (!file_exists($chunkFile)) {
                throw new MediaUploadException("Missing chunk {$i} for video {$filename}");
            }

            $chunkData = file_get_contents($chunkFile);
            fwrite($outputFile, $chunkData);
        }

        fclose($outputFile);

        foreach (glob($chunkPath . '/chunk_*') as $chunkFile) {
            unlink($chunkFile);
        }
        rmdir($chunkPath);

        $this->logger->info('Chunked video assembled', [
            'filename' => $filename,
            'final_path' => $finalPath,
            'total_chunks' => $totalChunks,
            'storage_path' => self::STORAGE_PATH,
        ]);

        return [
            'status' => 'complete',
            'path' => $finalPath,
            'url' => $this->generateUrl($finalPath),
            'filename' => basename($finalPath),
        ];
    }

    private function extractDuration(string $videoPath): ?int
    {
        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($videoPath)
        );

        $output = shell_exec($command);
        if ($output === null) {
            return null;
        }

        return (int) round((float) trim($output));
    }

    private function extractThumbnails(string $videoPath, string $extension): array
    {
        $thumbnailDir = dirname($videoPath) . '/thumbnails/' . pathinfo($videoPath, PATHINFO_FILENAME);
        $this->ensureDirectoryExists($thumbnailDir);

        $thumbnails = [];

        foreach (self::THUMBNAIL_TIMESTAMPS as $timestamp) {
            $thumbnailPath = $thumbnailDir . '/thumb_' . $timestamp . '.jpg';

            $command = sprintf(
                'ffmpeg -i %s -ss %d -vframes 1 -y %s 2>/dev/null',
                escapeshellarg($videoPath),
                $timestamp,
                escapeshellarg($thumbnailPath)
            );

            shell_exec($command);

            if (file_exists($thumbnailPath)) {
                $thumbnails[] = [
                    'timestamp' => $timestamp,
                    'path' => $thumbnailPath,
                    'url' => $this->generateUrl($thumbnailPath),
                ];
            }
        }

        return $thumbnails;
    }

    private function transcodeVideo(string $videoPath, array $formats): array
    {
        $transcodedDir = dirname($videoPath) . '/transcoded/' . pathinfo($videoPath, PATHINFO_FILENAME);
        $this->ensureDirectoryExists($transcodedDir);

        $results = [];

        foreach ($formats as $format) {
            $outputPath = $transcodedDir . '/video_' . $format . '.mp4';

            $command = sprintf(
                'ffmpeg -i %s -c:v %s -c:a aac -y %s 2>/dev/null',
                escapeshellarg($videoPath),
                escapeshellarg($format),
                escapeshellarg($outputPath)
            );

            shell_exec($command);

            if (file_exists($outputPath)) {
                $results[$format] = [
                    'path' => $outputPath,
                    'url' => $this->generateUrl($outputPath),
                    'size' => filesize($outputPath),
                ];
            }
        }

        return $results;
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaUploadException('Invalid video upload: ' . $file->getErrorMessage());
        }
    }

    private function validateFilename(string $filename): void
    {
        if (strlen($filename) > self::MAX_FILENAME_LENGTH) {
            throw new MediaUploadException(
                sprintf('Filename exceeds maximum length of %d characters', self::MAX_FILENAME_LENGTH)
            );
        }

        if (preg_match('/[^\w\-\.]/', $filename)) {
            throw new MediaUploadException('Filename contains invalid characters');
        }
    }

    private function validateExtension(string $extension): void
    {
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new MediaUploadException(
                sprintf(
                    'File extension "%s" is not allowed. Allowed: %s',
                    $extension,
                    implode(', ', self::ALLOWED_EXTENSIONS)
                )
            );
        }
    }

    private function validateMimeType(string $mimeType): void
    {
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new MediaUploadException(
                sprintf(
                    'MIME type "%s" is not allowed. Allowed: %s',
                    $mimeType,
                    implode(', ', self::ALLOWED_MIME_TYPES)
                )
            );
        }
    }

    private function validateFileSize(int $size): void
    {
        if ($size > self::MAX_FILE_SIZE) {
            throw new MediaUploadException(
                sprintf('File size %d exceeds maximum allowed size of %d bytes', $size, self::MAX_FILE_SIZE)
            );
        }
    }

    private function buildStoragePath(string $destinationPath): string
    {
        $basePath = $this->storageBasePath . '/' . self::STORAGE_PATH;
        if (!empty($destinationPath)) {
            $basePath .= '/' . ltrim($destinationPath, '/');
        }
        return $basePath;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function generateUniqueFilename(string $originalName, string $storagePath): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $uniqueId = substr(md5(uniqid((string) mt_rand(), true)), 0, 12);

        $filename = $baseName . '_' . $uniqueId . '.' . $extension;

        $counter = 1;
        while (file_exists($storagePath . '/' . $filename)) {
            $filename = $baseName . '_' . $uniqueId . '_' . $counter . '.' . $extension;
            $counter++;
        }

        return $filename;
    }

    private function generateUrl(string $path): string
    {
        $relativePath = str_replace($this->storageBasePath, '', $path);
        return '/storage' . $relativePath;
    }
}
