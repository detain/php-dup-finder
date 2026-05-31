<?php
declare(strict_types=1);

namespace App\Api\RateLimiting;

use App\Domain\Entity\RateLimitConfig;
use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Service\RateLimitServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class EndpointRateLimitWorkflow
{
    public function __construct(
        private RateLimitRepositoryInterface $rateLimitRepository,
        private RateLimitServiceInterface $rateLimitService,
        private AnalyticsServiceInterface $analyticsService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function checkLimit(string $clientId, string $endpoint, string $method): LimitCheckResult
    {
        $this->logger->debug('Checking endpoint rate limit', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'method' => $method,
        ]);

        $config = $this->loadConfig($clientId, $endpoint, $method);

        $usage = $this->fetchUsage($clientId, $endpoint);

        $result = $this->rateLimitService->evaluate($config, $usage);

        $this->storeUsage($clientId, $endpoint, $result);

        if ($result->isLimitExceeded()) {
            $this->onLimitExceeded($clientId, $endpoint, $result);
        }

        $this->trackMetrics($clientId, $endpoint, $result);

        $this->logger->debug('Endpoint rate limit check completed', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'remaining' => $result->getRemaining(),
            'exceeded' => $result->isLimitExceeded(),
        ]);

        return $result;
    }

    private function loadConfig(string $clientId, string $endpoint, string $method): RateLimitConfig
    {
        $config = $this->rateLimitRepository->findByClientAndEndpoint($clientId, $endpoint, $method);

        if ($config === null) {
            $config = $this->buildDefaultConfig($clientId, $endpoint, $method);
        }

        $this->logger->debug('Endpoint config loaded', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'limit' => $config->getLimit(),
            'window' => $config->getWindowSeconds(),
        ]);

        return $config;
    }

    private function buildDefaultConfig(string $clientId, string $endpoint, string $method): RateLimitConfig
    {
        $tierLimits = [
            'free' => ['limit' => 100, 'window' => 3600],
            'basic' => ['limit' => 1000, 'window' => 3600],
            'pro' => ['limit' => 10000, 'window' => 3600],
            'enterprise' => ['limit' => 100000, 'window' => 3600],
        ];

        $tier = $this->rateLimitRepository->getClientTier($clientId);
        $defaults = $tierLimits[$tier] ?? $tierLimits['free'];

        $config = new RateLimitConfig();
        $config->setApiKey($clientId);
        $config->setEndpoint($endpoint);
        $config->setLimit($defaults['limit']);
        $config->setWindowSeconds($defaults['window']);
        $config->setCreatedAt(new \DateTimeImmutable());

        $this->rateLimitRepository->save($config);

        return $config;
    }

    private function fetchUsage(string $clientId, string $endpoint): UsageData
    {
        $usage = $this->rateLimitRepository->getCurrentUsage($clientId, $endpoint);

        $this->logger->debug('Endpoint usage fetched', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'count' => $usage->getCount(),
        ]);

        return $usage;
    }

    private function storeUsage(string $clientId, string $endpoint, LimitCheckResult $result): void
    {
        $this->rateLimitRepository->record(
            $clientId,
            $endpoint,
            $result->getCurrentCount(),
            $result->getWindowStart()
        );

        $this->logger->debug('Endpoint usage stored', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'count' => $result->getCurrentCount(),
        ]);
    }

    private function onLimitExceeded(string $clientId, string $endpoint, LimitCheckResult $result): void
    {
        $this->logger->warning('Endpoint limit exceeded', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'limit' => $result->getLimit(),
            'current' => $result->getCurrentCount(),
        ]);

        $this->notificationService->sendToAdmin(
            'endpoint_limit_exceeded',
            [
                'client_id' => $this->maskClientId($clientId),
                'endpoint' => $endpoint,
                'current_count' => $result->getCurrentCount(),
                'limit' => $result->getLimit(),
            ]
        );

        $this->logAuditEvent('endpoint_limit_exceeded', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'count' => $result->getCurrentCount(),
            'limit' => $result->getLimit(),
        ]);
    }

    private function trackMetrics(string $clientId, string $endpoint, LimitCheckResult $result): void
    {
        $this->analyticsService->trackEvent('endpoint_rate_limit', [
            'client_id_hash' => hash('sha256', $clientId),
            'endpoint' => $endpoint,
            'limit' => $result->getLimit(),
            'remaining' => $result->getRemaining(),
            'exceeded' => $result->isLimitExceeded(),
        ]);

        $this->logger->debug('Metrics tracked', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
        ]);
    }

    private function maskClientId(string $clientId): string
    {
        if (strlen($clientId) <= 8) {
            return '****';
        }
        return substr($clientId, 0, 4) . '****' . substr($clientId, -4);
    }

    private function logAuditEvent(string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
