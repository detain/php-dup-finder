<?php
declare(strict_types=1);

namespace Notifications\Rules;

final class NotificationThrottler
{
    private const MAX_DAILY_EMAILS = 100;
    private const MAX_DAILY_SMS = 50;
    private const MAX_DAILY_PUSH = 200;

    private const HOURLY_EMAIL_BURST = 20;
    private const HOURLY_SMS_BURST = 10;
    private const HOURLY_PUSH_BURST = 40;

    private const COOLDOWN_EMAIL_MINUTES = 30;
    private const COOLDOWN_SMS_MINUTES = 60;
    private const COOLDOWN_PUSH_MINUTES = 15;

    public function canSendNotification(
        NotificationRequest $request,
        CustomerNotificationHistory $history
    ): NotificationThrottleResult {
        $channel = $request->getChannel();
        $customerId = $request->getCustomerId();

        $dailyLimitCheck = $this->checkDailyLimit($channel, $customerId, $history);
        if (!$dailyLimitCheck->allowed) {
            return new NotificationThrottleResult(
                allowed: false,
                reason: 'daily_limit_exceeded',
                retryAfter: $dailyLimitCheck->retryAfter,
            );
        }

        $burstLimitCheck = $this->checkBurstLimit($channel, $customerId, $history);
        if (!$burstLimitCheck->allowed) {
            return new NotificationThrottleResult(
                allowed: false,
                reason: 'burst_limit_exceeded',
                retryAfter: $burstLimitCheck->retryAfter,
            );
        }

        $cooldownCheck = $this->checkCooldown($channel, $customerId, $history);
        if (!$cooldownCheck->allowed) {
            return new NotificationThrottleResult(
                allowed: false,
                reason: 'cooldown_period_active',
                retryAfter: $cooldownCheck->retryAfter,
            );
        }

        return new NotificationThrottleResult(
            allowed: true,
            reason: null,
            retryAfter: null,
        );
    }

    private function checkDailyLimit(
        string $channel,
        string $customerId,
        CustomerNotificationHistory $history
    ): LimitCheckResult {
        $maxDaily = match ($channel) {
            'email' => self::MAX_DAILY_EMAILS,
            'sms' => self::MAX_DAILY_SMS,
            'push' => self::MAX_DAILY_PUSH,
            default => 0,
        };

        $todayCount = $history->getTodayChannelCount($channel);

        if ($todayCount >= $maxDaily) {
            $nextReset = $this->getNextDailyResetTime();
            return new LimitCheckResult(
                allowed: false,
                retryAfter: $nextReset,
            );
        }

        $remaining = $maxDaily - $todayCount;
        return new LimitCheckResult(
            allowed: true,
            retryAfter: null,
        );
    }

    private function checkBurstLimit(
        string $channel,
        string $customerId,
        CustomerNotificationHistory $history
    ): LimitCheckResult {
        $maxHourly = match ($channel) {
            'email' => self::HOURLY_EMAIL_BURST,
            'sms' => self::HOURLY_SMS_BURST,
            'push' => self::HOURLY_PUSH_BURST,
            default => 0,
        };

        $lastHourCount = $history->getLastHourChannelCount($channel);

        if ($lastHourCount >= $maxHourly) {
            $nextReset = $this->getNextHourResetTime();
            return new LimitCheckResult(
                allowed: false,
                retryAfter: $nextReset,
            );
        }

        return new LimitCheckResult(
            allowed: true,
            retryAfter: null,
        );
    }

    private function checkCooldown(
        string $channel,
        string $customerId,
        CustomerNotificationHistory $history
    ): LimitCheckResult {
        $cooldownMinutes = match ($channel) {
            'email' => self::COOLDOWN_EMAIL_MINUTES,
            'sms' => self::COOLDOWN_SMS_MINUTES,
            'push' => self::COOLDOWN_PUSH_MINUTES,
            default => 60,
        };

        $lastSent = $history->getLastSentTimestamp($channel);
        if ($lastSent === null) {
            return new LimitCheckResult(
                allowed: true,
                retryAfter: null,
            );
        }

        $minutesSinceLastSend = (time() - $lastSent) / 60;

        if ($minutesSinceLastSend < $cooldownMinutes) {
            $remainingCooldown = $cooldownMinutes - $minutesSinceLastSend;
            return new LimitCheckResult(
                allowed: false,
                retryAfter: (int)ceil($remainingCooldown * 60),
            );
        }

        return new LimitCheckResult(
            allowed: true,
            retryAfter: null,
        );
    }

    private function getNextDailyResetTime(): int
    {
        $now = new \DateTimeImmutable();
        $midnight = $now->modify('tomorrow')->setTime(0, 0, 0);
        return $midnight->getTimestamp() - $now->getTimestamp();
    }

    private function getNextHourResetTime(): int
    {
        $now = new \DateTimeImmutable();
        $nextHour = $now->modify('+1 hour')->setTime((int)$now->format('H'), 0, 0);
        return $nextHour->getTimestamp() - $now->getTimestamp();
    }
}
