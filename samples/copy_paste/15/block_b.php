<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Exceptions\MediaValidationException;

final class MediaFileChecker
{
    private const PERMITTED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const PERMITTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'doc', 'docx'];

    private const MAX_UPLOAD_BYTES = 10485760;
    private const MIN_UPLOAD_BYTES = 1;

    public function checkFileValidity(array $uploadedFile): void
    {
        $this->checkFilePresence($uploadedFile);
        $this->checkFileDimensions($uploadedFile);
        $this->checkExtensionValidity($uploadedFile);
        $this->checkMimeConsistency($uploadedFile);
        $this->checkFilenameSanity($uploadedFile);
    }

    public function checkBatchValidity(array $uploadedFiles): void
    {
        if (empty($uploadedFiles)) {
            throw new MediaValidationException('No files provided for upload');
        }

        if (count($uploadedFiles) > 20) {
            throw new MediaValidationException('Batch size limited to 20 files');
        }

        foreach ($uploadedFiles as $index => $file) {
            try {
                $this->checkFileValidity($file);
            } catch (MediaValidationException $e) {
                throw new MediaValidationException("File #{$index}: " . $e->getMessage());
            }
        }
    }

    public function checkProfileImage(array $file): void
    {
        $this->checkFilePresence($file);
        $this->checkProfileImageSize($file);
        $this->checkProfileImageExtension($file);
        $this->checkProfileImageMime($file);
        $this->checkFilenameSanity($file);
    }

    public function checkAttachment(array $file): void
    {
        $this->checkFilePresence($file);
        $this->checkAttachmentSize($file);
        $this->checkAttachmentExtension($file);
        $this->checkAttachmentMime($file);
        $this->checkFilenameSanity($file);
    }

    public function checkThumbnail(array $file): void
    {
        $this->checkFilePresence($file);
        $this->checkThumbnailSize($file);
        $this->checkThumbnailExtension($file);
        $this->checkThumbnailMime($file);
        $this->checkFilenameSanity($file);
    }

    private function checkFilePresence(array $file): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new MediaValidationException('File upload failed - no file received');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new MediaValidationException($this->translateErrorCode($file['error']));
        }
    }

    private function checkFileDimensions(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size < self::MIN_UPLOAD_BYTES) {
            throw new MediaValidationException('File is too small - appears corrupt');
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new MediaValidationException(
                'File size (' . number_format($size / 1048576, 2) . 'MB) exceeds limit of ' .
                (self::MAX_UPLOAD_BYTES / 1048576) . 'MB'
            );
        }
    }

    private function checkExtensionValidity(array $file): void
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, self::PERMITTED_EXTENSIONS, true)) {
            throw new MediaValidationException("Extension '{$ext}' is not permitted");
        }
    }

    private function checkMimeConsistency(array $file): void
    {
        $detected = $this->probeMimeType($file['tmp_name']);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $expectedMimes = $this->mimeMapForExtension($ext);

        if (!in_array($detected, $expectedMimes, true)) {
            throw new MediaValidationException(
                "MIME mismatch for .{$ext}: expected one of [" . implode(', ', $expectedMimes) . "], got {$detected}"
            );
        }

        if (!in_array($detected, self::PERMITTED_MIME_TYPES, true)) {
            throw new MediaValidationException("MIME type '{$detected}' is not permitted");
        }
    }

    private function checkFilenameSanity(array $file): void
    {
        $name = $file['name'];

        if (empty($name) || strlen($name) > 255) {
            throw new MediaValidationException('Invalid filename length');
        }

        if (preg_match('/[<>:"|?*]/', $name)) {
            throw new MediaValidationException('Filename contains forbidden characters');
        }

        if (str_starts_with($name, '.')) {
            throw new MediaValidationException('Dotfiles are not permitted');
        }

        if (str_contains($name, '..')) {
            throw new MediaValidationException('Path traversal detected in filename');
        }
    }

    private function checkProfileImageSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 2097152) {
            throw new MediaValidationException('Profile image cannot exceed 2MB');
        }
    }

    private function checkProfileImageExtension(array $file): void
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowed, true)) {
            throw new MediaValidationException("Extension '{$ext}' not allowed for profile image");
        }
    }

    private function checkProfileImageMime(array $file): void
    {
        $mime = $this->probeMimeType($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            throw new MediaValidationException("MIME '{$mime}' not allowed for profile image");
        }
    }

    private function checkAttachmentSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 26214400) {
            throw new MediaValidationException('Attachment cannot exceed 25MB');
        }
    }

    private function checkAttachmentExtension(array $file): void
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];

        if (!in_array($ext, $allowed, true)) {
            throw new MediaValidationException("Extension '{$ext}' not allowed for attachment");
        }
    }

    private function checkAttachmentMime(array $file): void
    {
        $mime = $this->probeMimeType($file['tmp_name']);
        $allowed = [
            'application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain', 'text/csv', 'application/zip',
        ];

        if (!in_array($mime, $allowed, true)) {
            throw new MediaValidationException("MIME '{$mime}' not allowed for attachment");
        }
    }

    private function checkThumbnailSize(array $file): void
    {
        $size = $file['size'] ?? 0;

        if ($size > 1048576) {
            throw new MediaValidationException('Thumbnail cannot exceed 1MB');
        }
    }

    private function checkThumbnailExtension(array $file): void
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowed, true)) {
            throw new MediaValidationException("Extension '{$ext}' not allowed for thumbnail");
        }
    }

    private function checkThumbnailMime(array $file): void
    {
        $mime = $this->probeMimeType($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime, $allowed, true)) {
            throw new MediaValidationException("MIME '{$mime}' not allowed for thumbnail");
        }
    }

    private function probeMimeType(string $filepath): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $result = $finfo->file($filepath);

        return $result !== false ? $result : 'application/octet-stream';
    }

    private function mimeMapForExtension(string $ext): array
    {
        return match ($ext) {
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'bmp' => ['image/bmp'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            default => ['application/octet-stream'],
        };
    }

    private function translateErrorCode(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form-specified limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially transferred',
            UPLOAD_ERR_NO_FILE => 'No file data was received',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration - no temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by PHP extension',
            default => 'Unrecognized upload error',
        };
    }
}
