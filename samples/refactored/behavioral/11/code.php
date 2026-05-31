<?php
declare(strict_types=1);

namespace App\User\Service;

interface UserActivityCheckerInterface
{
    public function isActive(string $userId): bool;
    public function getActivityScore(string $userId): float;
}

final class UserActivityChecker implements UserActivityCheckerInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly ActivityLogRepository $activityLogRepository
    ) {}

    public function isActive(string $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return false;
        }

        if (!$this->hasValidStatus($user)) {
            return false;
        }

        if (!$this->hasRecentActivity($userId)) {
            return false;
        }

        return $this->hasValidSession($userId);
    }

    public function getActivityScore(string $userId): float
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return 0.0;
        }

        return $this->calculateScore($user, $userId);
    }

    private function hasValidStatus($user): bool
    {
        $validStatuses = ['active', 'verified'];
        return in_array($user->getStatus(), $validStatuses, true);
    }

    private function hasRecentActivity(string $userId): bool
    {
        $recent = $this->activityLogRepository->getRecentActivities(
            $userId,
            new \DateTimeImmutable('-7 days')
        );

        return count($recent) > 0 || $this->sessionRepository->hasActiveSession($userId);
    }

    private function hasValidSession(string $userId): bool
    {
        $session = $this->sessionRepository->findActiveSessionForUser($userId);
        return $session !== null && !$session->isExpired();
    }

    private function calculateScore($user, string $userId): float
    {
        $score = 0.0;

        $loginCount = $this->activityLogRepository->countLogins($userId, new \DateTimeImmutable('-30 days'));
        $score += min(40, $loginCount * 5);

        $hasActiveSession = $this->sessionRepository->hasActiveSession($userId);
        if ($hasActiveSession) {
            $score += 30;
        }

        $verificationLevel = $user->getVerificationLevel();
        $score += match ($verificationLevel) {
            'full' => 30,
            'partial' => 15,
            default => 0
        };

        return min(100.0, $score);
    }
}
