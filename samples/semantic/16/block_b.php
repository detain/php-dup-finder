<?php
declare(strict_types=1);

namespace Notifications\Rules;

final class EmailDeliveryController
{
    private const DAILY_EMAIL_QUOTA = 100;
    private const HOURLY_EMAIL_BURST = 20;
    private const EMAIL_COOLDOWN_SECONDS = 1800;

    private const PERMISSION_EMAIL_LIMIT = 50;
    private const UNSUBSCRIBED_HARD_BOUNCE = 'hard_bounce';
    private const UNSUBSCRIBED_SOFT_BOUNCE = 'soft_bounce';

    public function shouldDeliverEmail(
        EmailDeliveryRequest $request,
        EmailTrackingRecord $tracking
    ): DeliveryDecision {
        $recipientEmail = $request->getRecipient();

        $subscriptionStatus = $this->checkSubscriptionStatus($recipientEmail, $tracking);
        if (!$subscriptionStatus->canReceive) {
            return new DeliveryDecision(
                deliver: false,
                reason: $subscriptionStatus->blockReason,
            );
        }

        $quotaStatus = $this->checkQuotaAvailability($recipientEmail, $tracking);
        if (!$quotaStatus->hasQuota) {
            return new DeliveryDecision(
                deliver: false,
                reason: 'daily_quota_exhausted',
            );
        }

        $burstStatus = $this->checkBurstAllowance($recipientEmail, $tracking);
        if (!$burstStatus->withinLimit) {
            return new DeliveryDecision(
                deliver: false,
                reason: 'hourly_burst_limit_reached',
            );
        }

        $cooldownStatus = $this->checkSendingCooldown($recipientEmail, $tracking);
        if (!$cooldownStatus->cooldownExpired) {
            return new DeliveryDecision(
                deliver: false,
                reason: 'cooldown_period_not_elapsed',
            );
        }

        return new DeliveryDecision(
            deliver: true,
            reason: null,
        );
    }

    private function checkSubscriptionStatus(
        string $email,
        EmailTrackingRecord $tracking
    ): SubscriptionCheckResult {
        $preference = $tracking->getEmailPreference($email);

        if ($preference->isUnsubscribed) {
            return new SubscriptionCheckResult(
                canReceive: false,
                blockReason: 'user_unsubscribed',
            );
        }

        if ($preference->bounceStatus === self::UNSUBSCRIBED_HARD_BOUNCE) {
            return new SubscriptionCheckResult(
                canReceive: false,
                blockReason: 'hard_bounce_blocked',
            );
        }

        if ($preference->bounceStatus === self::UNSUBSCRIBED_SOFT_BOUNCE) {
            if ($this->isSoftBounceRecovered($preference)) {
                return new SubscriptionCheckResult(
                    canReceive: true,
                    blockReason: null,
                );
            }
            return new SubscriptionCheckResult(
                canReceive: false,
                blockReason: 'soft_bounce_pending_recovery',
            );
        }

        if (!$preference->marketingEnabled) {
            return new SubscriptionCheckResult(
                canReceive: false,
                blockReason: 'marketing_not_enabled',
            );
        }

        return new SubscriptionCheckResult(
            canReceive: true,
            blockReason: null,
        );
    }

    private function checkQuotaAvailability(
        string $email,
        EmailTrackingRecord $tracking
    ): QuotaCheckResult {
        $dailyCount = $tracking->getTodayEmailCount($email);
        $quotaLimit = $this->getRecipientQuota($email);

        if ($dailyCount >= $quotaLimit) {
            return new QuotaCheckResult(
                hasQuota: false,
            );
        }

        return new QuotaCheckResult(
            hasQuota: true,
        );
    }

    private function checkBurstAllowance(
        string $email,
        EmailTrackingRecord $tracking
    ): BurstCheckResult {
        $lastHourCount = $tracking->getLastHourEmailCount($email);

        if ($lastHourCount >= self::HOURLY_EMAIL_BURST) {
            return new BurstCheckResult(
                withinLimit: false,
            );
        }

        return new BurstCheckResult(
            withinLimit: true,
        );
    }

    private function checkSendingCooldown(
        string $email,
        EmailTrackingRecord $tracking
    ): CooldownCheckResult {
        $lastEmailTimestamp = $tracking->getLastEmailSentTime($email);

        if ($lastEmailTimestamp === null) {
            return new CooldownCheckResult(
                cooldownExpired: true,
            );
        }

        $secondsSinceLastEmail = time() - $lastEmailTimestamp;

        if ($secondsSinceLastEmail < self::EMAIL_COOLDOWN_SECONDS) {
            return new CooldownCheckResult(
                cooldownExpired: false,
            );
        }

        return new CooldownCheckResult(
            cooldownExpired: true,
        );
    }

    private function getRecipientQuota(string $email): int
    {
        $premiumDomains = ['vip.example.com', 'premium.example.com'];

        $domain = explode('@', $email)[1] ?? '';

        if (in_array($domain, $premiumDomains)) {
            return self::DAILY_EMAIL_QUOTA * 2;
        }

        return self::DAILY_EMAIL_QUOTA;
    }

    private function isSoftBounceRecovered(mixed $preference): bool
    {
        $bounceRecoveryDays = 7;
        $lastBounceTime = $preference->lastBounceAt;

        if ($lastBounceTime === null) {
            return true;
        }

        $daysSinceBounce = (time() - $lastBounceTime) / 86400;

        return $daysSinceBounce >= $bounceRecoveryDays;
    }
}
