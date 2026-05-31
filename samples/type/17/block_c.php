<?php
declare(strict_types=1);

namespace RateLimiter\Services;

use Psr\Log\LoggerInterface;

final class BackgroundJobRateLimiter
{
    private const WINDOW_SIZE_SECONDS = 60;
    private const DEFAULT_MAX_JOBS = 100;
    private const EMAIL_JOB_MAX = 50;
    private const EXPORT_JOB_MAX = 10;
    private const IMPORT_JOB_MAX = 20;
    private const WEBHOOK_JOB_MAX = 75;

    private const CACHE_PREFIX = 'job_rate:';
    private const BYPASS_KEY = 'job_rate_bypass';
    private const CUSTOM_LIMIT_HEADER = 'X-RateLimit-Limit';
    private const REMAINING_HEADER = 'X-RateLimit-Remaining';
    private const RESET_HEADER = 'X-RateLimit-Reset';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function checkRateLimit(string $workerId, string $jobType = 'default'): RateLimitResult
    {
        $cacheKey = $this->buildCacheKey($workerId, $jobType);

        if ($this->isBypassed($workerId)) {
            return new RateLimitResult(
                allowed: true,
                limit: 0,
                remaining: 0,
                resetAt: 0,
                isBypassed: true
            );
        }

        $maxJobs = $this->getMaxJobsForType($jobType);
        $currentCount = $this->getCurrentCount($cacheKey);

        if ($currentCount >= $maxJobs) {
            $this->logger->warning('Job rate limit exceeded', [
                'worker_id' => $workerId,
                'job_type' => $jobType,
                'current' => $currentCount,
                'max' => $maxJobs,
            ]);

            $resetAt = $this->getWindowResetTime();

            return new RateLimitResult(
                allowed: false,
                limit: $maxJobs,
                remaining: 0,
                resetAt: $resetAt,
                retryAfter: $resetAt - time()
            );
        }

        $this->incrementCount($cacheKey);
        $remaining = $maxJobs - $currentCount - 1;
        $resetAt = $this->getWindowResetTime();

        return new RateLimitResult(
            allowed: true,
            limit: $maxJobs,
            remaining: $remaining,
            resetAt: $resetAt,
            isBypassed: false
        );
    }

    public function getHeaders(string $workerId, string $jobType): array
    {
        $result = $this->checkRateLimit($workerId, $jobType);

        return [
            self::CUSTOM_LIMIT_HEADER => (string)$result->limit,
            self::REMAINING_HEADER => (string)$result->remaining,
            self::RESET_HEADER => (string)$result->resetAt,
        ];
    }

    public function getCurrentCount(string $cacheKey): int
    {
        $cached = apcu_fetch($cacheKey, $success);
        return $success ? (int)$cached : 0;
    }

    public function incrementCount(string $cacheKey): void
    {
        $currentCount = $this->getCurrentCount($cacheKey);
        apcu_store($cacheKey, (string)($currentCount + 1), self::WINDOW_SIZE_SECONDS);
    }

    public function resetLimit(string $workerId, string $jobType = 'default'): void
    {
        $cacheKey = $this->buildCacheKey($workerId, $jobType);
        apcu_delete($cacheKey);

        $this->logger->info('Job rate limit reset', [
            'worker_id' => $workerId,
            'job_type' => $jobType,
        ]);
    }

    private function buildCacheKey(string $workerId, string $jobType): string
    {
        return self::CACHE_PREFIX . $jobType . ':' . $workerId;
    }

    private function getMaxJobsForType(string $jobType): int
    {
        return match ($jobType) {
            'email' => self::EMAIL_JOB_MAX,
            'export' => self::EXPORT_JOB_MAX,
            'import' => self::IMPORT_JOB_MAX,
            'webhook' => self::WEBHOOK_JOB_MAX,
            default => self::DEFAULT_MAX_JOBS,
        };
    }

    private function getWindowResetTime(): int
    {
        return time() + self::WINDOW_SIZE_SECONDS;
    }

    private function isBypassed(string $workerId): bool
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $workerId;
        return apcu_fetch($bypassKey, $success) !== false;
    }

    public function bypass(string $workerId, int $durationSeconds = 3600): void
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $workerId;
        apcu_store($bypassKey, '1', $durationSeconds);

        $this->logger->info('Job rate limit bypass granted', [
            'worker_id' => $workerId,
            'duration' => $durationSeconds,
        ]);
    }

    public function removeBypass(string $workerId): void
    {
        $bypassKey = self::CACHE_PREFIX . self::BYPASS_KEY . ':' . $workerId;
        apcu_delete($bypassKey);
    }
}
