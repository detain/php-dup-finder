<?php
declare(strict_types=1);

namespace App\RateLimit\Service;

use App\RateLimit\Repository\TokenBucketRepository;
use App\RateLimit\Entity\TokenBucket;
use Psr\Log\LoggerInterface;

final class RateLimitService
{
    public const DEFAULT_REQUESTS_PER_MINUTE = 60;
    public const DEFAULT_REQUESTS_PER_HOUR = 1000;
    public const DEFAULT_REQUESTS_PER_DAY = 10000;
    public const DEFAULT_BURST_SIZE = 10;

    public const AUTH_ENDPOINT_RATE_LIMIT = 5;
    public const AUTH_ENDPOINT_WINDOW_SECONDS = 60;

    public const API_ENDPOINT_RATE_LIMIT = 100;
    public const API_ENDPOINT_WINDOW_SECONDS = 60;

    public const WEBHOOK_RATE_LIMIT = 30;
    public const WEBHOOK_WINDOW_SECONDS = 60;

    private TokenBucketRepository $bucketRepo;
    private LoggerInterface $logger;

    public function __construct(
        TokenBucketRepository $bucketRepo,
        LoggerInterface $logger
    ) {
        $this->bucketRepo = $bucketRepo;
        $this->logger = $logger;
    }

    public function checkRateLimit(string $identifier, string $endpointType): RateLimitResult
    {
        $limit = $this->getRateLimitForEndpoint($endpointType);
        $windowSeconds = $this->getWindowSecondsForEndpoint($endpointType);

        $bucket = $this->bucketRepo->findOrCreate($identifier, $endpointType, [
            'capacity' => $limit,
            ' refill_rate' => $limit / $windowSeconds,
            'burst_size' => $this->getBurstSize($endpointType)
        ]);

        if (!$bucket->consume(1)) {
            $retryAfter = $bucket->getRetryAfter();
            $this->logger->warning('Rate limit exceeded', [
                'identifier' => $identifier,
                'endpoint' => $endpointType,
                'retry_after' => $retryAfter
            ]);

            return new RateLimitResult([
                'allowed' => false,
                'remaining' => 0,
                'limit' => $limit,
                'retry_after' => $retryAfter
            ]);
        }

        $this->bucketRepo->save($bucket);

        return new RateLimitResult([
            'allowed' => true,
            'remaining' => $bucket->getAvailableTokens(),
            'limit' => $limit,
            'retry_after' => 0
        ]);
    }

    public function checkAuthEndpointLimit(string $identifier): RateLimitResult
    {
        return $this->checkRateLimit($identifier, 'auth');
    }

    public function checkApiEndpointLimit(string $identifier): RateLimitResult
    {
        return $this->checkRateLimit($identifier, 'api');
    }

    public function checkWebhookLimit(string $identifier): RateLimitResult
    {
        return $this->checkRateLimit($identifier, 'webhook');
    }

    public function getRateLimitForEndpoint(string $endpointType): int
    {
        return match ($endpointType) {
            'auth' => self::AUTH_ENDPOINT_RATE_LIMIT,
            'api' => self::API_ENDPOINT_RATE_LIMIT,
            'webhook' => self::WEBHOOK_RATE_LIMIT,
            default => self::DEFAULT_REQUESTS_PER_MINUTE
        };
    }

    public function getWindowSecondsForEndpoint(string $endpointType): int
    {
        return match ($endpointType) {
            'auth' => self::AUTH_ENDPOINT_WINDOW_SECONDS,
            'api' => self::API_ENDPOINT_WINDOW_SECONDS,
            'webhook' => self::WEBHOOK_WINDOW_SECONDS,
            default => 60
        };
    }

    public function getBurstSize(string $endpointType): int
    {
        return match ($endpointType) {
            'auth' => 3,
            'api' => self::DEFAULT_BURST_SIZE,
            'webhook' => 5,
            default => self::DEFAULT_BURST_SIZE
        };
    }

    public function resetRateLimit(string $identifier, string $endpointType): void
    {
        $bucket = $this->bucketRepo->find($identifier, $endpointType);
        if ($bucket !== null) {
            $bucket->reset();
            $this->bucketRepo->save($bucket);
        }
    }

    public function getRateLimitStatus(string $identifier): array
    {
        $status = [];

        foreach (['auth', 'api', 'webhook'] as $endpointType) {
            $bucket = $this->bucketRepo->find($identifier, $endpointType);
            $limit = $this->getRateLimitForEndpoint($endpointType);

            $status[$endpointType] = [
                'limit' => $limit,
                'remaining' => $bucket?->getAvailableTokens() ?? $limit,
                'reset_at' => $bucket?->getResetTime()?->format('c')
            ];
        }

        return $status;
    }
}
