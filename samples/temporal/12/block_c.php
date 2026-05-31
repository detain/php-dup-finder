<?php
declare(strict_types=1);

namespace Notion\Integration\Service;

use Notion\Integration\Repository\SyncStateRepository;
use Notion\Integration\Repository\RemoteRecordRepository;
use Notion\Integration\Entity\SyncCheckpoint;
use Notion\Integration\Entity\RemoteRecord;
use Notion\Integration\Entity\SyncResult;
use Notion\Core\Cache\CacheManager;
use Notion\Core\Logging\SyncLogger;

final class BiDirectionalSyncService
{
    private SyncStateRepository $syncStateRepo;
    private RemoteRecordRepository $remoteRepo;
    private CacheManager $cache;
    private SyncLogger $logger;

    public function __construct(
        SyncStateRepository $syncStateRepo,
        RemoteRecordRepository $remoteRepo,
        CacheManager $cache,
        SyncLogger $logger
    ) {
        $this->syncStateRepo = $syncStateRepo;
        $this->remoteRepo = $remoteRepo;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function performSync(string $entityType, string $direction): SyncResult
    {
        $this->logger->info('Starting bi-directional sync', [
            'entity_type' => $entityType,
            'direction' => $direction
        ]);

        $checkpoint = $this->syncStateRepo->getCheckpoint($entityType);
        if ($checkpoint === null) {
            $checkpoint = $this->syncStateRepo->createInitialCheckpoint($entityType);
        }

        $this->cache->set("sync:lock:{$entityType}", time(), ['ttl' => 300]);
        $this->logger->debug('Sync lock acquired', ['entity_type' => $entityType]);

        try {
            if ($direction === 'push' || $direction === 'bidirectional') {
                $localChanges = $this->syncStateRepo->getLocalChangesSince(
                    $entityType,
                    $checkpoint->getLastSyncTimestamp()
                );

                $this->logger->info('Processing local changes for push', [
                    'entity_type' => $entityType,
                    'change_count' => count($localChanges)
                ]);

                foreach ($localChanges as $change) {
                    $remoteId = $this->remoteRepo->pushChange($entityType, $change);
                    $this->syncStateRepo->recordPushedChange($change->getId(), $remoteId);
                }
            }

            if ($direction === 'pull' || $direction === 'bidirectional') {
                $remoteChanges = $this->remoteRepo->getChangesSince(
                    $entityType,
                    $checkpoint->getLastRemoteTimestamp()
                );

                $this->logger->info('Processing remote changes for pull', [
                    'entity_type' => $entityType,
                    'change_count' => count($remoteChanges)
                ]);

                foreach ($remoteChanges as $change) {
                    $localId = $this->syncStateRepo->applyRemoteChange($entityType, $change);
                    $this->remoteRepo->acknowledgeChange($change->getRemoteId(), $localId);
                }
            }

            $newTimestamp = new \DateTimeImmutable();
            $this->syncStateRepo->updateCheckpoint($entityType, $newTimestamp);

            $this->cache->delete("sync:lock:{$entityType}");
            $this->logger->debug('Sync lock released', ['entity_type' => $entityType]);

            $this->logger->info('Bi-directional sync completed', [
                'entity_type' => $entityType,
                'completed_at' => $newTimestamp->format('c')
            ]);

            return new SyncResult([
                'success' => true,
                'entity_type' => $entityType,
                'synced_at' => $newTimestamp->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->cache->delete("sync:lock:{$entityType}");
            $this->logger->error('Bi-directional sync failed, lock released', [
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
