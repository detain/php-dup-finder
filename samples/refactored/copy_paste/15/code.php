<?php

declare(strict_types=1);

namespace App\Services\Upload;

use finfo;

final class FileValidationConfig
{
    public readonly array $allowedMimes;
    public readonly array $allowedExtensions;
    public readonly int $maxSizeBytes;
    public readonly int $minSizeBytes;

    public function __construct(
        array $allowedMimes,
        array $allowedExtensions,
        int $maxSizeBytes = 10485760,
        int $minSizeBytes = 1
    ) {
        $this->allowedMimes = $allowedMimes;
        $this->allowedExtensions = $allowedExtensions;
        $this->maxSizeBytes = $maxSizeBytes;
        $this->minSizeBytes = $minSizeBytes;
    }
}

final class FileValidationService
{
    private FileValidationConfig $config;

    public function __construct(FileValidationConfig $config)
    {
        $this->config = $config;
    }

    public function validate(array $file): void
    {
        $this->ensurePresent($file);
        $this->ensureSizeWithinBounds($file);
        $this->ensureExtensionAllowed($file);
        $this->ensureMimeMatchesExtension($file);
        $this->ensureFilenameValid($file);
    }

    private function ensurePresent(array $file): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('No valid file upload received');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->getUploadError($file['error']));
        }
    }

    private function ensureSizeWithinBounds(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size < $this->config->minSizeBytes) {
            throw new \InvalidArgumentException('File is too small or empty');
        }

        if ($size > $this->config->maxSizeBytes) {
            throw new \InvalidArgumentException(
                'File size exceeds ' . number_format($this->config->maxSizeBytes / 1048576, 1) . 'MB limit'
            );
        }
    }

    private function ensureExtensionAllowed(array $file): void
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $this->config->allowedExtensions, true)) {
            throw new \InvalidArgumentException("Extension '.{$ext}' is not permitted");
        }
    }

    private function ensureMimeMatchesExtension(array $file): void
    {
        $detected = $this->detectMime($file['tmp_name']);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($detected, $this->config->allowedMimes, true)) {
            throw new \InvalidArgumentException("MIME type '{$detected}' is not permitted");
        }
    }

    private function ensureFilenameValid(array $file): void
    {
        $name = $file['name'];

        if (empty($name) || strlen($name) > 255) {
            throw new \InvalidArgumentException('Invalid filename');
        }

        if (preg_match('/[<>:"|?*\x00-\x1f]/', $name)) {
            throw new \InvalidArgumentException('Filename contains invalid characters');
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($path) ?: 'application/octet-stream';
    }

    private function getUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
            UPLOAD_ERR_PARTIAL => 'File was partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            default => 'Upload error code: ' . $code,
        };
    }
}
