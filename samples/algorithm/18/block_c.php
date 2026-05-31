<?php
declare(strict_types=1);

namespace FraudDetection\Scoring;

use Psr\Log\LoggerInterface;

final class SessionFraudScorer
{
    private const VELOCITY_WINDOW_SECONDS = 300;
    private const VELOCITY_THRESHOLD_HIGH = 20;
    private const VELOCITY_THRESHOLD_MEDIUM = 10;
    private const VELOCITY_WEIGHT = 0.20;

    private const SESSION_DURATION_THRESHOLD_SECONDS = 10;
    private const SESSION_DURATION_WEIGHT = 0.15;

    private const MULTIPLE_ACCOUNT_WEIGHT = 0.20;
    private const VPN_DETECTED_WEIGHT = 0.15;
    private const BOT_LIKELIHOOD_WEIGHT = 0.15;

    private const TIMEZONE_MISMATCH_WEIGHT = 0.10;

    private const RISK_SCORE_LOW = 0.30;
    private const RISK_SCORE_MEDIUM = 0.60;
    private const RISK_SCORE_HIGH = 0.80;

    private const MAX_RISK_SCORE = 1.0;
    private const MIN_RISK_SCORE = 0.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateRiskScore(SessionContext $context): FraudScoreResult
    {
        $this->logger->debug('Calculating session fraud risk score', [
            'session_id' => $context->getSessionId(),
            'account_id' => $context->getAccountId(),
        ]);

        $velocityScore = $this->calculateVelocityScore($context);
        $durationScore = $this->calculateDurationScore($context);
        $multiAccountScore = $this->calculateMultiAccountScore($context);
        $vpnScore = $this->calculateVpnScore($context);
        $botScore = $this->calculateBotScore($context);
        $timezoneScore = $this->calculateTimezoneMismatchScore($context);

        $weightedScore = ($velocityScore * self::VELOCITY_WEIGHT)
            + ($durationScore * self::SESSION_DURATION_WEIGHT)
            + ($multiAccountScore * self::MULTIPLE_ACCOUNT_WEIGHT)
            + ($vpnScore * self::VPN_DETECTED_WEIGHT)
            + ($botScore * self::BOT_LIKELIHOOD_WEIGHT)
            + ($timezoneScore * self::TIMEZONE_MISMATCH_WEIGHT);

        $normalizedScore = max(self::MIN_RISK_SCORE, min(self::MAX_RISK_SCORE, $weightedScore));

        $riskLevel = $this->determineRiskLevel($normalizedScore);
        $recommendedAction = $this->determineAction($riskLevel);

        $this->logger->info('Session fraud risk score calculated', [
            'session_id' => $context->getSessionId(),
            'risk_score' => $normalizedScore,
            'risk_level' => $riskLevel,
        ]);

        return new FraudScoreResult(
            riskScore: $normalizedScore,
            riskLevel: $riskLevel,
            recommendedAction: $recommendedAction,
            factors: [
                'velocity' => $velocityScore,
                'duration' => $durationScore,
                'multi_account' => $multiAccountScore,
                'vpn' => $vpnScore,
                'bot' => $botScore,
                'timezone_mismatch' => $timezoneScore,
            ],
        );
    }

    private function calculateVelocityScore(SessionContext $context): float
    {
        $pageViewCount = $context->getPageViewsInWindow(self::VELOCITY_WINDOW_SECONDS);

        if ($pageViewCount >= self::VELOCITY_THRESHOLD_HIGH) {
            return 1.0;
        }

        if ($pageViewCount >= self::VELOCITY_THRESHOLD_MEDIUM) {
            return 0.7;
        }

        return $pageViewCount / self::VELOCITY_THRESHOLD_HIGH;
    }

    private function calculateDurationScore(SessionContext $context): float
    {
        $sessionDuration = $context->getSessionDuration();

        if ($sessionDuration <= self::SESSION_DURATION_THRESHOLD_SECONDS) {
            return 1.0;
        }

        return max(0.0, 1.0 - ($sessionDuration / 300));
    }

    private function calculateMultiAccountScore(SessionContext $context): float
    {
        $accountCount = $context->getUniqueAccountsFromIp();

        if ($accountCount >= 5) {
            return 1.0;
        }

        if ($accountCount >= 3) {
            return 0.7;
        }

        if ($accountCount >= 2) {
            return 0.4;
        }

        return 0.0;
    }

    private function calculateVpnScore(SessionContext $context): float
    {
        if ($context->isVpnDetected()) {
            return 1.0;
        }

        if ($context->isProxyDetected()) {
            return 0.6;
        }

        if ($context->isTorExitNode()) {
            return 0.8;
        }

        return 0.0;
    }

    private function calculateBotScore(SessionContext $context): float
    {
        $botLikelihood = $context->getBotLikelihoodScore();

        if ($botLikelihood >= 0.8) {
            return 1.0;
        }

        if ($botLikelihood >= 0.5) {
            return 0.6;
        }

        return $botLikelihood;
    }

    private function calculateTimezoneMismatchScore(SessionContext $context): float
    {
        if ($context->isTimezoneMismatch()) {
            return 1.0;
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
            'high' => 'terminate',
            'medium' => 'challenge',
            'low' => 'flag',
            default => 'allow',
        };
    }
}
