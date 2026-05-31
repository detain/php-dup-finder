<?php

declare(strict_types=1);

namespace App\Services\ImageUpload;

use App\Exceptions\ImageUploadException;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use Intervention\Image\ImageManager;

final class ImageUploadService
{
    private const MAX_FILE_SIZE = 5242880;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    private const STORAGE_PATH = 'uploads/images';
    private const UPLOAD_TIMEOUT = 30;
    private const CHUNK_SIZE = 8192;
    private const MAX_FILENAME_LENGTH = 255;
    private const THUMBNAIL_SIZE = 200;
    private const THUMBNAIL_QUALITY = 80;

    private string $storageBasePath;
    private ImageManager $imageManager;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $storageBasePath = '/var/www/uploads',
        ?ImageManager $imageManager = null
    ) {
        $this->storageBasePath = rtrim($storageBasePath, '/');
        $this->imageManager = $imageManager ?? new ImageManager(['driver' => 'gd']);
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
            ];

            if ($options['generate_thumbnail'] ?? false) {
                $thumbnailResult = $this->generateThumbnail($fullPath, $extension);
                $result = array_merge($result, $thumbnailResult);
            }

            if ($options['generate_medium'] ?? false) {
                $mediumResult = $this->generateMediumSize($fullPath, $extension);
                $result = array_merge($result, $mediumResult);
            }

            $this->logger->info('Image uploaded successfully', [
                'original_name' => $originalName,
                'stored_as' => $uniqueFilename,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'path' => $fullPath,
                'max_size' => self::MAX_FILE_SIZE,
                'allowed_extensions' => self::ALLOWED_EXTENSIONS,
                'thumbnail_size' => self::THUMBNAIL_SIZE,
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Image upload failed', [
                'error' => $e->getMessage(),
                'original_name' => $originalName,
                'size' => $fileSize,
                'storage_path' => $storagePath,
                'timeout' => self::UPLOAD_TIMEOUT,
                'chunk_size' => self::CHUNK_SIZE,
            ]);
            throw new ImageUploadException('Failed to upload image: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uploadChunked(string $filename, string $data, int $chunkIndex, int $totalChunks): array
    {
        $this->validateFilename($filename);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->validateExtension($extension);

        $chunkPath = $this->storageBasePath . '/temp/image_chunks/' . sha1($filename);
        $this->ensureDirectoryExists($chunkPath);

        $chunkFile = $chunkPath . '/chunk_' . str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT);
        file_put_contents($chunkFile, $data);

        $this->logger->debug('Image chunk uploaded', [
            'filename' => $filename,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_size' => strlen($data),
            'max_filename_length' => self::MAX_FILENAME_LENGTH,
        ]);

        if ($chunkIndex === $totalChunks - 1) {
            return $this->assembleImageChunks($filename, $chunkPath, $totalChunks);
        }

        return [
            'status' => 'partial',
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
        ];
    }

    private function assembleImageChunks(string $filename, string $chunkPath, int $totalChunks): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $finalPath = $this->storageBasePath . '/' . self::STORAGE_PATH . '/' . $this->generateUniqueFilename($filename, $this->storageBasePath . '/' . self::STORAGE_PATH);

        $this->ensureDirectoryExists(dirname($finalPath));

        $outputFile = fopen($finalPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunkPath . '/chunk_' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            if (!file_exists($chunkFile)) {
                throw new ImageUploadException("Missing chunk {$i} for image {$filename}");
            }

            $chunkData = file_get_contents($chunkFile);
            fwrite($outputFile, $chunkData);
        }

        fclose($outputFile);

        foreach (glob($chunkPath . '/chunk_*') as $chunkFile) {
            unlink($chunkFile);
        }
        rmdir($chunkPath);

        $this->logger->info('Chunked image assembled', [
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

    private function generateThumbnail(string $imagePath, string $extension): array
    {
        $thumbnailPath = dirname($imagePath) . '/thumbnails/' . pathinfo($imagePath, PATHINFO_FILENAME) . '_thumb.' . $extension;
        $this->ensureDirectoryExists(dirname($thumbnailPath));

        $img = $this->imageManager->make($imagePath);
        $img->widen(self::THUMBNAIL_SIZE, function ($constraint) {
            $constraint->aspectRatio();
        })->save($thumbnailPath, self::THUMBNAIL_QUALITY);

        return [
            'thumbnail_path' => $thumbnailPath,
            'thumbnail_url' => $this->generateUrl($thumbnailPath),
        ];
    }

    private function generateMediumSize(string $imagePath, string $extension): array
    {
        $mediumPath = dirname($imagePath) . '/medium/' . pathinfo($imagePath, PATHINFO_FILENAME) . '_medium.' . $extension;
        $this->ensureDirectoryExists(dirname($mediumPath));

        $img = $this->imageManager->make($imagePath);
        $img->widen(800, function ($constraint) {
            $constraint->aspectRatio();
        })->save($mediumPath, 85);

        return [
            'medium_path' => $mediumPath,
            'medium_url' => $this->generateUrl($mediumPath),
        ];
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ImageUploadException('Invalid image upload: ' . $file->getErrorMessage());
        }
    }

    private function validateFilename(string $filename): void
    {
        if (strlen($filename) > self::MAX_FILENAME_LENGTH) {
            throw new ImageUploadException(
                sprintf('Filename exceeds maximum length of %d characters', self::MAX_FILENAME_LENGTH)
            );
        }

        if (preg_match('/[^\w\-\.]/', $filename)) {
            throw new ImageUploadException('Filename contains invalid characters');
        }
    }

    private function validateExtension(string $extension): void
    {
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ImageUploadException(
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
            throw new ImageUploadException(
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
            throw new ImageUploadException(
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
