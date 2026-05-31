<?php
declare(strict_types=1);

namespace GitHub\Actions\Service;

use GitHub\Actions\Repository\WorkflowRunRepository;
use GitHub\Actions\Repository\ArtifactRepository;
use GitHub\Actions\Entity\WorkflowRun;
use GitHub\Actions\Entity\Artifact;
use GitHub\Actions\Entity\CacheEntry;
use GitHub\Actions\Exception\WorkflowRunException;
use GitHub\Actions\Service\Artifacts\ArtifactUploader;
use GitHub\Actions\Service\Cache\CacheManager;
use Psr\Log\LoggerInterface;

final class WorkflowRunService
{
    private WorkflowRunRepository $runRepository;
    private ArtifactRepository $artifactRepository;
    private ArtifactUploader $artifactUploader;
    private CacheManager $cacheManager;
    private LoggerInterface $logger;

    public function __construct(
        WorkflowRunRepository $runRepository,
        ArtifactRepository $artifactRepository,
        ArtifactUploader $artifactUploader,
        CacheManager $cacheManager,
        LoggerInterface $logger
    ) {
        $this->runRepository = $runRepository;
        $this->artifactRepository = $artifactRepository;
        $this->artifactUploader = $artifactUploader;
        $this->cacheManager = $cacheManager;
        $this->logger = $logger;
    }

    public function initiateWorkflowRun(string $workflowId, array $inputs): WorkflowRunResult
    {
        $this->logger->info('Initiating workflow run', [
            'workflow_id' => $workflowId,
            'input_count' => count($inputs)
        ]);

        $run = WorkflowRun::create([
            'workflow_id' => $workflowId,
            'status' => 'queued',
            'conclusion' => null,
            'run_number' => $this->runRepository->getNextRunNumber($workflowId),
            'event' => $inputs['event'] ?? 'workflow_dispatch',
            'inputs' => json_encode($inputs),
            'created_at' => new \DateTimeImmutable(),
            'updated_at' => new \DateTimeImmutable()
        ]);

        $savedRun = $this->runRepository->save($run);
        $this->logger->debug('Workflow run record created', [
            'run_id' => $savedRun->getId()
        ]);

        $cacheEntry = CacheEntry::create([
            'run_id' => $savedRun->getId(),
            'workflow_id' => $workflowId,
            'cache_key' => $this->generateCacheKey($workflowId, $inputs),
            'created_at' => new \DateTimeImmutable(),
            'expires_at' => (new \DateTimeImmutable())->modify('+7 days')
        ]);

        $artifact = Artifact::create([
            'run_id' => $savedRun->getId(),
            'name' => 'workflow-output',
            'size_bytes' => 0,
            'status' => 'uploaded',
            'created_at' => new \DateTimeImmutable()
        ]);

        try {
            $this->artifactRepository->save($artifact);
            $this->runRepository->attachArtifact($savedRun->getId(), $artifact->getId());

            $this->cacheManager->initialize($cacheEntry);

            $this->runRepository->updateStatus($savedRun->getId(), 'in_progress', [
                'started_at' => (new \DateTimeImmutable())->format('c')
            ]);

            $this->logger->info('Workflow run initiated successfully', [
                'run_id' => $savedRun->getId(),
                'run_number' => $savedRun->getRunNumber()
            ]);

            return new WorkflowRunResult([
                'success' => true,
                'run_id' => $savedRun->getId(),
                'run_number' => $savedRun->getRunNumber(),
                'status' => 'queued'
            ]);

        } catch (\Throwable $e) {
            $this->runRepository->updateStatus($savedRun->getId(), 'failed', [
                'conclusion' => 'internal_error',
                'completed_at' => (new \DateTimeImmutable())->format('c')
            ]);

            $this->logger->error('Workflow run initiation failed', [
                'workflow_id' => $workflowId,
                'error' => $e->getMessage()
            ]);

            throw new WorkflowRunException(
                'Failed to initiate workflow run: ' . $e->getMessage(),
                $savedRun->getId(),
                $e
            );
        }
    }

    public function completeWorkflowRun(string $runId, string $conclusion, array $outputs): WorkflowRunResult
    {
        $run = $this->runRepository->findById($runId);
        if ($run === null) {
            throw new \InvalidArgumentException("Workflow run not found: {$runId}");
        }

        if ($run->getStatus() !== 'in_progress') {
            throw new WorkflowRunException(
                "Cannot complete workflow run in status: {$run->getStatus()}",
                $runId
            );
        }

        $this->runRepository->updateStatus($runId, 'completed', [
            'conclusion' => $conclusion,
            'completed_at' => (new \DateTimeImmutable())->format('c'),
            'outputs' => json_encode($outputs)
        ]);

        if (!empty($outputs)) {
            $this->runRepository->saveOutputs($runId, $outputs);
        }

        $this->cacheManager->finalizeForRun($runId);

        $this->logger->info('Workflow run completed', [
            'run_id' => $runId,
            'conclusion' => $conclusion,
            'output_count' => count($outputs)
        ]);

        return new WorkflowRunResult([
            'success' => $conclusion === 'success',
            'run_id' => $runId,
            'conclusion' => $conclusion,
            'completed_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    private function generateCacheKey(string $workflowId, array $inputs): string
    {
        $inputHash = md5(json_encode($inputs));
        return "workflow-{$workflowId}-{$inputHash}";
    }
}
