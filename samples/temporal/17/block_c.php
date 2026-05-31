<?php
declare(strict_types=1);

namespace Kubernetes\Pod\Service;

use Kubernetes\Pod\Repository\PodRepository;
use Kubernetes\Pod\Repository\EventRepository;
use Kubernetes\Pod\Repository\ConfigMapRepository;
use Kubernetes\Pod\Entity\Pod;
use Kubernetes\Pod\Entity\ContainerStatus;
use Kubernetes\Pod\Entity\PodEvent;
use Kubernetes\Pod\Exception\PodException;
use Kubernetes\Pod\Service\Scheduler\NodeSelector;
use Kubernetes\Pod\Service\Networking\ServiceAccountManager;
use Psr\Log\LoggerInterface;

final class PodLifecycleService
{
    private PodRepository $podRepo;
    private EventRepository $eventRepo;
    private ConfigMapRepository $configMapRepo;
    private NodeSelector $nodeSelector;
    private ServiceAccountManager $serviceAccountManager;
    private LoggerInterface $logger;

    public function __construct(
        PodRepository $podRepo,
        EventRepository $eventRepo,
        ConfigMapRepository $configMapRepo,
        NodeSelector $nodeSelector,
        ServiceAccountManager $serviceAccountManager,
        LoggerInterface $logger
    ) {
        $this->podRepo = $podRepo;
        $this->eventRepo = $eventRepo;
        $this->configMapRepo = $configMapRepo;
        $this->nodeSelector = $nodeSelector;
        $this->serviceAccountManager = $serviceAccountManager;
        $this->logger = $logger;
    }

    public function createPod(string $namespace, array $podSpec): PodResult
    {
        $this->logger->info('Creating pod', [
            'namespace' => $namespace,
            'pod_name' => $podSpec['metadata']['name'] ?? 'unknown'
        ]);

        $namespaceObj = $this->podRepo->findNamespace($namespace);
        if ($namespaceObj === null) {
            throw new PodException("Namespace not found: {$namespace}");
        }

        $serviceAccount = $this->serviceAccountManager->getServiceAccount(
            $namespace,
            $podSpec['spec']['serviceAccountName'] ?? 'default'
        );

        $pod = Pod::create([
            'namespace' => $namespace,
            'name' => $podSpec['metadata']['name'],
            'uid' => $this->generateUid(),
            'labels' => $podSpec['metadata']['labels'] ?? [],
            'annotations' => $podSpec['metadata']['annotations'] ?? [],
            'service_account' => $serviceAccount->getName(),
            'status' => 'Pending',
            'node_name' => null,
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedPod = $this->podRepo->save($pod);

        foreach ($podSpec['spec']['containers'] as $containerSpec) {
            $containerStatus = ContainerStatus::create([
                'pod_id' => $savedPod->getId(),
                'container_name' => $containerSpec['name'],
                'image' => $containerSpec['image'],
                'ready' => false,
                'restart_count' => 0,
                'state' => 'waiting'
            ]);
            $this->podRepo->saveContainerStatus($containerStatus);
        }

        $this->injectConfigMaps($savedPod, $podSpec);
        $this->createPodEvent($savedPod->getId(), 'Normal', 'Scheduled', 'Pod is scheduled');

        $this->logger->info('Pod created successfully', [
            'pod_id' => $savedPod->getId(),
            'namespace' => $namespace,
            'containers_count' => count($podSpec['spec']['containers'])
        ]);

        return new PodResult([
            'success' => true,
            'pod_id' => $savedPod->getId(),
            'pod_name' => $savedPod->getName(),
            'namespace' => $namespace
        ]);
    }

    public function schedulePod(string $podId): ScheduleResult
    {
        $pod = $this->podRepo->findById($podId);
        if ($pod === null) {
            throw new PodException("Pod not found: {$podId}");
        }

        if ($pod->getStatus() !== 'Pending') {
            throw new PodException("Pod cannot be scheduled in status: {$pod->getStatus()}");
        }

        $scheduleLock = $this->podRepo->acquireSchedulingLock($podId);
        if ($scheduleLock === null) {
            throw new PodException("Could not acquire scheduling lock for pod: {$podId}");
        }

        $this->logger->debug('Scheduling lock acquired', ['pod_id' => $podId]);

        try {
            $selectedNode = $this->nodeSelector->selectNode($pod);

            if ($selectedNode === null) {
                $this->createPodEvent($podId, 'Warning', 'FailedScheduling', 'No node available');
                $this->podRepo->releaseSchedulingLock($scheduleLock);
                throw new PodException('No suitable node found for pod scheduling');
            }

            $this->podRepo->assignNode($podId, $selectedNode->getName());
            $this->podRepo->updateStatus($podId, 'Scheduled');

            $this->createPodEvent($podId, 'Normal', 'Scheduled', "Pod assigned to node {$selectedNode->getName()}");

            $this->podRepo->releaseSchedulingLock($scheduleLock);

            $this->logger->info('Pod scheduled successfully', [
                'pod_id' => $podId,
                'node' => $selectedNode->getName()
            ]);

            return new ScheduleResult([
                'success' => true,
                'pod_id' => $podId,
                'node_name' => $selectedNode->getName()
            ]);

        } catch (\Throwable $e) {
            $this->podRepo->releaseSchedulingLock($scheduleLock);
            $this->logger->error('Pod scheduling failed', [
                'pod_id' => $podId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function startPod(string $podId): StartResult
    {
        $pod = $this->podRepo->findById($podId);
        if ($pod === null) {
            throw new PodException("Pod not found: {$podId}");
        }

        if ($pod->getStatus() !== 'Scheduled') {
            throw new PodException("Pod cannot be started in status: {$pod->getStatus()}");
        }

        $this->podRepo->updateStatus($podId, 'Running', [
            'started_at' => new \DateTimeImmutable()
        ]);

        $this->createPodEvent($podId, 'Normal', 'Started', 'Container is starting');

        $containers = $this->podRepo->getContainerStatuses($podId);
        foreach ($containers as $container) {
            $this->podRepo->updateContainerStatus($container->getId(), [
                'state' => 'running',
                'started_at' => new \DateTimeImmutable()
            ]);
        }

        $this->logger->info('Pod started successfully', [
            'pod_id' => $podId,
            'containers_started' => count($containers)
        ]);

        return new StartResult([
            'success' => true,
            'pod_id' => $podId,
            'started_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    public function terminatePod(string $podId, int $gracePeriodSeconds = 30): TerminateResult
    {
        $pod = $this->podRepo->findById($podId);
        if ($pod === null) {
            throw new PodException("Pod not found: {$podId}");
        }

        if (!in_array($pod->getStatus(), ['Running', 'Scheduled', 'Pending'])) {
            throw new PodException("Pod cannot be terminated in status: {$pod->getStatus()}");
        }

        $terminationLock = $this->podRepo->acquireTerminationLock($podId);
        if ($terminationLock === null) {
            throw new PodException("Could not acquire termination lock for pod: {$podId}");
        }

        try {
            $this->createPodEvent($podId, 'Normal', 'Killing', 'Pod is being terminated');

            $this->podRepo->updateStatus($podId, 'Terminating', [
                'terminating_at' => new \DateTimeImmutable(),
                'grace_period_seconds' => $gracePeriodSeconds
            ]);

            $containers = $this->podRepo->getContainerStatuses($podId);
            foreach ($containers as $container) {
                $this->podRepo->updateContainerStatus($container->getId(), [
                    'state' => 'terminated',
                    'exit_code' => 0,
                    'terminated_at' => new \DateTimeImmutable()
                ]);
            }

            $this->podRepo->updateStatus($podId, 'Terminated', [
                'completed_at' => new \DateTimeImmutable()
            ]);

            $this->createPodEvent($podId, 'Normal', 'Stopped', 'Pod has been terminated');

            $this->podRepo->releaseTerminationLock($terminationLock);

            $this->logger->info('Pod terminated successfully', [
                'pod_id' => $podId,
                'grace_period' => $gracePeriodSeconds
            ]);

            return new TerminateResult([
                'success' => true,
                'pod_id' => $podId,
                'terminated_at' => (new \DateTimeImmutable())->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->podRepo->releaseTerminationLock($terminationLock);
            $this->logger->error('Pod termination failed', [
                'pod_id' => $podId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function injectConfigMaps(Pod $pod, array $podSpec): void
    {
        $configMapRefs = $podSpec['spec']['configMap'] ?? [];

        foreach ($configMapRefs as $ref) {
            $configMap = $this->configMapRepo->findInNamespace($ref['name'], $pod->getNamespace());
            if ($configMap !== null) {
                $this->podRepo->attachConfigMap($pod->getId(), $configMap->getId());
            }
        }
    }

    private function createPodEvent(string $podId, string $type, string $reason, string $message): void
    {
        $event = PodEvent::create([
            'pod_id' => $podId,
            'type' => $type,
            'reason' => $reason,
            'message' => $message,
            'created_at' => new \DateTimeImmutable()
        ]);
        $this->eventRepo->save($event);
    }

    private function generateUid(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            time(),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffffffffffff)
        );
    }
}
