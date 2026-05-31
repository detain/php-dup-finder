<?php
declare(strict_types=1);

namespace Jenkins\Build\Service;

use Jenkins\Build\Repository\BuildRepository;
use Jenkins\Build\Repository\ChangeRepository;
use Jenkins\Build\Repository\ArtifactRepository;
use Jenkins\Build\Entity\Build;
use Jenkins\Build\Entity\BuildChange;
use Jenkins\Build\Entity\BuildArtifact;
use Jenkins\Build\Exception\BuildException;
use Jenkins\Build\Service\Executor\ShellExecutor;
use Jenkins\Build\Service\Notifier\BuildNotifier;
use Psr\Log\LoggerInterface;

final class BuildExecutionService
{
    private BuildRepository $buildRepo;
    private ChangeRepository $changeRepo;
    private ArtifactRepository $artifactRepo;
    private ShellExecutor $shellExecutor;
    private BuildNotifier $notifier;
    private LoggerInterface $logger;

    public function __construct(
        BuildRepository $buildRepo,
        ChangeRepository $changeRepo,
        ArtifactRepository $artifactRepo,
        ShellExecutor $shellExecutor,
        BuildNotifier $notifier,
        LoggerInterface $logger
    ) {
        $this->buildRepo = $buildRepo;
        $this->changeRepo = $changeRepo;
        $this->artifactRepo = $artifactRepo;
        $this->shellExecutor = $shellExecutor;
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    public function enqueueBuild(string $jobName, array $parameters): BuildEnqueueResult
    {
        $this->logger->info('Enqueueing build', [
            'job_name' => $jobName,
            'parameters' => $parameters
        ]);

        $job = $this->buildRepo->findJob($jobName);
        if ($job === null) {
            throw new BuildException("Job not found: {$jobName}");
        }

        $buildNumber = $this->buildRepo->getNextBuildNumber($jobName);
        $workspaceLock = $this->buildRepo->acquireWorkspaceLock($jobName);

        if ($workspaceLock === null) {
            throw new BuildException("Workspace is currently in use by another build");
        }

        $build = Build::create([
            'job_name' => $jobName,
            'build_number' => $buildNumber,
            'status' => 'queued',
            'parameters' => json_encode($parameters),
            'queued_at' => new \DateTimeImmutable(),
            'correlation_id' => $this->generateCorrelationId()
        ]);

        $savedBuild = $this->buildRepo->save($build);

        $this->buildRepo->attachWorkspaceLock($savedBuild->getId(), $workspaceLock->getId());

        $this->logger->debug('Build enqueued', [
            'build_id' => $savedBuild->getId(),
            'workspace_lock_id' => $workspaceLock->getId()
        ]);

        return new BuildEnqueueResult([
            'success' => true,
            'build_id' => $savedBuild->getId(),
            'build_number' => $buildNumber,
            'queue_position' => $this->buildRepo->getQueuePosition($savedBuild->getId())
        ]);
    }

    public function startBuild(string $buildId): BuildStartResult
    {
        $build = $this->buildRepo->findById($buildId);
        if ($build === null) {
            throw new BuildException("Build not found: {$buildId}");
        }

        if ($build->getStatus() !== 'queued') {
            throw new BuildException("Build cannot be started in status: {$build->getStatus()}");
        }

        $this->buildRepo->updateStatus($buildId, 'running', [
            'started_at' => new \DateTimeImmutable()
        ]);

        $workspace = $this->buildRepo->getWorkspaceForBuild($buildId);
        $changes = $this->changeRepo->getChangesSinceLastBuild($build->getJobName());

        $this->changeRepo->attachChangesToBuild($buildId, $changes);

        $this->logger->info('Build started', [
            'build_id' => $buildId,
            'workspace' => $workspace,
            'changes_count' => count($changes)
        ]);

        return new BuildStartResult([
            'success' => true,
            'build_id' => $buildId,
            'workspace' => $workspace,
            'changes_count' => count($changes)
        ]);
    }

    public function completeBuild(string $buildId, bool $success, string $output): BuildCompleteResult
    {
        $build = $this->buildRepo->findById($buildId);
        if ($build === null) {
            throw new BuildException("Build not found: {$buildId}");
        }

        if ($build->getStatus() !== 'running') {
            throw new BuildException("Build cannot be completed in status: {$build->getStatus()}");
        }

        $duration = (new \DateTimeImmutable())->getTimestamp() - $build->getStartedAt()->getTimestamp();

        $this->buildRepo->updateStatus($buildId, $success ? 'success' : 'failure', [
            'completed_at' => new \DateTimeImmutable(),
            'duration_seconds' => $duration,
            'output_log' => $output
        ]);

        if ($success) {
            $artifacts = $this->collectBuildArtifacts($build);
            $this->artifactRepo->saveArtifacts($artifacts);
            $this->buildRepo->attachArtifacts($buildId, $artifacts);
        }

        $this->buildRepo->releaseWorkspaceLock($build->getJobName());

        $this->notifier->notifyBuildComplete($build, $success);

        $this->logger->info('Build completed', [
            'build_id' => $buildId,
            'success' => $success,
            'duration_seconds' => $duration,
            'artifacts_count' => count($artifacts ?? [])
        ]);

        return new BuildCompleteResult([
            'success' => true,
            'build_id' => $buildId,
            'status' => $success ? 'success' : 'failure',
            'duration_seconds' => $duration,
            'artifacts_count' => count($artifacts ?? [])
        ]);
    }

    private function collectBuildArtifacts(Build $build): array
    {
        $artifactPatterns = $build->getJob()->getArtifactPatterns();
        $artifacts = [];

        foreach ($artifactPatterns as $pattern) {
            $found = $this->shellExecutor->findMatchingFiles($build->getWorkspace(), $pattern);

            foreach ($found as $filePath) {
                $artifacts[] = BuildArtifact::create([
                    'build_id' => $build->getId(),
                    'filename' => basename($filePath),
                    'relative_path' => $filePath,
                    'size_bytes' => filesize($filePath),
                    'checksum' => hash_file('sha256', $filePath),
                    'created_at' => new \DateTimeImmutable()
                ]);
            }
        }

        return $artifacts;
    }

    private function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
