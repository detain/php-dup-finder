<?php

declare(strict_types=1);

namespace App\Content\Submission;

use App\Exceptions\ContentValidationException;

final class UploadedFileInspector
{
    private const ACCEPTABLE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const ACCEPTABLE_SUFFIXES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];

    private const SIZE_THRESHOLD = 10485760;
    private const FLOOR_SIZE = 1;

    public function inspect(array $upload): void
    {
        $this->ensureFilePresent($upload);
        $this->ensureSizeWithinBounds($upload);
        $this->ensureSuffixAllowed($upload);
        $this->ensureMimeTypeAllowed($upload);
        $this->ensureFilenameSecure($upload);
    }

    public function inspectSet(array $uploads): void
    {
        if (count($uploads) > 15) {
            throw new ContentValidationException('Upload set cannot exceed 15 files');
        }

        foreach ($uploads as $key => $upload) {
            try {
                $this->inspect($upload);
            } catch (ContentValidationException $e) {
                throw new ContentValidationException("File '{$key}': " . $e->getMessage());
            }
        }
    }

    public function inspectAvatar(array $upload): void
    {
        $this->ensureFilePresent($upload);
        $this->ensureAvatarSize($upload);
        $this->ensureAvatarSuffix($upload);
        $this->ensureAvatarMime($upload);
        $this->ensureFilenameSecure($upload);
    }

    public function inspectDocument(array $upload): void
    {
        $this->ensureFilePresent($upload);
        $this->ensureDocumentSize($upload);
        $this->ensureDocumentSuffix($upload);
        $this->ensureDocumentMime($upload);
        $this->ensureFilenameSecure($upload);
    }

    public function inspectBanner(array $upload): void
    {
        $this->ensureFilePresent($upload);
        $this->ensureBannerSize($upload);
        $this->ensureBannerSuffix($upload);
        $this->ensureBannerMime($upload);
        $this->ensureFilenameSecure($upload);
    }

    private function ensureFilePresent(array $upload): void
    {
        if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            throw new ContentValidationException('No valid upload received');
        }

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            throw new ContentValidationException($this->getErrorLabel($upload['error']));
        }
    }

    private function ensureSizeWithinBounds(array $upload): void
    {
        $bytes = $upload['size'] ?? 0;

        if ($bytes < self::FLOOR_SIZE) {
            throw new ContentValidationException('File appears empty or corrupted');
        }

        if ($bytes > self::SIZE_THRESHOLD) {
            throw new ContentValidationException(
                'File size ' . number_format($bytes / 1048576, 2) . 'MB exceeds ' .
                (self::SIZE_THRESHOLD / 1048576) . 'MB limit'
            );
        }
    }

    private function ensureSuffixAllowed(array $upload): void
    {
        $suffix = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));

        if (!in_array($suffix, self::ACCEPTABLE_SUFFIXES, true)) {
            throw new ContentValidationException("Suffix '.{$suffix}' is not permitted");
        }
    }

    private function ensureMimeTypeAllowed(array $upload): void
    {
        $actual = $this->detectMime($upload['tmp_name']);
        $suffix = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));

        $expected = $this->expectedMimeForSuffix($suffix);

        if ($actual !== $expected) {
            throw new ContentValidationException(
                "MIME type mismatch: .{$suffix} should be {$expected}, got {$actual}"
            );
        }

        if (!in_array($actual, self::ACCEPTABLE_TYPES, true)) {
            throw new ContentValidationException("MIME type '{$actual}' is not in allowed list");
        }
    }

    private function ensureFilenameSecure(array $upload): void
    {
        $name = $upload['name'];

        if (empty($name)) {
            throw new ContentValidationException('Filename cannot be blank');
        }

        if (strlen($name) > 255) {
            throw new ContentValidationException('Filename exceeds 255 characters');
        }

        if (preg_match('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/', $name)) {
            throw new ContentValidationException('Filename contains control or special characters');
        }

        if (str_starts_with($name, '.')) {
            throw new ContentValidationException('Filenames beginning with dot are not allowed');
        }
    }

    private function ensureAvatarSize(array $upload): void
    {
        $bytes = $upload['size'] ?? 0;

        if ($bytes > 3145728) {
            throw new ContentValidationException('Avatar image must be under 3MB');
        }
    }

    private function ensureAvatarSuffix(array $upload): void
    {
        $suffix = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($suffix, $allowed, true)) {
            throw new ContentValidationException("Avatar cannot use .{$suffix} extension");
        }
    }

    private function ensureAvatarMime(array $upload): void
    {
        $mime = $this->detectMime($upload['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            throw new ContentValidationException("Avatar cannot use {$mime} format");
        }
    }

    private function ensureDocumentSize(array $upload): void
    {
        $bytes = $upload['size'] ?? 0;

        if ($bytes > 52428800) {
            throw new ContentValidationException('Document must be under 50MB');
        }
    }

    private function ensureDocumentSuffix(array $upload): void
    {
        $suffix = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'];

        if (!in_array($suffix, $allowed, true)) {
            throw new ContentValidationException("Document cannot use .{$suffix} extension");
        }
    }

    private function ensureDocumentMime(array $upload): void
    {
        $mime = $this->detectMime($upload['tmp_name']);
        $allowed = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain', 'text/rtf', 'application/vnd.oasis.opendocument.text',
        ];

        if (!in_array($mime, $allowed, true)) {
            throw new ContentValidationException("Document cannot use {$mime} format");
        }
    }

    private function ensureBannerSize(array $upload): void
    {
        $bytes = $upload['size'] ?? 0;

        if ($bytes > 4194304) {
            throw new ContentValidationException('Banner image must be under 4MB');
        }
    }

    private function ensureBannerSuffix(array $upload): void
    {
        $suffix = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($suffix, $allowed, true)) {
            throw new ContentValidationException("Banner cannot use .{$suffix} extension");
        }
    }

    private function ensureBannerMime(array $upload): void
    {
        $mime = $this->detectMime($upload['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            throw new ContentValidationException("Banner cannot use {$mime} format");
        }
    }

    private function detectMime(string $path): string
    {
        $info = new finfo(FILEINFO_MIME_TYPE);
        $result = $info->file($path);

        return $result !== false ? $result : 'application/octet-stream';
    }

    private function expectedMimeForSuffix(string $suffix): string
    {
        return match ($suffix) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }

    private function getErrorLabel(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds browser upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was selected for upload',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error - temp folder missing',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write uploaded file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension',
            default => 'Unknown upload error encountered',
        };
    }
}
