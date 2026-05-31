<?php
declare(strict_types=1);

namespace App\Api\RateLimiting;

use App\Domain\Entity\RateLimitConfig;
use App\Domain\Repository\RateLimitRepositoryInterface;
use App\Domain\Service\RateLimitServiceInterface;
use App\Domain\Service\AnalyticsServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class GlobalRateLimitWorkflow
{
    public function __construct(
        private RateLimitRepositoryInterface $rateLimitRepository,
        private RateLimitServiceInterface $rateLimitService,
        private AnalyticsServiceInterface $analyticsService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function evaluate(string $identifier, string $scope): GlobalLimitResult
    {
        $this->logger->debug('Evaluating global rate limit', [
            'identifier' => $identifier,
            'scope' => $scope,
        ]);

        $config = $this->retrieveConfig($identifier, $scope);

        $currentUsage = $this->retrieveUsage($identifier, $scope);

        $result = $this->rateLimitService->evaluateGlobal($config, $currentUsage);

        $this->persistRequest($identifier, $scope, $result);

        if ($result->isGlobalLimitExceeded()) {
            $this->triggerExceededHandler($identifier, $scope, $result);
        }

        $this->emitMetrics($identifier, $scope, $result);

        $this->logger->debug('Global rate limit evaluation completed', [
            'identifier' => $identifier,
            'scope' => $scope,
            'remaining' => $result->getRemaining(),
            'is_exceeded' => $result->isGlobalLimitExceeded(),
        ]);

        return $result;
    }

    private function retrieveConfig(string $identifier, string $scope): RateLimitConfig
    {
        $config = $this->rateLimitRepository->findGlobalConfig($identifier, $scope);

        if ($config === null) {
            $config = $this->initializeConfig($identifier, $scope);
        }

        $this->logger->debug('Global config retrieved', [
            'identifier' => $identifier,
            'scope' => $scope,
            'limit' => $config->getLimit(),
            'window' => $config->getWindowSeconds(),
        ]);

        return $config;
    }

    private function initializeConfig(string $identifier, string $scope): RateLimitConfig
    {
        $scopeDefaults = [
            'ip' => ['limit' => 10000, 'window' => 3600],
            'user' => ['limit' => 50000, 'window' => 3600],
            'api_key' => ['limit' => 100000, 'window' => 3600],
            'org' => ['limit' => 500000, 'window' => 3600],
        ];

        $defaults = $scopeDefaults[$scope] ?? ['limit' => 1000, 'window' => 3600];

        $config = new RateLimitConfig();
        $config->setApiKey($identifier);
        $config->setEndpoint($scope);
        $config->setLimit($defaults['limit']);
        $config->setWindowSeconds($defaults['window']);
        $config->setCreatedAt(new \DateTimeImmutable());

        $this->rateLimitRepository->save($config);

        return $config;
    }

    private function retrieveUsage(string $identifier, string $scope): UsageData
    {
        $usage = $this->rateLimitRepository->getGlobalUsage($identifier, $scope);

        $this->logger->debug('Global usage retrieved', [
            'identifier' => $identifier,
            'scope' => $scope,
            'count' => $usage->getCount(),
        ]);

        return $usage;
    }

    private function persistRequest(string $identifier, string $scope, GlobalLimitResult $result): void
    {
        $this->rateLimitRepository->recordGlobalRequest(
            $identifier,
            $scope,
            $result->getCurrentCount(),
            $result->getWindowStart()
        );

        $this->logger->debug('Global request persisted', [
            'identifier' => $identifier,
            'scope' => $scope,
            'count' => $result->getCurrentCount(),
        ]);
    }

    private function triggerExceededHandler(string $identifier, string $scope, GlobalLimitResult $result): void
    {
        $this->logger->warning('Global limit exceeded', [
            'identifier' => $identifier,
            'scope' => $scope,
            'limit' => $result->getLimit(),
            'current' => $result->getCurrentCount(),
        ]);

        $this->notificationService->sendToAdmin(
            'global_limit_exceeded',
            [
                'identifier_hash' => hash('sha256', $identifier),
                'scope' => $scope,
                'current_count' => $result->getCurrentCount(),
                'limit' => $result->getLimit(),
            ]
        );

        $this->writeAuditEntry('global_limit_exceeded', [
            'identifier_hash' => hash('sha256', $identifier),
            'scope' => $scope,
            'count' => $result->getCurrentCount(),
            'limit' => $result->getLimit(),
        ]);
    }

    private function emitMetrics(string $identifier, string $scope, GlobalLimitResult $result): void
    {
        $this->analyticsService->trackEvent('global_rate_limit', [
            'identifier_hash' => hash('sha256', $identifier),
            'scope' => $scope,
            'limit' => $result->getLimit(),
            'remaining' => $result->getRemaining(),
            'exceeded' => $result->isGlobalLimitExceeded(),
        ]);

        $this->logger->debug('Global metrics emitted', [
            'identifier' => $identifier,
            'scope' => $scope,
        ]);
    }

    private function writeAuditEntry(string $event, array $data = []): void
    {
        $this->logger->info('Audit entry', array_merge([
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
