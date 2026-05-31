<?php
declare(strict_types=1);

namespace CircleCI\Pipeline\Service;

use CircleCI\Pipeline\Repository\PipelineRepository;
use CircleCI\Pipeline\Repository\StepRepository;
use CircleCI\Pipeline\Repository\ArtifactRepository;
use CircleCI\Pipeline\Entity\Pipeline;
use CircleCI\Pipeline\Entity\PipelineStep;
use CircleCI\Pipeline\Entity\Artifact;
use CircleCI\Pipeline\Exception\PipelineException;
use CircleCI\Pipeline\Service\Executor\StepExecutor;
use CircleCI\Pipeline\Service\Cache\ArtifactCache;
use Psr\Log\LoggerInterface;

final class PipelineExecutionService
{
    private PipelineRepository $pipelineRepo;
    private StepRepository $stepRepo;
    private ArtifactRepository $artifactRepo;
    private StepExecutor $executor;
    private ArtifactCache $artifactCache;
    private LoggerInterface $logger;

    public function __construct(
        PipelineRepository $pipelineRepo,
        StepRepository $stepRepo,
        ArtifactRepository $artifactRepo,
        StepExecutor $executor,
        ArtifactCache $artifactCache,
        LoggerInterface $logger
    ) {
        $this->pipelineRepo = $pipelineRepo;
        $this->stepRepo = $stepRepo;
        $this->artifactRepo = $artifactRepo;
        $this->executor = $executor;
        $this->artifactCache = $artifactCache;
        $this->logger = $logger;
    }

    public function createPipeline(string $projectId, array $config): PipelineResult
    {
        $this->logger->info('Creating pipeline', ['project_id' => $projectId]);

        $project = $this->pipelineRepo->findProject($projectId);
        if ($project === null) {
            throw new PipelineException("Project not found: {$projectId}");
        }

        $pipelineNumber = $this->pipelineRepo->getNextPipelineNumber($projectId);

        $pipeline = Pipeline::create([
            'project_id' => $projectId,
            'pipeline_number' => $pipelineNumber,
            'status' => 'created',
            'config_sha' => hash('sha256', json_encode($config)),
            'triggered_by' => $config['triggered_by'] ?? 'api',
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedPipeline = $this->pipelineRepo->save($pipeline);
        $this->logger->debug('Pipeline record created', ['pipeline_id' => $savedPipeline->getId()]);

        foreach ($config['workflows'] as $workflowConfig) {
            $this->createWorkflowSteps($savedPipeline->getId(), $workflowConfig);
        }

        $this->pipelineRepo->updateStatus($savedPipeline->getId(), 'queued');

        $this->logger->info('Pipeline created successfully', [
            'pipeline_id' => $savedPipeline->getId(),
            'workflows_count' => count($config['workflows'])
        ]);

        return new PipelineResult([
            'success' => true,
            'pipeline_id' => $savedPipeline->getId(),
            'pipeline_number' => $pipelineNumber
        ]);
    }

    public function executePipeline(string $pipelineId): ExecutionResult
    {
        $pipeline = $this->pipelineRepo->findById($pipelineId);
        if ($pipeline === null) {
            throw new PipelineException("Pipeline not found: {$pipelineId}");
        }

        if ($pipeline->getStatus() !== 'queued') {
            throw new PipelineException("Pipeline cannot be executed in status: {$pipeline->getStatus()}");
        }

        $executionLock = $this->pipelineRepo->acquireExecutionLock($pipelineId);
        if ($executionLock === null) {
            throw new PipelineException("Could not acquire execution lock for pipeline: {$pipelineId}");
        }

        $this->pipelineRepo->updateStatus($pipelineId, 'running', [
            'started_at' => new \DateTimeImmutable()
        ]);

        $this->logger->debug('Execution lock acquired', ['pipeline_id' => $pipelineId]);

        try {
            $steps = $this->stepRepo->findStepsForPipeline($pipelineId);

            $results = [];
            foreach ($steps as $step) {
                $this->logger->info('Executing pipeline step', [
                    'pipeline_id' => $pipelineId,
                    'step_id' => $step->getId(),
                    'step_name' => $step->getName()
                ]);

                $stepResult = $this->executor->execute($step);

                $this->stepRepo->recordStepResult($step->getId(), [
                    'status' => $stepResult->getStatus(),
                    'output' => $stepResult->getOutput(),
                    'exit_code' => $stepResult->getExitCode(),
                    'completed_at' => new \DateTimeImmutable()
                ]);

                $results[$step->getId()] = $stepResult;

                if (!$stepResult->isSuccess() && $step->isCritical()) {
                    $this->logger->warning('Critical step failed, stopping pipeline', [
                        'step_id' => $step->getId()
                    ]);
                    break;
                }
            }

            $allSuccessful = array_reduce(
                $results,
                fn($carry, $r) => $carry && $r->isSuccess(),
                true
            );

            $this->pipelineRepo->updateStatus($pipelineId, $allSuccessful ? 'success' : 'failed', [
                'completed_at' => new \DateTimeImmutable()
            ]);

            $artifacts = $this->collectArtifacts($pipelineId);
            $this->pipelineRepo->attachArtifacts($pipelineId, $artifacts);

            $this->pipelineRepo->releaseExecutionLock($executionLock);

            $this->logger->info('Pipeline execution completed', [
                'pipeline_id' => $pipelineId,
                'success' => $allSuccessful,
                'steps_completed' => count($results)
            ]);

            return new ExecutionResult([
                'success' => $allSuccessful,
                'pipeline_id' => $pipelineId,
                'status' => $allSuccessful ? 'success' : 'failed',
                'steps_completed' => count($results),
                'artifacts_count' => count($artifacts)
            ]);

        } catch (\Throwable $e) {
            $this->pipelineRepo->updateStatus($pipelineId, 'failed', [
                'error' => $e->getMessage(),
                'failed_at' => new \DateTimeImmutable()
            ]);

            $this->pipelineRepo->releaseExecutionLock($executionLock);

            $this->logger->error('Pipeline execution failed', [
                'pipeline_id' => $pipelineId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function createWorkflowSteps(string $pipelineId, array $workflowConfig): void
    {
        $workflowName = $workflowConfig['name'];

        foreach ($workflowConfig['jobs'] as $jobIndex => $jobConfig) {
            $step = PipelineStep::create([
                'pipeline_id' => $pipelineId,
                'workflow_name' => $workflowName,
                'name' => $jobConfig['name'],
                'command' => $jobConfig['command'] ?? null,
                'environment' => $jobConfig['environment'] ?? [],
                'order' => $jobIndex,
                'critical' => $jobConfig['critical'] ?? false,
                'status' => 'pending',
                'created_at' => new \DateTimeImmutable()
            ]);

            $this->stepRepo->save($step);
        }
    }

    private function collectArtifacts(string $pipelineId): array
    {
        $artifacts = $this->artifactRepo->findByPipelineId($pipelineId);

        foreach ($artifacts as $artifact) {
            $this->artifactCache->store($artifact);
        }

        return $artifacts;
    }
}
