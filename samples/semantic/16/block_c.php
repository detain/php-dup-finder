<?php
declare(strict_types=1);

namespace Notifications\Rules;

final class PushNotificationGatekeeper
{
    private const DAILY_PUSH_LIMIT_STANDARD = 200;
    private const DAILY_PUSH_LIMIT_PREMIUM = 500;
    private const HOURLY_PUSH_BURST = 40;

    private const PUSH_COOLDOWN_INTERVAL = 900;
    private const QUIET_HOURS_START = 22;
    private const QUIET_HOURS_END = 7;

    private const INVALID_TOKEN = 'invalid_registration';
    private const EXPIRED_TOKEN = 'not_registered';

    public function evaluatePushDelivery(
        PushNotificationRequest $request,
        DeviceTokenRecord $deviceRecord
    ): PushDeliveryDecision {
        $deviceToken = $request->getDeviceToken();

        $tokenValidation = $this->validateToken($deviceToken, $deviceRecord);
        if (!$tokenValidation->isValid) {
            return new PushDeliveryDecision(
                shouldDeliver: false,
                blockReason: $tokenValidation->failureReason,
            );
        }

        $quotaCheck = $this->evaluateQuotaStatus($request, $deviceRecord);
        if (!$quotaCheck->withinQuota) {
            return new PushDeliveryDecision(
                shouldDeliver: false,
                blockReason: 'push_quota_exceeded',
            );
        }

        $rateLimitCheck = $this->evaluateRateLimiting($request, $deviceRecord);
        if (!$rateLimitCheck->withinRateLimit) {
            return new PushDeliveryDecision(
                shouldDeliver: false,
                blockReason: 'rate_limit_exceeded',
            );
        }

        $quietHoursCheck = $this->evaluateQuietHours($request);
        if (!$quietHoursCheck->canDeliver) {
            return new PushDeliveryDecision(
                shouldDeliver: false,
                blockReason: 'quiet_hours_restriction',
            );
        }

        return new PushDeliveryDecision(
            shouldDeliver: true,
            blockReason: null,
        );
    }

    private function validateToken(string $token, DeviceTokenRecord $record): TokenValidation
    {
        $tokenStatus = $record->getTokenStatus($token);

        if ($tokenStatus === self::INVALID_TOKEN) {
            return new TokenValidation(
                isValid: false,
                failureReason: 'device_token_invalid',
            );
        }

        if ($tokenStatus === self::EXPIRED_TOKEN) {
            return new TokenValidation(
                isValid: false,
                failureReason: 'device_token_expired',
            );
        }

        return new TokenValidation(
            isValid: true,
            failureReason: null,
        );
    }

    private function evaluateQuotaStatus(
        PushNotificationRequest $request,
        DeviceTokenRecord $record
    ): QuotaStatus {
        $userId = $request->getUserId();
        $todayCount = $record->getDailyPushCount($userId);
        $quotaLimit = $this->getPushQuotaLimit($request->getUserTier());

        if ($todayCount >= $quotaLimit) {
            return new QuotaStatus(
                withinQuota: false,
            );
        }

        return new QuotaStatus(
            withinQuota: true,
        );
    }

    private function evaluateRateLimiting(
        PushNotificationRequest $request,
        DeviceTokenRecord $record
    ): RateLimitStatus {
        $userId = $request->getUserId();
        $lastHourCount = $record->getHourlyPushCount($userId);

        if ($lastHourCount >= self::HOURLY_PUSH_BURST) {
            return new RateLimitStatus(
                withinRateLimit: false,
            );
        }

        $lastPushTime = $record->getLastPushTimestamp($userId);
        if ($lastPushTime !== null) {
            $secondsSinceLastPush = time() - $lastPushTime;
            if ($secondsSinceLastPush < self::PUSH_COOLDOWN_INTERVAL) {
                return new RateLimitStatus(
                    withinRateLimit: false,
                );
            }
        }

        return new RateLimitStatus(
            withinRateLimit: true,
        );
    }

    private function evaluateQuietHours(PushNotificationRequest $request): QuietHoursStatus
    {
        if (!$request->isQuietHoursOverride()) {
            $currentHour = (int) date('G');

            if ($currentHour >= self::QUIET_HOURS_START || $currentHour < self::QUIET_HOURS_END) {
                return new QuietHoursStatus(
                    canDeliver: false,
                );
            }
        }

        return new QuietHoursStatus(
            canDeliver: true,
        );
    }

    private function getPushQuotaLimit(string $userTier): int
    {
        return match ($userTier) {
            'premium' => self::DAILY_PUSH_LIMIT_PREMIUM,
            'standard' => self::DAILY_PUSH_LIMIT_STANDARD,
            default => self::DAILY_PUSH_LIMIT_STANDARD,
        };
    }
}
