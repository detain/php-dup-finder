<?php
declare(strict_types=1);

namespace FraudDetection\Scoring;

use Psr\Log\LoggerInterface;

final class AccountFraudScorer
{
    private const VELOCITY_WINDOW_SECONDS = 300;
    private const VELOCITY_THRESHOLD_HIGH = 10;
    private const VELOCITY_THRESHOLD_MEDIUM = 5;
    private const VELOCITY_WEIGHT = 0.20;

    private const LOGIN_FAILURE_THRESHOLD = 5;
    private const LOGIN_FAILURE_WEIGHT = 0.25;

    private const PASSWORD_RESET_WEIGHT = 0.15;

    private const NEW_DEVICE_WEIGHT = 0.15;
    private const NEW_IP_WEIGHT = 0.10;

    private const SUSPICIOUS_ACTIVITY_WEIGHT = 0.15;

    private const RISK_SCORE_LOW = 0.30;
    private const RISK_SCORE_MEDIUM = 0.60;
    private const RISK_SCORE_HIGH = 0.80;

    private const MAX_RISK_SCORE = 1.0;
    private const MIN_RISK_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRiskScore(AccountContext $context): FraudScoreResult
    {
        $this->logger->debug('Calculating account fraud risk score', [
            'account_id' => $context->getAccountId(),
        ]);

        $velocityScore = $this->calculateVelocityScore($context);
        $loginFailureScore = $this->calculateLoginFailureScore($context);
        $passwordResetScore = $this->calculatePasswordResetScore($context);
        $deviceScore = $this->calculateNewDeviceScore($context);
        $ipScore = $this->calculateNewIpScore($context);
        $activityScore = $this->calculateSuspiciousActivityScore($context);

        $weightedScore = ($velocityScore * self::VELOCITY_WEIGHT)
            + ($loginFailureScore * self::LOGIN_FAILURE_WEIGHT)
            + ($passwordResetScore * self::PASSWORD_RESET_WEIGHT)
            + ($deviceScore * self::NEW_DEVICE_WEIGHT)
            + ($ipScore * self::NEW_IP_WEIGHT)
            + ($activityScore * self::SUSPICIOUS_ACTIVITY_WEIGHT);

        $normalizedScore = max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $weightedScore));

        $riskLevel = $this->determineRiskLevel($normalizedScore);
        $recommendedAction = $this->determineAction($riskLevel);

        $this->logger->info('Account fraud risk score calculated', [
            'account_id' => $context->getAccountId(),
            'risk_score' => $normalizedScore,
            'risk_level' => $riskLevel,
        ]);

        return new FraudScoreResult(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $recommendedAction,
            factors: [
                'velocity' => $velocityScore,
                'login_failures' => $loginFailureScore,
                'password_reset' => $passwordResetScore,
                'new_device' => $deviceScore,
                'new_ip' => $ipScore,
                'suspicious_activity' => $activityScore,
            ],
        );
    }

    private function calculateVelocityScore(AccountContext $context): float
    {
        $actionCount = $context->getActionCountInWindow(self::VELOCITY_WINDOW_SECONDS);

        if ($actionCount >= self::VELOCITY_THRESHOLD_HIGH) {
            return 1.0;
        }

        if ($actionCount >= self::VELOCITY_THRESHOLD_MEDIUM) {
            return 0.7;
        }

        return $actionCount / self::VELOCITY_THRESHOLD_HIGH;
    }

    private function calculateLoginFailureScore(AccountContext $context): float
    {
        $failureCount = $context->getRecentLoginFailures();

        if ($failureCount >= self::LOGIN_FAILURE_THRESHOLD) {
            return 1.0;
        }

        return $failureCount / self::LOGIN_FAILURE_THRESHOLD;
    }

    private function calculatePasswordResetScore(AccountContext $context): float
    {
        $passwordResets = $context->getPasswordResetsInWindow(86400);

        if ($passwordResets >= 3) {
            return 1.0;
        }

        if ($passwordResets >= 2) {
            return 0.7;
        }

        if ($passwordResets >= 1) {
            return 0.4;
        }

        return 0.0;
    }

    private function calculateNewDeviceScore(AccountContext $context): float
    {
        if ($context->isKnownDevice()) {
            return 0.0;
        }

        return 1.0;
    }

    private function calculateNewIpScore(AccountContext $context): float
    {
        if ($context->isKnownIpAddress()) {
            return 0.0;
        }

        return 0.7;
    }

    private function calculateSuspiciousActivityScore(AccountContext $context): float
    {
        $suspiciousFlags = $context->getSuspiciousActivityFlags();

        if ($suspiciousFlags >= 5) {
            return 1.0;
        }

        if ($suspiciousFlags >= 3) {
            return 0.7;
        }

        if ($suspiciousFlags >= 1) {
            return 0.4;
        }

        return 0.0;
    }

    private function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_SCORE_HIGH) {
            return 'high';
        }

        if ($score >= self::RISK_SCORE_MEDIUM) {
            return 'medium';
        }

        if ($score >= self::RISK_SCORE_LOW) {
            return 'low';
        }

        return 'minimal';
    }

    private function determineAction(string $riskLevel): string
    {
        return match ($riskLevel) {
            'high' => 'lock',
            'medium' => 'verify',
            'low' => 'flag',
            default => 'allow',
        };
    }
}
