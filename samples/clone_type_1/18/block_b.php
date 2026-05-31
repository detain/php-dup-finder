<?php

declare(strict_types=1);

namespace App\Cache\File;

use App\Service\CacheBackendInterface;
use Psr\Log\LoggerInterface;

final class FileCacheService implements CacheBackendInterface
{
    private string $cacheDir;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $cacheDir = '/var/cache/app',
    ) {
        $this->cacheDir = $cacheDir;
    }

    public function get(string $key): mixed
    {
        $path = $this->getCachePath($key);

        if (!file_exists($path)) {
            $this->logger->debug('File cache miss', ['key' => $key]);
            return null;
        }

        $data = file_get_contents($path);
        $cached = unserialize($data);

        if ($cached['expiry'] < time()) {
            unlink($path);
            $this->logger->debug('File cache expired', ['key' => $key]);
            return null;
        }

        $this->logger->debug('File cache hit', ['key' => $key]);
        return $cached['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $path = $this->getCachePath($key);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'value' => $value,
            'expiry' => time() + $ttl,
        ];

        file_put_contents($path, serialize($data));

        $this->logger->debug('File cache set', [
            'key' => $key,
            'ttl' => $ttl,
        ]);
    }

    public function delete(string $key): void
    {
        $path = $this->getCachePath($key);

        if (file_exists($path)) {
            unlink($path);
        }

        $this->logger->debug('File cache delete', ['key' => $key]);
    }

    public function clear(): void
    {
        $this->deleteDirectory($this->cacheDir);
        $this->logger->info('File cache cleared');
    }

    public function has(string $key): bool
    {
        $path = $this->getCachePath($key);

        if (!file_exists($path)) {
            return false;
        }

        $data = file_get_contents($path);
        $cached = unserialize($data);

        if ($cached['expiry'] < time()) {
            unlink($path);
            return false;
        }

        return true;
    }

    public function prune(): int
    {
        return $this->pruneDirectory($this->cacheDir);
    }

    private function getCachePath(string $key): string
    {
        $hash = md5($key);
        return $this->cacheDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function pruneDirectory(string $dir): int
    {
        $pruned = 0;
        $now = time();

        $files = glob($dir . '/**/*.cache', GLOB_BRACE);

        foreach ($files as $file) {
            $data = file_get_contents($file);
            $cached = unserialize($data);

            if ($cached['expiry'] < $now) {
                unlink($file);
                $pruned++;
            }
        }

        $this->logger->debug('File cache pruned', ['count' => $pruned]);

        return $pruned;
    }
}
