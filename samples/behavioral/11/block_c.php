<?php
declare(strict_types=1);

namespace App\Analytics\Service;

use App\Analytics\Repository\ActivityLogRepository;
use App\User\Repository\UserRepository;
use Psr\Log\LoggerInterface;

final class UserEngagementAnalyzer
{
    private ActivityLogRepository $activityLog;
    private UserRepository $userRepository;
    private LoggerInterface $logger;

    private const MIN_LOGINS_FOR_ACTIVE = 3;
    private const MAX_INACTIVE_DAYS_FOR_ACTIVE = 7;

    public function __construct(
        ActivityLogRepository $activityLog,
        UserRepository $userRepository,
        LoggerInterface $logger
    ) {
        $this->activityLog = $activityLog;
        $this->userRepository = $userRepository;
        $this->logger = $logger;
    }

    public function determineUserActiveStatus(string $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return false;
        }

        if ($user->getStatus() === 'deleted') {
            return false;
        }

        if ($user->getStatus() === 'suspended') {
            return false;
        }

        $recentActivities = $this->activityLog->getRecentActivities(
            $userId,
            (new \DateTimeImmutable())->modify('-7 days')
        );

        $loginActivities = array_filter(
            $recentActivities,
            fn($a) => $a->getType() === 'login'
        );

        if (count($loginActivities) >= self::MIN_LOGINS_FOR_ACTIVE) {
            return true;
        }

        $lastLogin = $user->getLastLoginAt();
        if ($lastLogin === null) {
            $accountCreation = $user->getCreatedAt();
            $accountAge = (new \DateTimeImmutable())->getTimestamp() - $accountCreation->getTimestamp();
            $accountAgeDays = (int) floor($accountAge / 86400);

            return $accountAgeDays <= 7;
        }

        $lastActivity = end($recentActivities);
        if ($lastActivity !== false) {
            $lastActivityTime = $lastActivity->getCreatedAt();
            $daysSinceActivity = (new \DateTimeImmutable())->getTimestamp() - $lastActivityTime->getTimestamp();
            $daysSinceActivity = (int) floor($daysSinceActivity / 86400);

            return $daysSinceActivity <= self::MAX_INACTIVE_DAYS_FOR_ACTIVE;
        }

        return false;
    }

    public function getUserEngagementLevel(string $userId): string
    {
        $recentActivities = $this->activityLog->getRecentActivities(
            $userId,
            (new \DateTimeImmutable())->modify('-30 days')
        );

        $activityCount = count($recentActivities);

        if ($activityCount >= 50) {
            return 'highly_engaged';
        }

        if ($activityCount >= 20) {
            return 'engaged';
        }

        if ($activityCount >= 5) {
            return 'moderate';
        }

        if ($activityCount >= 1) {
            return 'low';
        }

        return 'inactive';
    }

    public function predictChurnRisk(string $userId): ChurnRiskResult
    {
        $engagementLevel = $this->getUserEngagementLevel($userId);

        $riskScore = match ($engagementLevel) {
            'highly_engaged' => 0.1,
            'engaged' => 0.25,
            'moderate' => 0.5,
            'low' => 0.75,
            'inactive' => 0.95
        };

        $lastPurchase = $this->activityLog->getLastActivityOfType($userId, 'purchase');
        if ($lastPurchase !== null) {
            $daysSincePurchase = (new \DateTimeImmutable())->getTimestamp() - $lastPurchase->getCreatedAt()->getTimestamp();
            $daysSincePurchase = (int) floor($daysSincePurchase / 86400);

            if ($daysSincePurchase > 60) {
                $riskScore = min(1.0, $riskScore * 1.5);
            }
        }

        $user = $this->userRepository->findById($userId);
        if ($user !== null && $user->hasUnresolvedComplaints()) {
            $riskScore = min(1.0, $riskScore * 1.3);
        }

        return new ChurnRiskResult(
            userId: $userId,
            riskScore: $riskScore,
            riskLevel: $this->getRiskLevel($riskScore),
            engagementLevel: $engagementLevel
        );
    }

    private function getRiskLevel(float $score): string
    {
        if ($score < 0.2) {
            return 'very_low';
        }

        if ($score < 0.4) {
            return 'low';
        }

        if ($score < 0.6) {
            return 'medium';
        }

        if ($score < 0.8) {
            return 'high';
        }

        return 'very_high';
    }
}
