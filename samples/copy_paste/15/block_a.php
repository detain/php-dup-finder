<?php

declare(strict_types=1);

namespace App\Uploads\Validation;

use App\Exceptions\FileValidationException;
use finfo;

final class DocumentUploadValidator
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv',
    ];

    private const MAX_FILE_SIZE = 10485760;
    private const MIN_FILE_SIZE = 1;

    public function validateUploadedFile(array $file): void
    {
        $this->validateFileExists($file);
        $this->validateFileSize($file);
        $this->validateFileExtension($file);
        $this->validateMimeType($file);
        $this->validateFileName($file);
    }

    public function validateMultipleFiles(array $files): void
    {
        if (count($files) > 10) {
            throw new FileValidationException('Cannot upload more than 10 files at once');
        }

        foreach ($files as $index => $file) {
            try {
                $this->validateUploadedFile($file);
            } catch (FileValidationException $e) {
                throw new FileValidationException(
                    "File {$index}: " . $e->getMessage()
                );
            }
        }
    }

    public function validateImageFile(array $file): void
    {
        $this->validateFileExists($file);
        $this->validateImageSize($file);
        $this->validateImageExtension($file);
        $this->validateImageMimeType($file);
        $this->validateFileName($file);
    }

    public function validateVideoFile(array $file): void
    {
        $this->validateFileExists($file);
        $this->validateVideoSize($file);
        $this->validateVideoExtension($file);
        $this->validateVideoMimeType($file);
        $this->validateFileName($file);
    }

    public function validateAudioFile(array $file): void
    {
        $this->validateFileExists($file);
        $this->validateAudioSize($file);
        $this->validateAudioExtension($file);
        $this->validateAudioMimeType($file);
        $this->validateFileName($file);
    }

    public function validateArchiveFile(array $file): void
    {
        $this->validateFileExists($file);
        $this->validateArchiveSize($file);
        $this->validateArchiveExtension($file);
        $this->validateArchiveMimeType($file);
        $this->validateFileName($file);
    }

    private function validateFileExists(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new FileValidationException('No file was uploaded');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new FileValidationException($this->getUploadErrorMessage($file['error']));
        }
    }

    private function validateFileSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size < self::MIN_FILE_SIZE) {
            throw new FileValidationException('File is empty');
        }

        if ($size > self::MAX_FILE_SIZE) {
            throw new FileValidationException(
                'File size exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1048576) . 'MB'
            );
        }
    }

    private function validateFileExtension(array $file): void
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new FileValidationException(
                'File extension "' . $extension . '" is not allowed'
            );
        }
    }

    private function validateMimeType(array $file): void
    {
        $detectedMime = $this->detectMimeType($file['tmp_name']);
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $extensionMimeMap = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
        ];

        $expectedMime = $extensionMimeMap[$extension] ?? null;

        if ($expectedMime !== null && $detectedMime !== $expectedMime) {
            throw new FileValidationException(
                'File MIME type mismatch. Expected: ' . $expectedMime . ', Detected: ' . $detectedMime
            );
        }

        if (!in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new FileValidationException(
                'File MIME type "' . $detectedMime . '" is not allowed'
            );
        }
    }

    private function validateFileName(array $file): void
    {
        $filename = $file['name'];

        if (empty($filename)) {
            throw new FileValidationException('Filename is required');
        }

        if (strlen($filename) > 255) {
            throw new FileValidationException('Filename is too long (max 255 characters)');
        }

        if (preg_match('/[\/\\\*\?"<>\|]/', $filename)) {
            throw new FileValidationException('Filename contains invalid characters');
        }

        if (str_starts_with($filename, '.')) {
            throw new FileValidationException('Hidden files are not allowed');
        }
    }

    private function validateImageSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 5242880) {
            throw new FileValidationException('Image size cannot exceed 5MB');
        }
    }

    private function validateImageExtension(array $file): void
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        if (!in_array($extension, $allowed, true)) {
            throw new FileValidationException('Image extension "' . $extension . '" is not allowed');
        }
    }

    private function validateImageMimeType(array $file): void
    {
        $mime = $this->detectMimeType($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

        if (!in_array($mime, $allowed, true)) {
            throw new FileValidationException('Image MIME type "' . $mime . '" is not allowed');
        }
    }

    private function validateVideoSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 104857600) {
            throw new FileValidationException('Video size cannot exceed 100MB');
        }
    }

    private function validateVideoExtension(array $file): void
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'];

        if (!in_array($extension, $allowed, true)) {
            throw new FileValidationException('Video extension "' . $extension . '" is not allowed');
        }
    }

    private function validateVideoMimeType(array $file): void
    {
        $mime = $this->detectMimeType($file['tmp_name']);
        $allowed = [
            'video/mp4', 'video/avi', 'video/x-msvideo', 'video/quicktime',
            'video/x-ms-wmv', 'video/x-flv', 'video/webm', 'video/x-matroska',
        ];

        if (!in_array($mime, $allowed, true)) {
            throw new FileValidationException('Video MIME type "' . $mime . '" is not allowed');
        }
    }

    private function validateAudioSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 20971520) {
            throw new FileValidationException('Audio size cannot exceed 20MB');
        }
    }

    private function validateAudioExtension(array $file): void
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'];

        if (!in_array($extension, $allowed, true)) {
            throw new FileValidationException('Audio extension "' . $extension . '" is not allowed');
        }
    }

    private function validateAudioMimeType(array $file): void
    {
        $mime = $this->detectMimeType($file['tmp_name']);
        $allowed = [
            'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/flac',
            'audio/aac', 'audio/mp4', 'audio/x-ms-wma',
        ];

        if (!in_array($mime, $allowed, true)) {
            throw new FileValidationException('Audio MIME type "' . $mime . '" is not allowed');
        }
    }

    private function validateArchiveSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 52428800) {
            throw new FileValidationException('Archive size cannot exceed 50MB');
        }
    }

    private function validateArchiveExtension(array $file): void
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];

        if (!in_array($extension, $allowed, true)) {
            throw new FileValidationException('Archive extension "' . $extension . '" is not allowed');
        }
    }

    private function validateArchiveMimeType(array $file): void
    {
        $mime = $this->detectMimeType($file['tmp_name']);
        $allowed = [
            'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
            'application/x-tar', 'application/gzip', 'application/x-bzip2',
        ];

        if (!in_array($mime, $allowed, true)) {
            throw new FileValidationException('Archive MIME type "' . $mime . '" is not allowed');
        }
    }

    private function detectMimeType(string $filepath): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filepath);

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error',
        };
    }
}
