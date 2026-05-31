<?php

declare(strict_types=1);

namespace App\Session;

use App\Entity\PrincipalInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class UnifiedSessionManager
{
    /** @var array<string, SessionConfig> */
    private array $configs = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeConfigs();
    }

    private function initializeConfigs(): void
    {
        $this->configs['auth'] = new SessionConfig(
            timeout: 3600,
            absoluteTimeout: 86400,
            tokenHeader: 'X-Session-Token',
            repositoryClass: \App\Repository\SessionRepository::class,
            entityClass: \App\Entity\Session::class,
        );

        $this->configs['api'] = new SessionConfig(
            timeout: 1800,
            absoluteTimeout: 604800,
            tokenHeader: 'Authorization',
            repositoryClass: \App\Repository\AccessTokenRepository::class,
            entityClass: \App\Entity\AccessToken::class,
        );

        $this->configs['worker'] = new SessionConfig(
            timeout: 300,
            absoluteTimeout: 43200,
            tokenHeader: null,
            repositoryClass: \App\Repository\WorkerSessionRepository::class,
            entityClass: \App\Entity\WorkerSession::class,
        );
    }

    public function validate(string $sessionType, mixed $tokenOrId, Request $request): ?PrincipalInterface
    {
        $config = $this->configs[$sessionType] ?? null;

        if ($config === null) {
            $this->logger->warning('Unknown session type', ['type' => $sessionType]);
            return null;
        }

        $session = $this->findSession($config, $tokenOrId);

        if ($session === null) {
            return null;
        }

        if ($this->isSessionExpired($session, $config)) {
            $this->markSessionExpired($config, $session);
            return null;
        }

        if ($this->exceededIdleTimeout($session, $config)) {
            $this->logger->info("{$sessionType} session timed out due to inactivity");
            $this->markSessionExpired($config, $session);
            return null;
        }

        if ($this->exceededAbsoluteTimeout($session, $config)) {
            $this->logger->info("{$sessionType} session reached absolute timeout");
            $this->markSessionExpired($config, $session);
            return null;
        }

        $principal = $this->resolvePrincipal($config, $session);

        if ($principal === null) {
            return null;
        }

        $this->refreshSession($config, $session);

        return $principal;
    }

    private function findSession(SessionConfig $config, mixed $tokenOrId): ?object
    {
        $method = $config->tokenHeader === null ? 'findActive' : 'findActiveByToken';
        return $config->repository->{$method}($tokenOrId);
    }

    private function isSessionExpired(object $session, SessionConfig $config): bool
    {
        return $session->isExpired() || $session->isTerminated();
    }

    private function exceededIdleTimeout(object $session, SessionConfig $config): bool
    {
        $lastActivity = $session->getLastActivityAt() ?? $session->getLastHeartbeatAt() ?? $session->getLastUsedAt();

        if ($lastActivity === null) {
            return false;
        }

        return (time() - $lastActivity->getTimestamp()) > $config->timeout;
    }

    private function exceededAbsoluteTimeout(object $session, SessionConfig $config): bool
    {
        $createdAt = $session->getCreatedAt() ?? $session->getStartedAt();

        if ($createdAt === null) {
            return false;
        }

        return (time() - $createdAt->getTimestamp()) > $config->absoluteTimeout;
    }

    private function resolvePrincipal(SessionConfig $config, object $session): ?PrincipalInterface
    {
        $principalId = $session->getUserId() ?? $session->getClientId() ?? $session->getWorkerId();
        $principalClass = $config->principalClass;

        $principal = $principalClass::find($principalId);

        if ($principal === null || !$principal->isActive()) {
            $this->logger->warning('Principal not found or inactive', [
                'id' => $principalId,
                'class' => $principalClass,
            ]);
            return null;
        }

        return $principal;
    }

    private function refreshSession(SessionConfig $config, object $session): void
    {
        $session->recordActivity();
        if (method_exists($session, 'recordUsage')) {
            $session->recordUsage();
        }
        if (method_exists($session, 'recordHeartbeat')) {
            $session->recordHeartbeat();
        }
        $config->repository->save($session);
    }

    private function markSessionExpired(SessionConfig $config, object $session): void
    {
        if (method_exists($session, 'markExpired')) {
            $session->markExpired();
        }
        if (method_exists($session, 'markTerminated')) {
            $session->markTerminated();
        }
        $config->repository->save($session);
    }
}

final class SessionConfig
{
    public function __construct(
        public readonly int $timeout,
        public readonly int $absoluteTimeout,
        public readonly ?string $tokenHeader,
        public readonly string $repositoryClass,
        public readonly string $entityClass,
    ) {}
}
