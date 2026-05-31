<?php

declare(strict_types=1);

namespace Acme\Cron\Scheduler;

use Acme\Locking\LockManager;
use Acme\Cron\Repository\JobRepository;
use Psr\Log\LoggerInterface;

final class CronJobRunner
{
    public function __construct(
        private readonly LockManager $locks,
        private readonly JobRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runDue(string $jobName): bool
    {
        $lockKey = "cron:{$jobName}";
        $lock = $this->locks->acquire($lockKey, 300);
        if ($lock === null) {
            $this->logger->info('cron lock contended', ['job' => $jobName]);
            return false;
        }

        try {
            $job = $this->jobs->findByName($jobName);
            if ($job === null || $job->status() !== 'pending') {
                $this->logger->debug('cron not runnable', ['job' => $jobName, 'status' => $job?->status()]);
                return false;
            }

            $this->jobs->updateStatus($job->id(), 'running');
            $this->jobs->setLastRun($job->id(), new \DateTimeImmutable());
            $this->jobs->updateStatus($job->id(), 'completed');

            $this->logger->info('cron completed', ['job' => $jobName]);
            return true;
        } finally {
            $this->locks->release($lock);
        }
    }
}
