<?php
declare(strict_types=1);

namespace App\Api\RateLimiting;

use App\Domain\Entity\RateLimitConfig;
use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Service\RateLimitServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class ApiRateLimitWorkflow
{
    public function __construct(
        private RateLimitRepositoryInterface $rateLimitRepository,
        private RateLimitServiceInterface $rateLimitService,
        private AnalyticsServiceInterface $analyticsService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function checkRateLimit(string $apiKey, string $endpoint): RateLimitResult
    {
        $this->logger->debug('Checking rate limit', ['api_key' => $apiKey, 'endpoint' => $endpoint]);

        $config = $this->getRateLimitConfig($apiKey, $endpoint);

        $currentUsage = $this->getCurrentUsage($apiKey, $endpoint);

        $result = $this->rateLimitService->evaluate($config, $currentUsage);

        $this->recordUsage($apiKey, $endpoint, $result);

        if ($result->isExceeded()) {
            $this->handleRateLimitExceeded($apiKey, $endpoint, $result);
        }

        $this->updateAnalytics($apiKey, $endpoint, $result);

        $this->logger->debug('Rate limit check completed', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'remaining' => $result->getRemaining(),
            'is_exceeded' => $result->isExceeded(),
        ]);

        return $result;
    }

    private function getRateLimitConfig(string $apiKey, string $endpoint): RateLimitConfig
    {
        $config = $this->rateLimitRepository->findConfig($apiKey, $endpoint);

        if ($config === null) {
            $config = $this->createDefaultConfig($apiKey, $endpoint);
        }

        $this->logger->debug('Rate limit config loaded', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'limit' => $config->getLimit(),
            'window' => $config->getWindowSeconds(),
        ]);

        return $config;
    }

    private function createDefaultConfig(string $apiKey, string $endpoint): RateLimitConfig
    {
        $defaultLimits = [
            'GET' => ['limit' => 1000, 'window' => 3600],
            'POST' => ['limit' => 100, 'window' => 3600],
            'PUT' => ['limit' => 100, 'window' => 3600],
            'DELETE' => ['limit' => 50, 'window' => 3600],
        ];

        $method = 'GET';
        $defaults = $defaultLimits[$method] ?? ['limit' => 100, 'window' => 3600];

        $config = new RateLimitConfig();
        $config->setApiKey($apiKey);
        $config->setEndpoint($endpoint);
        $config->setLimit($defaults['limit']);
        $config->setWindowSeconds($defaults['window']);
        $config->setCreatedAt(new \DateTimeImmutable());

        $this->rateLimitRepository->save($config);

        return $config;
    }

    private function getCurrentUsage(string $apiKey, string $endpoint): UsageData
    {
        $usage = $this->rateLimitRepository->getUsage($apiKey, $endpoint);

        $this->logger->debug('Current usage loaded', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'current_count' => $usage->getCount(),
            'window_start' => $usage->getWindowStart()?->format('Y-m-d H:i:s'),
        ]);

        return $usage;
    }

    private function recordUsage(string $apiKey, string $endpoint, RateLimitResult $result): void
    {
        $this->rateLimitRepository->recordRequest(
            $apiKey,
            $endpoint,
            $result->getCurrentCount(),
            $result->getWindowStart()
        );

        $this->logger->debug('Usage recorded', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'count' => $result->getCurrentCount(),
        ]);
    }

    private function handleRateLimitExceeded(string $apiKey, string $endpoint, RateLimitResult $result): void
    {
        $this->logger->warning('Rate limit exceeded', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'limit' => $result->getLimit(),
            'current' => $result->getCurrentCount(),
        ]);

        $this->notificationService->sendToAdmin(
            'rate_limit_exceeded',
            [
                'api_key' => $this->maskApiKey($apiKey),
                'endpoint' => $endpoint,
                'current_count' => $result->getCurrentCount(),
                'limit' => $result->getLimit(),
            ]
        );

        $this->recordAuditEvent('rate_limit_exceeded', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
            'count' => $result->getCurrentCount(),
            'limit' => $result->getLimit(),
        ]);
    }

    private function updateAnalytics(string $apiKey, string $endpoint, RateLimitResult $result): void
    {
        $this->analyticsService->trackEvent('api_rate_limit_check', [
            'api_key_hash' => hash('sha256', $apiKey),
            'endpoint' => $endpoint,
            'limit' => $result->getLimit(),
            'remaining' => $result->getRemaining(),
            'is_exceeded' => $result->isExceeded(),
        ]);

        $this->logger->debug('Analytics updated', [
            'api_key' => $apiKey,
            'endpoint' => $endpoint,
        ]);
    }

    private function maskApiKey(string $apiKey): string
    {
        if (strlen($apiKey) <= 8) {
            return '****';
        }
        return substr($apiKey, 0, 4) . '****' . substr($apiKey, -4);
    }

    private function recordAuditEvent(string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
