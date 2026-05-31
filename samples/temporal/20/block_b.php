<?php
declare(strict_types=1);

namespace MongoDB\Atlas\Service;

use MongoDB\Atlas\Repository\ClusterRepository;
use MongoDB\Atlas\Repository\DatabaseUserRepository;
use MongoDB\Atlas\Repository\BackupRepository;
use MongoDB\Atlas\Entity\Cluster;
use MongoDB\Atlas\Entity\DatabaseUser;
use MongoDB\Atlas\Entity\BackupSnapshot;
use MongoDB\Atlas\Exception\AtlasException;
use MongoDB\Atlas\Service\Automation\ClusterAutoscaler;
use MongoDB\Atlas\Service\Security\IpWhitelistService;
use Psr\Log\LoggerInterface;

final class ClusterLifecycleService
{
    private ClusterRepository $clusterRepo;
    private DatabaseUserRepository $userRepo;
    private BackupRepository $backupRepo;
    private ClusterAutoscaler $autoscaler;
    private IpWhitelistService $ipWhitelist;
    private LoggerInterface $logger;

    public function __construct(
        ClusterRepository $clusterRepo,
        DatabaseUserRepository $userRepo,
        BackupRepository $backupRepo,
        ClusterAutoscaler $autoscaler,
        IpWhitelistService $ipWhitelist,
        LoggerInterface $logger
    ) {
        $this->clusterRepo = $clusterRepo;
        $this->userRepo = $userRepo;
        $this->backupRepo = $backupRepo;
        $this->autoscaler = $autoscaler;
        $this->ipWhitelist = $ipWhitelist;
        $this->logger = $logger;
    }

    public function createCluster(string $projectId, array $clusterSpec): ClusterResult
    {
        $this->logger->info('Creating cluster', [
            'project_id' => $projectId,
            'cluster_name' => $clusterSpec['name'] ?? 'unknown'
        ]);

        $existing = $this->clusterRepo->findByName($projectId, $clusterSpec['name']);
        if ($existing !== null) {
            throw new AtlasException("Cluster already exists: {$clusterSpec['name']}");
        }

        $clusterLock = $this->clusterRepo->acquireCreationLock($projectId, $clusterSpec['name']);
        if ($clusterLock === null) {
            throw new AtlasException("Could not acquire cluster creation lock");
        }

        $this->logger->debug('Cluster creation lock acquired', [
            'project_id' => $projectId,
            'cluster_name' => $clusterSpec['name']
        ]);

        try {
            $cluster = Cluster::create([
                'project_id' => $projectId,
                'name' => $clusterSpec['name'],
                'provider' => $clusterSpec['provider'] ?? 'AWS',
                'region' => $clusterSpec['region'],
                'tier' => $clusterSpec['tier'] ?? 'M10',
                'replica_set_count' => $clusterSpec['replica_set_count'] ?? 3,
                'disk_size_gb' => $clusterSpec['disk_size_gb'] ?? 10,
                'mongo_version' => $clusterSpec['mongo_version'] ?? '5.0',
                'status' => 'creating',
                'backup_enabled' => $clusterSpec['backup_enabled'] ?? true,
                'created_at' => new \DateTimeImmutable()
            ]);

            $savedCluster = $this->clusterRepo->save($cluster);
            $this->logger->debug('Cluster record created', [
                'cluster_id' => $savedCluster->getId()
            ]);

            $this->ipWhitelist->initializeForCluster($savedCluster->getId(), [
                'whitelist' => $clusterSpec['ip_whitelist'] ?? []
            ]);

            if ($clusterSpec['backup_enabled'] ?? true) {
                $this->initializeBackupConfiguration($savedCluster);
            }

            $deploymentResult = $this->clusterRepo->initiateDeployment($savedCluster);

            if (!$deploymentResult->isSuccess()) {
                throw new AtlasException('Cluster deployment initiation failed');
            }

            $this->clusterRepo->updateStatus($savedCluster->getId(), 'deploying');

            $this->clusterRepo->releaseCreationLock($clusterLock);

            $this->logger->info('Cluster creation initiated', [
                'cluster_id' => $savedCluster->getId(),
                'project_id' => $projectId,
                'estimated_deployment_time' => $deploymentResult->getEstimatedSeconds() . 's'
            ]);

            return new ClusterResult([
                'success' => true,
                'cluster_id' => $savedCluster->getId(),
                'cluster_name' => $savedCluster->getName(),
                'status' => 'deploying',
                'estimated_deployment_time' => $deploymentResult->getEstimatedSeconds()
            ]);

        } catch (\Throwable $e) {
            $this->clusterRepo->releaseCreationLock($clusterLock);
            $this->logger->error('Cluster creation failed', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function scaleCluster(string $clusterId, array $newSpec): ScaleResult
    {
        $cluster = $this->clusterRepo->findById($clusterId);
        if ($cluster === null) {
            throw new AtlasException("Cluster not found: {$clusterId}");
        }

        if (!$cluster->isActive()) {
            throw new AtlasException("Cannot scale cluster in status: {$cluster->getStatus()}");
        }

        $scaleLock = $this->clusterRepo->acquireScaleLock($clusterId);
        if ($scaleLock === null) {
            throw new AtlasException("Could not acquire scale lock for cluster: {$clusterId}");
        }

        $this->logger->debug('Scale lock acquired', ['cluster_id' => $clusterId]);

        try {
            $this->clusterRepo->updateSpecification($clusterId, $newSpec);
            $this->clusterRepo->updateStatus($clusterId, 'scaling');

            $scaleResult = $this->autoscaler->performScale($clusterId, $newSpec);

            if (!$scaleResult->isSuccess()) {
                $this->clusterRepo->updateStatus($clusterId, 'failed');
                throw new AtlasException('Cluster scaling failed');
            }

            $this->clusterRepo->updateStatus($clusterId, 'active');

            $this->clusterRepo->releaseScaleLock($scaleLock);

            $this->logger->info('Cluster scaled successfully', [
                'cluster_id' => $clusterId,
                'new_tier' => $newSpec['tier']
            ]);

            return new ScaleResult([
                'success' => true,
                'cluster_id' => $clusterId,
                'new_tier' => $newSpec['tier'],
                'completed_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->clusterRepo->releaseScaleLock($scaleLock);
            $this->logger->error('Cluster scaling failed', [
                'cluster_id' => $clusterId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function terminateCluster(string $clusterId, bool $takeFinalBackup = true): TerminateResult
    {
        $cluster = $this->clusterRepo->findById($clusterId);
        if ($cluster === null) {
            throw new AtlasException("Cluster not found: {$clusterId}");
        }

        $terminateLock = $this->clusterRepo->acquireTerminationLock($clusterId);
        if ($terminateLock === null) {
            throw new AtlasException("Could not acquire termination lock");
        }

        try {
            if ($takeFinalBackup) {
                $this->logger->info('Creating final backup before termination', [
                    'cluster_id' => $clusterId
                ]);

                $backup = $this->createFinalBackup($cluster);
                $this->logger->debug('Final backup created', [
                    'backup_id' => $backup->getId()
                ]);
            }

            $this->clusterRepo->updateStatus($clusterId, 'terminating');

            $terminationResult = $this->clusterRepo->initiateTermination($clusterId);

            if (!$terminationResult->isSuccess()) {
                $this->clusterRepo->updateStatus($clusterId, 'failed');
                throw new AtlasException('Cluster termination failed');
            }

            $this->userRepo->removeUsersForCluster($clusterId);
            $this->ipWhitelist->cleanupForCluster($clusterId);

            $this->clusterRepo->updateStatus($clusterId, 'terminated', [
                'terminated_at' => new \DateTimeImmutable()
            ]);

            $this->clusterRepo->releaseTerminationLock($terminateLock);

            $this->logger->info('Cluster terminated successfully', [
                'cluster_id' => $clusterId,
                'final_backup_taken' => $takeFinalBackup
            ]);

            return new TerminateResult([
                'success' => true,
                'cluster_id' => $clusterId,
                'terminated_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->clusterRepo->releaseTerminationLock($terminateLock);
            $this->logger->error('Cluster termination failed', [
                'cluster_id' => $clusterId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function initializeBackupConfiguration(Cluster $cluster): void
    {
        $this->backupRepo->createConfiguration([
            'cluster_id' => $cluster->getId(),
            'enabled' => true,
            'policy' => 'daily',
            'retention_days' => 30,
            'method' => 'cloud_backup'
        ]);
    }

    private function createFinalBackup(Cluster $cluster): BackupSnapshot
    {
        $snapshot = BackupSnapshot::create([
            'cluster_id' => $cluster->getId(),
            'type' => 'final',
            'status' => 'in_progress',
            'initiated_at' => new \DateTimeImmutable()
        ]);

        return $this->backupRepo->create($snapshot);
    }
}
