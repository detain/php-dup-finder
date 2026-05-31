<?php

declare(strict_types=1);

namespace App\Helpers;

class FileUploadHelper
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
    ];

    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'txt', 'csv',
    ];

    public static function validateUpload(array $file, array $options = []): array
    {
        $errors = [];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = self::getUploadErrorMessage($file['error']);
            return $errors;
        }

        // Check file size
        $maxSize = $options['max_size'] ?? self::MAX_FILE_SIZE;
        if ($file['size'] > $maxSize) {
            $errors[] = sprintf('File size exceeds maximum allowed size of %d MB', $maxSize / 1024 / 1024);
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimeTypes = $options['allowed_mime_types'] ?? self::ALLOWED_MIME_TYPES;
        if (!in_array($detectedMimeType, $allowedMimeTypes)) {
            $errors[] = sprintf('File type "%s" is not allowed', $detectedMimeType);
        }

        // Check extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = $options['allowed_extensions'] ?? self::ALLOWED_EXTENSIONS;
        if (!in_array($extension, $allowedExtensions)) {
            $errors[] = sprintf('File extension ".%s" is not allowed', $extension);
        }

        // Check MIME matches extension
        if (!self::mimeMatchesExtension($detectedMimeType, $extension)) {
            $errors[] = 'File extension does not match file content';
        }

        // Additional image validation
        if (in_array($detectedMimeType, ['image/jpeg', 'image/png', 'image/gif'])) {
            $imageErrors = self::validateImage($file['tmp_name'], $options);
            $errors = array_merge($errors, $imageErrors);
        }

        return $errors;
    }

    private static function validateImage(string $tmpPath, array $options): array
    {
        $errors = [];

        // Get image dimensions
        $imageInfo = getimagesize($tmpPath);

        if ($imageInfo === false) {
            $errors[] = 'Unable to process image file';
            return $errors;
        }

        // Check minimum dimensions
        $minWidth = $options['min_width'] ?? 0;
        $minHeight = $options['min_height'] ?? 0;

        if ($minWidth > 0 && $imageInfo[0] < $minWidth) {
            $errors[] = sprintf('Image width must be at least %d pixels', $minWidth);
        }

        if ($minHeight > 0 && $imageInfo[1] < $minHeight) {
            $errors[] = sprintf('Image height must be at least %d pixels', $minHeight);
        }

        // Check maximum dimensions
        $maxWidth = $options['max_width'] ?? 0;
        $maxHeight = $options['max_height'] ?? 0;

        if ($maxWidth > 0 && $imageInfo[0] > $maxWidth) {
            $errors[] = sprintf('Image width must not exceed %d pixels', $maxWidth);
        }

        if ($maxHeight > 0 && $imageInfo[1] > $maxHeight) {
            $errors[] = sprintf('Image height must not exceed %d pixels', $maxHeight);
        }

        return $errors;
    }

    private static function mimeMatchesExtension(string $mimeType, string $extension): bool
    {
        $mimeToExt = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'text/plain' => ['txt'],
            'text/csv' => ['csv'],
        ];

        $allowedExts = $mimeToExt[$mimeType] ?? [];
        return in_array($extension, $allowedExts);
    }

    public static function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the MAX_FILE_SIZE directive specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error',
        };
    }

    public static function generateSecureFilename(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Generate random filename
        $randomBytes = random_bytes(16);
        $randomName = bin2hex($randomBytes);

        // Keep original extension
        $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);

        return $randomName . '.' . $safeExtension;
    }

    public static function getFileTypeCategory(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        if ($mimeType === 'application/pdf' || str_contains($mimeType, 'document')) {
            return 'document';
        }

        if (str_contains($mimeType, 'spreadsheet') || str_contains($mimeType, 'excel')) {
            return 'spreadsheet';
        }

        return 'other';
    }

    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        $size = (float) $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
