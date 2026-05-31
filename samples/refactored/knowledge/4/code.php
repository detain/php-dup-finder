<?php

declare(strict_types=1);

namespace App\Domain\Uploads;

final class UploadLimits
{
    public const MAX_BYTES = 10485760; // 10 MiB

    public static function maxMegabytes(): int
    {
        return (int) (self::MAX_BYTES / 1024 / 1024);
    }

    public static function describe(): string
    {
        return sprintf('Maximum file size: %d MB.', self::maxMegabytes());
    }

    public static function exceeds(int $sizeBytes): bool
    {
        return $sizeBytes > self::MAX_BYTES;
    }
}

// Middleware:
// if (UploadLimits::exceeds($contentLength)) {
//     return Response::json(['error' => 'payload_too_large', 'limit_bytes' => UploadLimits::MAX_BYTES], 413);
// }

// Storage service:
// if (UploadLimits::exceeds($file->size)) {
//     throw new StorageException(UploadLimits::describe());
// }

// UI hint renderer:
// $attrs[] = 'data-max-bytes="' . UploadLimits::MAX_BYTES . '"';
// $html .= '<p class="hint">' . htmlspecialchars(UploadLimits::describe()) . '</p>';
