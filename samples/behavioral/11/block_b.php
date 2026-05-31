<?php
declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Repository\SessionRepository;
use App\Auth\Repository\UserRepository;
use Psr\Log\LoggerInterface;

final class AuthenticationStatusService
{
    private SessionRepository $sessionRepository;
    private UserRepository $userRepository;
    private LoggerInterface $logger;

    public function __construct(
        SessionRepository $sessionRepository,
        UserRepository $userRepository,
        LoggerInterface $logger
    ) {
        $this->sessionRepository = $sessionRepository;
        $this->userRepository = $userRepository;
        $this->logger = $logger;
    }

    public function checkUserActivityStatus(string $userId): bool
    {
        $activeSession = $this->sessionRepository->findActiveSessionForUser($userId);

        if ($activeSession === null) {
            return false;
        }

        if ($activeSession->isExpired()) {
            return false;
        }

        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return false;
        }

        $userStatus = $user->getStatus();

        $activeStatuses = ['active', 'verified'];

        if (!in_array($userStatus, $activeStatuses, true)) {
            return false;
        }

        return !$user->isBanned();
    }

    public function validateUserSession(string $sessionToken): bool
    {
        $session = $this->sessionRepository->findByToken($sessionToken);

        if ($session === null) {
            return false;
        }

        if ($session->isExpired()) {
            return false;
        }

        if ($session->isRevoked()) {
            return false;
        }

        return $this->checkUserActivityStatus($session->getUserId());
    }

    public function getUserActivityScore(string $userId): float
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return 0.0;
        }

        $score = 0.0;

        $lastLogin = $user->getLastLoginAt();
        if ($lastLogin !== null) {
            $daysSinceLogin = (new \DateTimeImmutable())->getTimestamp() - $lastLogin->getTimestamp();
            $daysSinceLogin = (int) floor($daysSinceLogin / 86400);

            if ($daysSinceLogin <= 1) {
                $score += 40;
            } elseif ($daysSinceLogin <= 7) {
                $score += 30;
            } elseif ($daysSinceLogin <= 30) {
                $score += 20;
            } else {
                $score += 0;
            }
        }

        $sessionCount = $this->sessionRepository->countActiveSessionsForUser($userId);
        $score += min(30, $sessionCount * 10);

        $verificationLevel = $user->getVerificationLevel();
        $score += match ($verificationLevel) {
            'full' => 30,
            'partial' => 15,
            'minimal' => 5,
            default => 0
        };

        return min(100.0, $score);
    }

    public function isUserInGoodStanding(string $userId): bool
    {
        $activityScore = $this->getUserActivityScore($userId);

        return $activityScore >= 50.0;
    }
}
