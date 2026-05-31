<?php

declare(strict_types=1);

namespace App\Services\FileUpload;

use App\Exceptions\FileUploadException;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;

final class DocumentUploadService
{
    private const MAX_FILE_SIZE = 10485760;
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'txt', 'rtf'];
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/rtf',
    ];
    private const STORAGE_PATH = 'uploads/documents';
    private const UPLOAD_TIMEOUT = 30;
    private const CHUNK_SIZE = 8192;
    private const MAX_FILENAME_LENGTH = 255;

    private string $storageBasePath;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $storageBasePath = '/var/www/uploads'
    ) {
        $this->storageBasePath = rtrim($storageBasePath, '/');
    }

    public function upload(UploadedFile $file, string $destinationPath = ''): array
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

        $storagePath = $this->buildStoragePath($destinationPath);
        $uniqueFilename = $this->generateUniqueFilename($originalName, $storagePath);
        $fullPath = $storagePath . '/' . $uniqueFilename;

        $this->ensureDirectoryExists($storagePath);

        try {
            $file->move($storagePath, $uniqueFilename);

            $this->logger->info('File uploaded successfully', [
                'original_name' => $originalName,
                'stored_as' => $uniqueFilename,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'path' => $fullPath,
                'max_size' => self::MAX_FILE_SIZE,
                'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            ]);

            return [
                'path' => $fullPath,
                'url' => $this->generateUrl($fullPath),
                'filename' => $uniqueFilename,
                'original_name' => $originalName,
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'extension' => $extension,
            ];
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'original_name' => $originalName,
                'size' => $fileSize,
                'storage_path' => $storagePath,
                'timeout' => self::UPLOAD_TIMEOUT,
                'chunk_size' => self::CHUNK_SIZE,
            ]);
            throw new FileUploadException('Failed to upload file: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uploadChunked(string $filename, string $data, int $chunkIndex, int $totalChunks): array
    {
        $this->validateFilename($filename);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->validateExtension($extension);

        $chunkPath = $this->storageBasePath . '/temp/chunks/' . sha1($filename);
        $this->ensureDirectoryExists($chunkPath);

        $chunkFile = $chunkPath . '/chunk_' . str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT);
        file_put_contents($chunkFile, $data);

        $this->logger->debug('Chunk uploaded', [
            'filename' => $filename,
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
            'chunk_size' => strlen($data),
            'max_filename_length' => self::MAX_FILENAME_LENGTH,
        ]);

        if ($chunkIndex === $totalChunks - 1) {
            return $this->assembleChunks($filename, $chunkPath, $totalChunks);
        }

        return [
            'status' => 'partial',
            'chunk_index' => $chunkIndex,
            'total_chunks' => $totalChunks,
        ];
    }

    private function assembleChunks(string $filename, string $chunkPath, int $totalChunks): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $finalPath = $this->storageBasePath . '/' . self::STORAGE_PATH . '/' . $this->generateUniqueFilename($filename, $this->storageBasePath . '/' . self::STORAGE_PATH);

        $this->ensureDirectoryExists(dirname($finalPath));

        $outputFile = fopen($finalPath, 'wb');

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $chunkPath . '/chunk_' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            if (!file_exists($chunkFile)) {
                throw new FileUploadException("Missing chunk {$i} for file {$filename}");
            }

            $chunkData = file_get_contents($chunkFile);
            fwrite($outputFile, $chunkData);
        }

        fclose($outputFile);

        foreach (glob($chunkPath . '/chunk_*') as $chunkFile) {
            unlink($chunkFile);
        }
        rmdir($chunkPath);

        $this->logger->info('Chunked file assembled', [
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

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new FileUploadException('Invalid file upload: ' . $file->getErrorMessage());
        }
    }

    private function validateFilename(string $filename): void
    {
        if (strlen($filename) > self::MAX_FILENAME_LENGTH) {
            throw new FileUploadException(
                sprintf('Filename exceeds maximum length of %d characters', self::MAX_FILENAME_LENGTH)
            );
        }

        if (preg_match('/[^\w\-\.]/', $filename)) {
            throw new FileUploadException('Filename contains invalid characters');
        }
    }

    private function validateExtension(string $extension): void
    {
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new FileUploadException(
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
            throw new FileUploadException(
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
            throw new FileUploadException(
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
