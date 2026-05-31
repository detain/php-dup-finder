<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class LockoutPolicy
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 900;   // 15 minutes
    public const LOCKOUT_SECONDS = 1800; // 30 minutes

    public static function windowMinutes(): int
    {
        return (int) (self::WINDOW_SECONDS / 60);
    }

    public static function isLockoutTriggering(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS;
    }

    public static function isCloseToLockout(int $attempts): bool
    {
        return $attempts === self::MAX_ATTEMPTS - 1;
    }

    public static function describe(): string
    {
        return sprintf('%d failed attempts within %d minutes', self::MAX_ATTEMPTS, self::windowMinutes());
    }
}

// LoginThrottle:
// $this->cache->set($key, $attempts, LockoutPolicy::WINDOW_SECONDS);
// if (LockoutPolicy::isLockoutTriggering($attempts)) { $user->lockedUntil = time() + LockoutPolicy::LOCKOUT_SECONDS; }

// SecurityAuditLogger:
// if (LockoutPolicy::isCloseToLockout($attemptNumber)) { $event['severity'] = 'warning'; }
// $event['reason'] = LockoutPolicy::describe();

// UserLockoutPanel:
// 'policy_max_attempts' => LockoutPolicy::MAX_ATTEMPTS,
// 'policy_window_minutes' => LockoutPolicy::windowMinutes(),
// 'progress_label' => sprintf('%d / %d failed attempts in the last %d minutes', $progress, LockoutPolicy::MAX_ATTEMPTS, LockoutPolicy::windowMinutes()),
