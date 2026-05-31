<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\MediaRepository;
use App\Repository\AlbumRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class MediaCacheHandler
{
    private const CACHE_PREFIX = 'media';
    private const DEFAULT_TTL = 86400;
    private const STALE_TTL = 3600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly MediaRepository $mediaRepository,
        private readonly AlbumRepository $albumRepository,
        private readonly UserRepository $userRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getMedia(int $mediaId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildMediaCacheKey($mediaId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'media']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'media']);
        $media = $this->mediaRepository->find($mediaId);

        if ($media === null) {
            return null;
        }

        $data = $this->serializeMedia($media);
        $this->setMedia($mediaId, $data);
        return $data;
    }

    public function setMedia(int $mediaId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildMediaCacheKey($mediaId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateMedia(int $mediaId): void
    {
        $cacheKey = $this->buildMediaCacheKey($mediaId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateAlbumMedia(int $albumId): void
    {
        $mediaItems = $this->mediaRepository->findByAlbumId($albumId);
        $cacheKeys = array_map(
            fn($media) => $this->buildMediaCacheKey($media->getId()),
            $mediaItems
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateAlbumMediaCount($albumId);
        $this->logger->info('Invalidated media for album', [
            'album_id' => $albumId,
            'media_count' => count($mediaItems),
        ]);
    }

    public function invalidateUserMedia(int $userId): void
    {
        $mediaItems = $this->mediaRepository->findByUserId($userId);
        $cacheKeys = array_map(
            fn($media) => $this->buildMediaCacheKey($media->getId()),
            $mediaItems
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateUserMediaCount($userId);
        $this->logger->info('Invalidated media for user', [
            'user_id' => $userId,
            'media_count' => count($mediaItems),
        ]);
    }

    public function refreshMedia(int $mediaId): void
    {
        $cacheKey = $this->buildMediaCacheKey($mediaId);
        $media = $this->mediaRepository->find($mediaId);

        if ($media === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeMedia($media);
        $this->setMedia($mediaId, $data);
    }

    public function warmUser(int $userId): void
    {
        $mediaItems = $this->mediaRepository->findRecentByUserId($userId, 50);

        foreach ($mediaItems as $media) {
            $data = $this->serializeMedia($media);
            $this->setMedia($media->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed media cache for user', [
            'user_id' => $userId,
            'media_warmed' => count($mediaItems),
        ]);
    }

    public function handleUploadMedia(int $mediaId): void
    {
        $media = $this->mediaRepository->find($mediaId);
        if ($media === null) {
            return;
        }

        $this->invalidateUserMedia($media->getUserId());
        if ($media->getAlbumId() !== null) {
            $this->invalidateAlbumMedia($media->getAlbumId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'upload_media',
            'media_id' => (string) $mediaId,
        ]);
    }

    public function handleUpdateMedia(int $mediaId): void
    {
        $this->invalidateMedia($mediaId);

        $media = $this->mediaRepository->find($mediaId);
        if ($media === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('media', $mediaId, 'metadata'),
            $this->keyBuilder->build('media', $mediaId, 'thumbnail'),
            $this->keyBuilder->build('media', $mediaId, 'exif'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled media update cache invalidation', [
            'media_id' => $mediaId,
        ]);
    }

    public function handleDeleteMedia(int $mediaId): void
    {
        $media = $this->mediaRepository->find($mediaId);
        if ($media !== null) {
            $this->invalidateMedia($mediaId);
            $this->invalidateUserMedia($media->getUserId());
            if ($media->getAlbumId() !== null) {
                $this->invalidateAlbumMedia($media->getAlbumId());
            }
        }

        $this->logger->info('Handled media deletion cache invalidation', [
            'media_id' => $mediaId,
        ]);
    }

    public function handleAlbumUpdate(int $albumId): void
    {
        $this->invalidateAlbumMediaCount($albumId);

        $albumKeys = [
            $this->keyBuilder->build('album', $albumId, 'cover'),
            $this->keyBuilder->build('album', $albumId, 'metadata'),
            $this->keyBuilder->build('album', $albumId, 'share_settings'),
        ];

        foreach ($albumKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateAlbumMedia($albumId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'album_update',
            'album_id' => (string) $albumId,
        ]);
    }

    private function buildMediaCacheKey(int $mediaId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'media', $mediaId);
    }

    private function buildAlbumMediaCountCacheKey(int $albumId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'album', $albumId, 'media_count');
    }

    private function buildUserMediaCountCacheKey(int $userId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'user', $userId, 'media_count');
    }

    private function invalidateAlbumMediaCount(int $albumId): void
    {
        $this->cache->delete($this->buildAlbumMediaCountCacheKey($albumId));
    }

    private function invalidateUserMediaCount(int $userId): void
    {
        $this->cache->delete($this->buildUserMediaCountCacheKey($userId));
    }

    private function serializeMedia(object $media): array
    {
        return [
            'id' => $media->getId(),
            'user_id' => $media->getUserId(),
            'album_id' => $media->getAlbumId(),
            'filename' => $media->getFilename(),
            'mime_type' => $media->getMimeType(),
            'size' => $media->getSize(),
            'url' => $media->getUrl(),
            'created_at' => $media->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
