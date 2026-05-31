<?php

declare(strict_types=1);

namespace App\Storage;

use App\Http\UploadedFile;
use App\Exceptions\StorageException;
use App\Repositories\AssetRepository;
use Psr\Log\LoggerInterface;

final class AssetStorageService
{
    public function __construct(
        private string $storageRoot,
        private AssetRepository $assets,
        private LoggerInterface $logger,
    ) {}

    public function store(UploadedFile $file, int $ownerId): string
    {
        if ($file->size > 10485760) {
            $this->logger->warning('Rejected upload over 10 MiB', [
                'owner_id' => $ownerId,
                'size' => $file->size,
                'name' => $file->originalName,
            ]);
            throw new StorageException(
                'File exceeds the 10 MiB maximum. Please compress or resize before uploading.'
            );
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($file->mimeType, $allowed, true)) {
            throw new StorageException('Unsupported file type: ' . $file->mimeType);
        }

        $hash = hash_file('sha256', $file->tempPath);
        $key = substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        $target = $this->storageRoot . '/' . $key;

        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o755, true);
        }
        if (!move_uploaded_file($file->tempPath, $target)) {
            throw new StorageException('Failed to persist uploaded file.');
        }

        $this->assets->insert([
            'owner_id' => $ownerId,
            'storage_key' => $key,
            'mime_type' => $file->mimeType,
            'size_bytes' => $file->size,
            'original_name' => $file->originalName,
        ]);

        return $key;
    }
}
