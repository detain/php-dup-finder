<?php

namespace App\Services\Media;

final class MimeTypeConfig
{
    public readonly bool $useMagicBytes;
    public readonly bool $strictExtension;

    public function __construct(bool $useMagicBytes = true, bool $strictExtension = false)
    {
        $this->useMagicBytes = $useMagicBytes;
        $this->strictExtension = $strictExtension;
    }
}

final class MimeTypeService
{
    private MimeTypeConfig $config;

    private const MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
    ];

    private const SIGNATURES = [
        'image/jpeg' => [0xFF, 0xD8, 0xFF],
        'image/png' => [0x89, 0x50, 0x4E, 0x47],
        'image/gif' => [0x47, 0x49, 0x46],
        'application/pdf' => [0x25, 0x50, 0x44, 0x46],
        'application/zip' => [0x50, 0x4B, 0x03, 0x04],
    ];

    public function __construct(MimeTypeConfig $config)
    {
        $this->config = $config;
    }

    public function detect(string $filename, ?string $binaryData = null): ?string
    {
        $extension = $this->extractExtension($filename);

        if ($extension === null) {
            return null;
        }

        $mimeType = self::MIME_MAP[strtolower($extension)] ?? null;

        if ($mimeType !== null && $this->config->useMagicBytes && $binaryData !== null) {
            $signature = $this->detectSignature($binaryData);

            if ($signature !== null && $signature !== $mimeType) {
                return $signature;
            }
        }

        return $mimeType;
    }

    public function getExtension(string $mimeType): ?string
    {
        $flipped = array_flip(self::MIME_MAP);

        return $flipped[$mimeType] ?? null;
    }

    public function getCategory(string $mimeType): string
    {
        $prefix = explode('/', $mimeType)[0];

        return match ($prefix) {
            'image', 'video', 'audio', 'text' => $prefix,
            default => 'application',
        };
    }

    private function extractExtension(string $filename): ?string
    {
        $pos = strrpos($filename, '.');

        if ($pos === false || $pos === strlen($filename) - 1) {
            return null;
        }

        return substr($filename, $pos + 1);
    }

    private function detectSignature(string $binaryData): ?string
    {
        $bytes = unpack('C*', $binaryData);

        foreach (self::SIGNATURES as $mime => $signature) {
            $match = true;

            for ($i = 0; $i < count($signature); $i++) {
                if ($bytes[$i + 1] !== $signature[$i]) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return $mime;
            }
        }

        return null;
    }
}
