<?php
declare(strict_types=1);

namespace App\Session\Service;

use App\Session\Repository\SessionRepository;
use App\Session\Entity\Session;
use Psr\Log\LoggerInterface;

final class SessionService
{
    public const DEFAULT_SESSION_TIMEOUT_SECONDS = 1800;
    public const DEFAULT_EXTENDED_TIMEOUT_SECONDS = 7200;
    public const DEFAULT_REMEMBER_ME_TIMEOUT_SECONDS = 1209600;
    public const DEFAULT_SLIDING_EXPIRATION = true;
    public const DEFAULT_MAX_CONCURRENT_SESSIONS = 3;

    public const SESSION_COOKIE_NAME = 'PHPSESSID';
    public const SESSION_COOKIE_LIFETIME = 0;
    public const SESSION_COOKIE_PATH = '/';
    public const SESSION_COOKIE_SECURE = true;
    public const SESSION_COOKIE_HTTPONLY = true;
    public const SESSION_COOKIE_SAMESITE = 'Lax';

    private SessionRepository $sessionRepo;
    private LoggerInterface $logger;

    public function __construct(
        SessionRepository $sessionRepo,
        LoggerInterface $logger
    ) {
        $this->sessionRepo = $sessionRepo;
        $this->logger = $logger;
    }

    public function createSession(string $userId, array $options = []): SessionResult
    {
        $isRememberMe = $options['remember_me'] ?? false;
        $isExtended = $options['extended_timeout'] ?? false;

        $activeSessions = $this->sessionRepo->countActiveSessionsForUser($userId);

        if ($activeSessions >= self::DEFAULT_MAX_CONCURRENT_SESSIONS) {
            if (!($options['force'] ?? false)) {
                throw new \RuntimeException(
                    'Maximum concurrent sessions reached. Please logout from another device.'
                );
            }

            $this->invalidateOldestSession($userId);
        }

        $timeout = $this->determineSessionTimeout($isRememberMe, $isExtended);

        $session = Session::create([
            'user_id' => $userId,
            'ip_address' => $options['ip_address'] ?? '0.0.0.0',
            'user_agent' => $options['user_agent'] ?? 'Unknown',
            'timeout_seconds' => $timeout,
            'sliding_expiration' => $options['sliding_expiration'] ?? self::DEFAULT_SLIDING_EXPIRATION,
            'created_at' => new \DateTimeImmutable(),
            'expires_at' => (new \DateTimeImmutable())->modify("+{$timeout} seconds"),
            'last_activity_at' => new \DateTimeImmutable()
        ]);

        $savedSession = $this->sessionRepo->save($session);

        $this->logger->info('Session created', [
            'session_id' => $savedSession->getId(),
            'user_id' => $userId,
            'timeout' => $timeout
        ]);

        return new SessionResult([
            'session_id' => $savedSession->getId(),
            'expires_at' => $savedSession->getExpiresAt()->format('c'),
            'timeout_seconds' => $timeout
        ]);
    }

    public function validateSession(string $sessionId): ValidationResult
    {
        $session = $this->sessionRepo->findById($sessionId);

        if ($session === null) {
            return new ValidationResult(false, 'Session not found');
        }

        if ($session->isExpired()) {
            $this->sessionRepo->delete($sessionId);
            return new ValidationResult(false, 'Session has expired');
        }

        if ($session->getUser()->isLocked()) {
            return new ValidationResult(false, 'Account is locked');
        }

        if ($session->getSlidingExpiration()) {
            $newExpiry = (new \DateTimeImmutable())->modify(
                '+' . $session->getTimeoutSeconds() . ' seconds'
            );
            $session->extendExpiry($newExpiry);
            $session->updateLastActivity();
            $this->sessionRepo->save($session);
        }

        return new ValidationResult(true, 'Session is valid');
    }

    public function refreshSession(string $sessionId): RefreshResult
    {
        $session = $this->sessionRepo->findById($sessionId);

        if ($session === null) {
            throw new \RuntimeException('Session not found');
        }

        $newExpiry = (new \DateTimeImmutable())->modify(
            '+' . $session->getTimeoutSeconds() . ' seconds'
        );

        $session->extendExpiry($newExpiry);
        $session->updateLastActivity();

        $this->sessionRepo->save($session);

        return new RefreshResult([
            'success' => true,
            'expires_at' => $newExpiry->format('c')
        ]);
    }

    public function invalidateSession(string $sessionId): void
    {
        $this->sessionRepo->delete($sessionId);
        $this->logger->info('Session invalidated', ['session_id' => $sessionId]);
    }

    public function invalidateAllUserSessions(string $userId): int
    {
        $count = $this->sessionRepo->deleteAllForUser($userId);
        $this->logger->info('All user sessions invalidated', [
            'user_id' => $userId,
            'count' => $count
        ]);
        return $count;
    }

    private function determineSessionTimeout(bool $rememberMe, bool $extended): int
    {
        if ($rememberMe) {
            return self::DEFAULT_REMEMBER_ME_TIMEOUT_SECONDS;
        }

        if ($extended) {
            return self::DEFAULT_EXTENDED_TIMEOUT_SECONDS;
        }

        return self::DEFAULT_SESSION_TIMEOUT_SECONDS;
    }

    private function invalidateOldestSession(string $userId): void
    {
        $oldest = $this->sessionRepo->findOldestSessionForUser($userId);
        if ($oldest !== null) {
            $this->sessionRepo->delete($oldest->getId());
        }
    }
}
