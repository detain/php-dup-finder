<?php

declare(strict_types=1);

namespace App\Uploads;

final class FileTypeIdentifier
{
    private const EXTENSION_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'rtf' => 'application/rtf',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'yaml' => 'application/yaml',
        'yml' => 'application/yaml',
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'bz2' => 'application/x-bzip2',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'flac' => 'audio/flac',
        'aac' => 'audio/aac',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
    ];

    public function identifyByExtension(string $filename): ?string
    {
        $ext = $this->pullExtension($filename);

        if ($ext === null) {
            return null;
        }

        return self::EXTENSION_MAP[strtolower($ext)] ?? null;
    }

    public function identifyByPath(string $path): ?string
    {
        return $this->identifyByExtension(basename($path));
    }

    public function identifyByFileContent(string $filename): ?string
    {
        $fromMagic = $this->identifyViaMagic($filename);

        if ($fromMagic !== null) {
            return $fromMagic;
        }

        return $this->identifyByExtension($filename);
    }

    public function identifyByByteSequence(string $filename, string $binaryData): ?string
    {
        $fromBinary = $this->identifyBinarySignature($binaryData);

        if ($fromBinary !== null) {
            return $fromBinary;
        }

        return $this->identifyByExtension($filename);
    }

    public function categorizeImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    public function categorizeVideo(string $mime): bool
    {
        return str_starts_with($mime, 'video/');
    }

    public function categorizeAudio(string $mime): bool
    {
        return str_starts_with($mime, 'audio/');
    }

    public function categorizeDocument(string $mime): bool
    {
        return str_starts_with($mime, 'text/')
            || str_starts_with($mime, 'application/pdf')
            || str_starts_with($mime, 'application/msword')
            || str_starts_with($mime, 'application/vnd.openxmlformats');
    }

    public function categorizeCompressed(string $mime): bool
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

    public function extensionFor(string $mimeType): ?string
    {
        $reversed = array_flip(self::EXTENSION_MAP);

        return $reversed[$mimeType] ?? $this->inferExtension($mimeType);
    }

    public function typeCategory(string $mimeType): string
    {
        if ($this->categorizeImage($mimeType)) {
            return 'image';
        }

        if ($this->categorizeVideo($mimeType)) {
            return 'video';
        }

        if ($this->categorizeAudio($mimeType)) {
            return 'audio';
        }

        if ($this->categorizeDocument($mimeType)) {
            return 'document';
        }

        if ($this->categorizeCompressed($mimeType)) {
            return 'archive';
        }

        return 'binary';
    }

    public function isAllowedExtension(string $filename, array $permitted): bool
    {
        $ext = $this->pullExtension($filename);

        if ($ext === null) {
            return false;
        }

        return in_array(strtolower($ext), $permitted, true);
    }

    public function isAllowedMimeType(string $mime, array $permitted): bool
    {
        return in_array($mime, $permitted, true);
    }

    private function pullExtension(string $filename): ?string
    {
        $pos = strrpos($filename, '.');

        if ($pos === false || $pos === strlen($filename) - 1) {
            return null;
        }

        return substr($filename, $pos + 1);
    }

    private function identifyViaMagic(string $filename): ?string
    {
        if (!function_exists('finfo_file')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $result = finfo_file($finfo, $filename);
        finfo_close($finfo);

        return $result !== false ? $result : null;
    }

    private function identifyBinarySignature(string $binaryData): ?string
    {
        if (strlen($binaryData) < 4) {
            return null;
        }

        $stream = unpack('C*', $binaryData);

        if ($stream[1] === 0xFF && $stream[2] === 0xD8 && $stream[3] === 0xFF) {
            return 'image/jpeg';
        }

        if ($stream[1] === 0x89 && $stream[2] === 0x50 && $stream[3] === 0x4E && $stream[4] === 0x47) {
            return 'image/png';
        }

        if ($stream[1] === 0x47 && $stream[2] === 0x49 && $stream[3] === 0x46) {
            return 'image/gif';
        }

        if ($stream[1] === 0x25 && $stream[2] === 0x50 && $stream[3] === 0x44 && $stream[4] === 0x46) {
            return 'application/pdf';
        }

        if ($stream[1] === 0x50 && $stream[2] === 0x4B && $stream[3] === 0x03 && $stream[4] === 0x04) {
            return 'application/zip';
        }

        return null;
    }

    private function inferExtension(string $mimeType): ?string
    {
        if (str_ends_with($mimeType, '+xml')) {
            return match (substr($mimeType, 0, -4)) {
                'image/svg' => 'svg',
                'application/math' => 'mml',
                default => null,
            };
        }

        return null;
    }
}
