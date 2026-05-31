<?php

declare(strict_types=1);

namespace App\Services\Upload;

use Illuminate\Http\UploadedFile;
use App\Exceptions\UploadException;

abstract class AbstractUploadService
{
    protected const MAX_FILE_SIZE = 10485760;
    protected const ALLOWED_EXTENSIONS = [];
    protected const ALLOWED_MIME_TYPES = [];
    protected const STORAGE_PATH = 'uploads/default';
    protected const UPLOAD_TIMEOUT = 30;
    protected const CHUNK_SIZE = 8192;
    protected const MAX_FILENAME_LENGTH = 255;

    protected string $storageBasePath;

    abstract protected function getMaxFileSize(): int;
    abstract protected function getAllowedExtensions(): array;
    abstract protected function getAllowedMimeTypes(): array;
    abstract protected function getStoragePath(): string;
    abstract protected function getUploadExceptionClass(): string;

    public function upload(UploadedFile $file, array $options = []): array
    {
        $this->validateFile($file);
        $this->validateFilename($file->getClientOriginalName());
        $this->validateExtension(strtolower($file->getClientOriginalExtension()));
        $this->validateMimeType($file->getMimeType());
        $this->validateFileSize($file->getSize());

        $storagePath = $this->buildStoragePath($options['path'] ?? '');
        $uniqueFilename = $this->generateUniqueFilename($file->getClientOriginalName(), $storagePath);

        $file->move($storagePath, $uniqueFilename);

        return $this->buildUploadResult($file, $storagePath, $uniqueFilename, $options);
    }

    protected function validateFilename(string $filename): void
    {
        if (strlen($filename) > self::MAX_FILENAME_LENGTH) {
            $exceptionClass = $this->getUploadExceptionClass();
            throw new $exceptionClass("Filename exceeds max length");
        }
    }

    protected function validateExtension(string $extension): void
    {
        if (!in_array($extension, $this->getAllowedExtensions(), true)) {
            $exceptionClass = $this->getUploadExceptionClass();
            throw new $exceptionClass("Extension not allowed");
        }
    }

    protected function validateFileSize(int $size): void
    {
        if ($size > $this->getMaxFileSize()) {
            $exceptionClass = $this->getUploadExceptionClass();
            throw new $exceptionClass("File size exceeds limit");
        }
    }

    protected function buildStoragePath(string $destinationPath): string
    {
        $basePath = $this->storageBasePath . '/' . $this->getStoragePath();
        if (!empty($destinationPath)) {
            $basePath .= '/' . ltrim($destinationPath, '/');
        }
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        return $basePath;
    }

    protected function generateUniqueFilename(string $originalName, string $storagePath): string
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

    protected function buildUploadResult(UploadedFile $file, string $storagePath, string $uniqueFilename, array $options): array
    {
        return [
            'path' => $storagePath . '/' . $uniqueFilename,
            'url' => '/storage/' . self::STORAGE_PATH . '/' . $uniqueFilename,
            'filename' => $uniqueFilename,
            'size' => $file->getSize(),
            'extension' => $file->getClientOriginalExtension(),
        ];
    }
}
