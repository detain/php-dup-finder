<?php
declare(strict_types=1);

namespace Notifications\Shared;

interface NotificationThrottlePolicy
{
    public function canSend(NotificationContext $context): ThrottleResult;
    public function getChannel(): string;
    public function getMaxDaily(): int;
    public function getMaxHourly(): int;
    public function getCooldownSeconds(): int;
}

abstract class BaseThrottlePolicy implements NotificationThrottlePolicy
{
    protected LoggerInterface $logger;

    protected const DEFAULT_MAX_DAILY = 100;
    protected const DEFAULT_MAX_HOURLY = 20;
    protected const DEFAULT_COOLDOWN_SECONDS = 1800;

    public function canSend(NotificationContext $context): ThrottleResult
    {
        $history = $context->getHistory();

        if (!$this->checkDailyQuota($history)) {
            return ThrottleResult::denied('daily_quota_exceeded', $this->getNextResetTime());
        }

        if (!$this->checkHourlyBurst($history)) {
            return ThrottleResult::denied('burst_limit_exceeded', $this->getNextHourResetTime());
        }

        if (!$this->checkCooldown($history)) {
            return ThrottleResult::denied('cooldown_active', $this->getCooldownRemaining($history));
        }

        return ThrottleResult::allowed();
    }

    protected function checkDailyQuota(NotificationHistory $history): bool
    {
        return $history->getTodayCount($this->getChannel()) < $this->getMaxDaily();
    }

    protected function checkHourlyBurst(NotificationHistory $history): bool
    {
        return $history->getLastHourCount($this->getChannel()) < $this->getMaxHourly();
    }

    protected function checkCooldown(NotificationHistory $history): bool
    {
        $lastSent = $history->getLastSentTime($this->getChannel());
        if ($lastSent === null) {
            return true;
        }

        return (time() - $lastSent) >= $this->getCooldownSeconds();
    }

    abstract public function getChannel(): string;
    abstract public function getMaxDaily(): int;
    abstract public function getMaxHourly(): int;
    abstract public function getCooldownSeconds(): int;
}

final class EmailThrottlePolicy extends BaseThrottlePolicy
{
    public function getChannel(): string
    {
        return 'email';
    }

    public function getMaxDaily(): int
    {
        return 100;
    }

    public function getMaxHourly(): int
    {
        return 20;
    }

    public function getCooldownSeconds(): int
    {
        return 1800;
    }
}

final class UnifiedNotificationThrottler
{
    private array $policies = [];

    public function addPolicy(NotificationThrottlePolicy $policy): void
    {
        $this->policies[$policy->getChannel()] = $policy;
    }

    public function evaluate(NotificationContext $context): ThrottleResult
    {
        $channel = $context->getChannel();
        $policy = $this->policies[$channel] ?? null;

        if ($policy === null) {
            return ThrottleResult::allowed();
        }

        return $policy->canSend($context);
    }
}
