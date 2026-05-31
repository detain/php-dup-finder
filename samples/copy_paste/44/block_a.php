<?php

declare(strict_types=1);

namespace App\Media;

final class MimeTypeDetector
{
    private const COMMON_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
        'webm' => 'video/webm',
    ];

    public function detectFromExtension(string $filename): ?string
    {
        $extension = $this->extractExtension($filename);

        if ($extension === null) {
            return null;
        }

        return self::COMMON_TYPES[strtolower($extension)] ?? null;
    }

    public function detectFromPath(string $path): ?string
    {
        return $this->detectFromExtension(basename($path));
    }

    public function detectFromFile(string $filename): ?string
    {
        $detected = $this->detectFromMimeContent($filename);

        if ($detected !== null) {
            return $detected;
        }

        return $this->detectFromExtension($filename);
    }

    public function detectFromBytes(string $filename, string $data): ?string
    {
        $signature = $this->identifySignature($data);

        if ($signature !== null) {
            return $signature;
        }

        return $this->detectFromExtension($filename);
    }

    public function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    public function isVideo(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }

    public function isAudio(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/');
    }

    public function isDocument(string $mimeType): bool
    {
        $documentTypes = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.',
            'application/pdf',
            'application/rtf',
            'text/',
        ];

        foreach ($documentTypes as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function isArchive(string $mimeType): bool
    {
        $archiveTypes = [
            'application/zip',
            'application/x-tar',
            'application/gzip',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/x-bzip2',
        ];

        return in_array($mimeType, $archiveTypes, true);
    }

    public function isText(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/');
    }

    public function getExtension(string $mimeType): ?string
    {
        $flipped = array_flip(self::COMMON_TYPES);

        return $flipped[$mimeType] ?? $this->getExtensionFromGeneric($mimeType);
    }

    public function getCategory(string $mimeType): string
    {
        if ($this->isImage($mimeType)) {
            return 'image';
        }

        if ($this->isVideo($mimeType)) {
            return 'video';
        }

        if ($this->isAudio($mimeType)) {
            return 'audio';
        }

        if ($this->isDocument($mimeType)) {
            return 'document';
        }

        if ($this->isArchive($mimeType)) {
            return 'archive';
        }

        if ($this->isText($mimeType)) {
            return 'text';
        }

        return 'application';
    }

    public function validateExtension(string $filename, array $allowedExtensions): bool
    {
        $extension = $this->extractExtension($filename);

        if ($extension === null) {
            return false;
        }

        return in_array(strtolower($extension), $allowedExtensions, true);
    }

    public function validateMimeType(string $mimeType, array $allowedTypes): bool
    {
        return in_array($mimeType, $allowedTypes, true);
    }

    private function extractExtension(string $filename): ?string
    {
        $lastDot = strrpos($filename, '.');

        if ($lastDot === false || $lastDot === strlen($filename) - 1) {
            return null;
        }

        return substr($filename, $lastDot + 1);
    }

    private function detectFromMimeContent(string $filename): ?string
    {
        if (!function_exists('mime_content_type')) {
            return null;
        }

        $detected = mime_content_type($filename);

        return $detected !== false ? $detected : null;
    }

    private function identifySignature(string $data): ?string
    {
        if (strlen($data) < 4) {
            return null;
        }

        $bytes = unpack('C*', $data);

        if ($bytes[1] === 0xFF && $bytes[2] === 0xD8 && $bytes[3] === 0xFF) {
            return 'image/jpeg';
        }

        if ($bytes[1] === 0x89 && $bytes[2] === 0x50 && $bytes[3] === 0x4E && $bytes[4] === 0x47) {
            return 'image/png';
        }

        if ($bytes[1] === 0x47 && $bytes[2] === 0x49 && $bytes[3] === 0x46) {
            return 'image/gif';
        }

        if ($bytes[1] === 0x42 && $bytes[2] === 0x4D) {
            return 'image/bmp';
        }

        if ($bytes[1] === 0x25 && $bytes[2] === 0x50 && $bytes[3] === 0x44 && $bytes[4] === 0x46) {
            return 'application/pdf';
        }

        return null;
    }

    private function getExtensionFromGeneric(string $mimeType): ?string
    {
        if (str_ends_with($mimeType, '+xml')) {
            $base = substr($mimeType, 0, -4);

            return match ($base) {
                'application/svg' => 'svg',
                'application/math' => 'mml',
                'application/xhtml' => 'xhtml',
                default => null,
            };
        }

        return null;
    }
}
