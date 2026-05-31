<?php

declare(strict_types=1);

namespace App\Content;

final class ContentTypeResolver
{
    private const MIME_LOOKUP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'rss' => 'application/rss+xml',
        'atom' => 'application/atom+xml',
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'gzip' => 'application/gzip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
    ];

    public function resolveFromExtension(string $filename): ?string
    {
        $ext = $this->getFileExtension($filename);

        if ($ext === null) {
            return null;
        }

        return self::MIME_LOOKUP[strtolower($ext)] ?? null;
    }

    public function resolveFromPath(string $path): ?string
    {
        return $this->resolveFromExtension(basename($path));
    }

    public function resolveFromSystem(string $filename): ?string
    {
        $systemDetected = $this->detectSystem($filename);

        if ($systemDetected !== null) {
            return $systemDetected;
        }

        return $this->resolveFromExtension($filename);
    }

    public function resolveFromBinary(string $filename, string $rawBytes): ?string
    {
        $binarySignature = $this->parseSignature($rawBytes);

        if ($binarySignature !== null) {
            return $binarySignature;
        }

        return $this->resolveFromExtension($filename);
    }

    public function isImageType(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    public function isVideoType(string $mime): bool
    {
        return str_starts_with($mime, 'video/');
    }

    public function isAudioType(string $mime): bool
    {
        return str_starts_with($mime, 'audio/');
    }

    public function isReadableText(string $mime): bool
    {
        return str_starts_with($mime, 'text/');
    }

    public function isOfficeDocument(string $mime): bool
    {
        return str_contains($mime, 'wordprocessingml')
            || str_contains($mime, 'spreadsheetml')
            || str_contains($mime, 'presentationml')
            || $mime === 'application/msword'
            || $mime === 'application/vnd.ms-excel';
    }

    public function isPackaged(string $mime): bool
    {
        return in_array($mime, [
            'application/zip',
            'application/x-tar',
            'application/gzip',
            'application/x-bzip2',
            'application/vnd.rar',
            'application/x-7z-compressed',
        ], true);
    }

    public function mapMimeToExtension(string $mime): ?string
    {
        $inverted = array_flip(self::MIME_LOOKUP);

        return $inverted[$mime] ?? $this->deriveExtension($mime);
    }

    public function determineGroup(string $mime): string
    {
        if ($this->isImageType($mime)) {
            return 'image';
        }

        if ($this->isVideoType($mime)) {
            return 'video';
        }

        if ($this->isAudioType($mime)) {
            return 'audio';
        }

        if ($this->isReadableText($mime)) {
            return 'text';
        }

        if ($this->isOfficeDocument($mime)) {
            return 'document';
        }

        if ($this->isPackaged($mime)) {
            return 'archive';
        }

        return 'application';
    }

    public function checkExtensionAllowed(string $filename, array $allowedList): bool
    {
        $ext = $this->getFileExtension($filename);

        if ($ext === null) {
            return false;
        }

        return in_array(strtolower($ext), $allowedList, true);
    }

    public function checkMimeAllowed(string $mime, array $allowedList): bool
    {
        return in_array($mime, $allowedList, true);
    }

    private function getFileExtension(string $filename): ?string
    {
        $idx = strrpos($filename, '.');

        if ($idx === false || $idx === strlen($filename) - 1) {
            return null;
        }

        return substr($filename, $idx + 1);
    }

    private function detectSystem(string $filename): ?string
    {
        if (!function_exists('finfo_file')) {
            return null;
        }

        $handle = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($handle, $filename);
        finfo_close($handle);

        return $detected !== false ? $detected : null;
    }

    private function parseSignature(string $bytes): ?string
    {
        if (strlen($bytes) < 4) {
            return null;
        }

        $data = unpack('C*', $bytes);

        if ($data[1] === 0xFF && $data[2] === 0xD8 && $data[3] === 0xFF) {
            return 'image/jpeg';
        }

        if ($data[1] === 0x89 && $data[2] === 0x50 && $data[3] === 0x4E && $data[4] === 0x47) {
            return 'image/png';
        }

        if ($data[1] === 0x47 && $data[2] === 0x49 && $data[3] === 0x46) {
            return 'image/gif';
        }

        if ($data[1] === 0x42 && $data[2] === 0x4D) {
            return 'image/bmp';
        }

        if ($data[1] === 0x25 && $data[2] === 0x50 && $data[3] === 0x44 && $data[4] === 0x46) {
            return 'application/pdf';
        }

        if ($data[1] === 0x50 && $data[2] === 0x4B && $data[3] === 0x03 && $data[4] === 0x04) {
            return 'application/zip';
        }

        return null;
    }

    private function deriveExtension(string $mimeType): ?string
    {
        if (str_ends_with($mimeType, '+xml')) {
            return match (substr($mimeType, 0, -4)) {
                'image/svg' => 'svg',
                'application/xslt' => 'xsl',
                'application/xhtml' => 'xhtml',
                default => null,
            };
        }

        return null;
    }
}
