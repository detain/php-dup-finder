<?php

declare(strict_types=1);

namespace Acme\Common\Async;

use Psr\Log\LoggerInterface;

enum JobPhase: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}

/**
 * @phpstan-type StatusResult array{phase: JobPhase, result?: string, error?: string}
 */
final class AsyncJobCoordinator
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param callable(): string $dispatch        Returns job id.
     * @param callable(string): array $poll       jobId -> StatusResult
     * @param callable(string, string): void $onSuccess  jobId, result
     * @param callable(string, string): void $onFailure  jobId, error
     */
    public function run(
        string $label,
        callable $dispatch,
        callable $poll,
        callable $onSuccess,
        callable $onFailure,
        int $intervalSec,
        int $timeoutSec,
    ): string {
        $jobId = $dispatch();
        $this->logger->info("{$label} dispatched", ['job_id' => $jobId]);

        $start = time();
        while (true) {
            sleep($intervalSec);
            $status = $poll($jobId);

            if ($status['phase'] === JobPhase::Succeeded) {
                $onSuccess($jobId, $status['result']);
                $this->logger->info("{$label} succeeded", ['job_id' => $jobId]);
                return $status['result'];
            }
            if ($status['phase'] === JobPhase::Failed) {
                $onFailure($jobId, $status['error']);
                throw new \RuntimeException("{$label} failed: {$status['error']}");
            }
            if ((time() - $start) > $timeoutSec) {
                $onFailure($jobId, 'timeout');
                throw new \RuntimeException("{$label} timed out");
            }
        }
    }
}
