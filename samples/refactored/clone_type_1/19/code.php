<?php

declare(strict_types=1);

namespace App\Queue;

use App\Entity\Job;
use App\Repository\JobRepository;
use App\Service\JobProcessor;
use Psr\Log\LoggerInterface;

interface QueueWorkerInterface
{
    public function processNextJob(): ?Job;
    public function processJobs(int $maxJobs = 10): int;
    public function retryFailedJob(int $jobId): bool;
}

abstract class AbstractQueueWorker implements QueueWorkerInterface
{
    public function __construct(
        protected readonly JobRepository $jobRepository,
        protected readonly JobProcessor $jobProcessor,
        protected readonly LoggerInterface $logger,
    ) {}

    public function processNextJob(): ?Job
    {
        $job = $this->jobRepository->findNextPendingJob();

        if ($job === null) {
            $this->logger->debug('No pending jobs in queue');
            return null;
        }

        $this->logger->info('Processing queue job', [
            'job_id' => $job->getId(),
            'type' => $job->getType(),
        ]);

        try {
            $this->markJobAsProcessing($job);
            $result = $this->jobProcessor->process($job);
            $this->markJobAsCompleted($job, $result);

            $this->logger->info('Queue job completed', [
                'job_id' => $job->getId(),
                'duration_ms' => $job->getDurationMs(),
            ]);

            return $job;
        } catch (\Exception $e) {
            $this->markJobAsFailed($job, $e);

            $this->logger->error('Queue job failed', [
                'job_id' => $job->getId(),
                'error' => $e->getMessage(),
            ]);

            return $job;
        }
    }

    public function processJobs(int $maxJobs = 10): int
    {
        $processed = 0;

        for ($i = 0; $i < $maxJobs; $i++) {
            $job = $this->processNextJob();
            if ($job === null) {
                break;
            }
            $processed++;
        }

        $this->logger->info('Queue batch processed', [
            'processed' => $processed,
        ]);

        return $processed;
    }

    public function retryFailedJob(int $jobId): bool
    {
        $job = $this->jobRepository->findById($jobId);

        if ($job === null || $job->getStatus() !== 'failed') {
            return false;
        }

        $this->resetJobForRetry($job);
        $this->jobRepository->save($job);

        $this->logger->info('Queue job retry scheduled', [
            'job_id' => $jobId,
        ]);

        return true;
    }

    protected function markJobAsProcessing(Job $job): void
    {
        $job->setStatus('processing');
        $job->setStartedAt(new \DateTimeImmutable());
        $this->jobRepository->save($job);
    }

    protected function markJobAsCompleted(Job $job, $result): void
    {
        $job->setStatus('completed');
        $job->setCompletedAt(new \DateTimeImmutable());
        $job->setResult($result);
        $this->jobRepository->save($job);
    }

    protected function markJobAsFailed(Job $job, \Exception $e): void
    {
        $job->setStatus('failed');
        $job->setCompletedAt(new \DateTimeImmutable());
        $job->setError($e->getMessage());
        $this->jobRepository->save($job);
    }

    protected function resetJobForRetry(Job $job): void
    {
        $job->setStatus('pending');
        $job->setStartedAt(null);
        $job->setCompletedAt(null);
        $job->setError(null);
    }
}
