<?php

declare(strict_types=1);

namespace App\Queue\Sync;

use App\Entity\Job;
use App\Repository\JobRepository;
use App\Service\JobProcessor;
use Psr\Log\LoggerInterface;

final class SyncQueueWorker
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly JobProcessor $jobProcessor,
        private readonly LoggerInterface $logger,
    ) {}

    public function processNextJob(): ?Job
    {
        $job = $this->jobRepository->findNextPendingJob();

        if ($job === null) {
            $this->logger->debug('No pending jobs in sync queue');
            return null;
        }

        $this->logger->info('Processing sync queue job', [
            'job_id' => $job->getId(),
            'type' => $job->getType(),
        ]);

        try {
            $job->setStatus('processing');
            $job->setStartedAt(new \DateTimeImmutable());
            $this->jobRepository->save($job);

            $result = $this->jobProcessor->process($job);

            $job->setStatus('completed');
            $job->setCompletedAt(new \DateTimeImmutable());
            $job->setResult($result);
            $this->jobRepository->save($job);

            $this->logger->info('Sync queue job completed', [
                'job_id' => $job->getId(),
                'duration_ms' => $job->getDurationMs(),
            ]);

            return $job;
        } catch (\Exception $e) {
            $job->setStatus('failed');
            $job->setCompletedAt(new \DateTimeImmutable());
            $job->setError($e->getMessage());
            $this->jobRepository->save($job);

            $this->logger->error('Sync queue job failed', [
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

        $this->logger->info('Sync queue batch processed', [
            'processed' => $processed,
        ]);

        return $processed;
    }

    public function retryFailedJob(int $jobId): bool
    {
        $job = $this->jobRepository->findById($jobId);

        if ($job === null) {
            return false;
        }

        if ($job->getStatus() !== 'failed') {
            return false;
        }

        $job->setStatus('pending');
        $job->setStartedAt(null);
        $job->setCompletedAt(null);
        $job->setError(null);
        $this->jobRepository->save($job);

        $this->logger->info('Sync queue job retry scheduled', [
            'job_id' => $jobId,
        ]);

        return true;
    }
}
