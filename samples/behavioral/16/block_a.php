<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticationSessionManager
{
    private const SESSION_TIMEOUT = 3600;
    private const ABSOLUTE_TIMEOUT = 86400;

    public function __construct(
        private readonly SessionRepository $sessionRepository,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function validateSession(Request $request): ?User
    {
        $sessionToken = $request->headers->get('X-Session-Token');

        if ($sessionToken === null) {
            $this->logger->debug('No session token provided');
            return null;
        }

        $session = $this->sessionRepository->findActiveByToken($sessionToken);

        if ($session === null) {
            $this->logger->debug('Session not found or expired', ['token_prefix' => substr($sessionToken, 0, 8)]);
            return null;
        }

        if ($session->isExpired()) {
            $this->logger->info('Session expired', ['session_id' => $session->getId()]);
            $this->sessionRepository->markExpired($session);
            return null;
        }

        $lastActivity = $session->getLastActivityAt();
        if ($lastActivity !== null) {
            $secondsSinceActivity = time() - $lastActivity->getTimestamp();

            if ($secondsSinceActivity > self::SESSION_TIMEOUT) {
                $this->logger->info('Session timed out due to inactivity', [
                    'session_id' => $session->getId(),
                    'seconds_idle' => $secondsSinceActivity,
                ]);
                $this->sessionRepository->markExpired($session);
                return null;
            }
        }

        $createdAt = $session->getCreatedAt();
        $absoluteAge = time() - $createdAt->getTimestamp();
        if ($absoluteAge > self::ABSOLUTE_TIMEOUT) {
            $this->logger->info('Session reached absolute timeout', [
                'session_id' => $session->getId(),
                'age_seconds' => $absoluteAge,
            ]);
            $this->sessionRepository->markExpired($session);
            return null;
        }

        $user = $this->userRepository->find($session->getUserId());
        if ($user === null || !$user->isActive()) {
            $this->logger->warning('User not found or inactive for session', [
                'session_id' => $session->getId(),
                'user_id' => $session->getUserId(),
            ]);
            return null;
        }

        $session->recordActivity();
        $this->sessionRepository->save($session);

        $this->logger->debug('Session validated successfully', [
            'session_id' => $session->getId(),
            'user_id' => $user->getId(),
        ]);

        return $user;
    }

    public function createSession(User $user, Request $request): string
    {
        $sessionToken = bin2hex(random_bytes(32));

        $session = new Session();
        $session->setUserId($user->getId());
        $session->setToken($sessionToken);
        $session->setIpAddress($request->getClientIp());
        $session->setUserAgent($request->headers->get('User-Agent', 'Unknown'));
        $session->setCreatedAt(new \DateTimeImmutable());
        $session->setLastActivityAt(new \DateTimeImmutable());

        $this->sessionRepository->save($session);

        $this->logger->info('Session created', [
            'user_id' => $user->getId(),
            'session_id' => $session->getId(),
            'ip' => $request->getClientIp(),
        ]);

        return $sessionToken;
    }

    public function invalidateSession(string $sessionToken): bool
    {
        $session = $this->sessionRepository->findActiveByToken($sessionToken);

        if ($session === null) {
            return false;
        }

        $this->sessionRepository->markExpired($session);

        $this->logger->info('Session invalidated', [
            'session_id' => $session->getId(),
            'user_id' => $session->getUserId(),
        ]);

        return true;
    }
}
