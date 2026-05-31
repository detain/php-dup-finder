<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\DocumentRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class DocumentCacheHandler
{
    private const CACHE_PREFIX = 'document';
    private const DEFAULT_TTL = 7200;
    private const STALE_TTL = 3600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly DocumentRepository $documentRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDocument(int $documentId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDocumentCacheKey($documentId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'document']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'document']);

        $document = $this->documentRepository->find($documentId);

        if ($document === null) {
            return null;
        }

        $data = $this->serializeDocument($document);
        $this->setDocument($documentId, $data);

        return $data;
    }

    public function setDocument(int $documentId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildDocumentCacheKey($documentId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached document', [
            'document_id' => $documentId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateDocument(int $documentId): void
    {
        $cacheKey = $this->buildDocumentCacheKey($documentId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated document cache', [
            'document_id' => $documentId,
        ]);
    }

    public function refreshDocument(int $documentId): void
    {
        $document = $this->documentRepository->find($documentId);

        if ($document === null) {
            $this->cache->delete($this->buildDocumentCacheKey($documentId));
            return;
        }

        $data = $this->serializeDocument($document);
        $this->setDocument($documentId, $data);

        $this->logger->debug('Refreshed document cache', [
            'document_id' => $documentId,
        ]);
    }

    public function warmDocument(int $documentId): void
    {
        $document = $this->documentRepository->find($documentId);

        if ($document !== null) {
            $data = $this->serializeDocument($document);
            $this->setDocument($documentId, $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed document cache', [
            'document_id' => $documentId,
        ]);
    }

    public function getDocumentVersion(int $documentId, int $versionId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDocumentVersionCacheKey($documentId, $versionId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'document_version']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'document_version']);

        $version = $this->documentRepository->findVersion($documentId, $versionId);

        if ($version === null) {
            return null;
        }

        $data = $this->serializeDocumentVersion($version);
        $this->setDocumentVersion($documentId, $versionId, $data);

        return $data;
    }

    public function setDocumentVersion(int $documentId, int $versionId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildDocumentVersionCacheKey($documentId, $versionId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached document version', [
            'document_id' => $documentId,
            'version_id' => $versionId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateDocumentVersion(int $documentId, int $versionId): void
    {
        $cacheKey = $this->buildDocumentVersionCacheKey($documentId, $versionId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated document version cache', [
            'document_id' => $documentId,
            'version_id' => $versionId,
        ]);
    }

    public function getDocumentMetadata(int $documentId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDocumentMetadataCacheKey($documentId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'document_metadata']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'document_metadata']);

        $metadata = $this->documentRepository->findMetadata($documentId);

        if ($metadata === null) {
            return null;
        }

        $data = $this->serializeMetadata($metadata);
        $this->setDocumentMetadata($documentId, $data);

        return $data;
    }

    public function setDocumentMetadata(int $documentId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildDocumentMetadataCacheKey($documentId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached document metadata', [
            'document_id' => $documentId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateDocumentMetadata(int $documentId): void
    {
        $cacheKey = $this->buildDocumentMetadataCacheKey($documentId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated document metadata cache', [
            'document_id' => $documentId,
        ]);
    }

    public function refreshDocumentMetadata(int $documentId): void
    {
        $metadata = $this->documentRepository->findMetadata($documentId);

        if ($metadata === null) {
            $this->cache->delete($this->buildDocumentMetadataCacheKey($documentId));
            return;
        }

        $data = $this->serializeMetadata($metadata);
        $this->setDocumentMetadata($documentId, $data);

        $this->logger->debug('Refreshed document metadata cache', [
            'document_id' => $documentId,
        ]);
    }

    public function getDocumentPermissions(int $documentId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildDocumentPermissionsCacheKey($documentId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'document_permissions']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'document_permissions']);

        $permissions = $this->documentRepository->findPermissions($documentId);

        if ($permissions === null) {
            return null;
        }

        $data = $this->serializePermissions($permissions);
        $this->setDocumentPermissions($documentId, $data);

        return $data;
    }

    public function setDocumentPermissions(int $documentId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildDocumentPermissionsCacheKey($documentId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached document permissions', [
            'document_id' => $documentId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateDocumentPermissions(int $documentId): void
    {
        $cacheKey = $this->buildDocumentPermissionsCacheKey($documentId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated document permissions cache', [
            'document_id' => $documentId,
        ]);
    }

    public function refreshDocumentPermissions(int $documentId): void
    {
        $permissions = $this->documentRepository->findPermissions($documentId);

        if ($permissions === null) {
            $this->cache->delete($this->buildDocumentPermissionsCacheKey($documentId));
            return;
        }

        $data = $this->serializePermissions($permissions);
        $this->setDocumentPermissions($documentId, $data);

        $this->logger->debug('Refreshed document permissions cache', [
            'document_id' => $documentId,
        ]);
    }

    public function handleDocumentUpdate(int $documentId): void
    {
        $this->invalidateDocument($documentId);
        $this->invalidateDocumentMetadata($documentId);
        $this->refreshDocumentPermissions($documentId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'document_update',
            'document_id' => (string) $documentId,
        ]);

        $this->logger->info('Handled document update cache invalidation', [
            'document_id' => $documentId,
        ]);
    }

    public function handleDocumentDelete(int $documentId): void
    {
        $this->invalidateDocument($documentId);
        $this->invalidateDocumentMetadata($documentId);
        $this->invalidateDocumentPermissions($documentId);

        $versions = $this->documentRepository->findVersionIds($documentId);
        foreach ($versions as $versionId) {
            $this->invalidateDocumentVersion($documentId, $versionId);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'document_delete',
            'document_id' => (string) $documentId,
        ]);

        $this->logger->info('Handled document delete cache invalidation', [
            'document_id' => $documentId,
        ]);
    }

    public function handlePermissionChange(int $documentId): void
    {
        $this->invalidateDocumentPermissions($documentId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'permission_change',
            'document_id' => (string) $documentId,
        ]);

        $this->logger->info('Handled permission change cache invalidation', [
            'document_id' => $documentId,
        ]);
    }

    public function handleBulkDocumentUpdate(array $documentIds): void
    {
        foreach ($documentIds as $documentId) {
            $this->invalidateDocument($documentId);
            $this->invalidateDocumentMetadata($documentId);
        }

        $this->logger->info('Handled bulk document update cache invalidation', [
            'document_count' => count($documentIds),
        ]);
    }

    private function buildDocumentCacheKey(int $documentId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'doc', (string) $documentId);
    }

    private function buildDocumentVersionCacheKey(int $documentId, int $versionId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'doc', (string) $documentId, 'v', (string) $versionId);
    }

    private function buildDocumentMetadataCacheKey(int $documentId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'doc', (string) $documentId, 'meta');
    }

    private function buildDocumentPermissionsCacheKey(int $documentId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'doc', (string) $documentId, 'perms');
    }

    private function serializeDocument(object $document): array
    {
        return [
            'id' => $document->getId(),
            'title' => $document->getTitle(),
            'content' => $document->getContent(),
            'format' => $document->getFormat(),
            'size' => $document->getSize(),
        ];
    }

    private function serializeDocumentVersion(object $version): array
    {
        return [
            'id' => $version->getId(),
            'content' => $version->getContent(),
            'created_at' => $version->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }

    private function serializeMetadata(object $metadata): array
    {
        return [
            'author' => $metadata->getAuthor(),
            'created_at' => $metadata->getCreatedAt()?->format(\DATE_ATOM),
            'modified_at' => $metadata->getModifiedAt()?->format(\DATE_ATOM),
            'tags' => $metadata->getTags(),
        ];
    }

    private function serializePermissions(array $permissions): array
    {
        $result = [];
        foreach ($permissions as $perm) {
            $result[$perm->getUserId()] = $perm->getPermissions();
        }
        return $result;
    }
}
