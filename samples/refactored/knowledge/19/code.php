<?php
declare(strict_types=1);

namespace App\Security\Policy;

final class SessionPolicy
{
    public const DEFAULT_TIMEOUT = 1800;
    public const EXTENDED_TIMEOUT = 7200;
    public const REMEMBER_ME_TIMEOUT = 1209600;
    public const MAX_CONCURRENT_SESSIONS = 3;

    public function __construct(
        public readonly int $defaultTimeout = self::DEFAULT_TIMEOUT,
        public readonly int $extendedTimeout = self::EXTENDED_TIMEOUT,
        public readonly int $rememberMeTimeout = self::REMEMBER_ME_TIMEOUT,
        public readonly int $maxConcurrentSessions = self::MAX_CONCURRENT_SESSIONS,
        public readonly bool $slidingExpiration = true,
        public readonly array $cookieSettings = [
            'name' => 'PHPSESSID',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    ) {}

    public static function fromConfig(array $config): self
    {
        $s = $config['session'] ?? [];

        return new self(
            defaultTimeout: $s['default_timeout'] ?? self::DEFAULT_TIMEOUT,
            extendedTimeout: $s['extended_timeout'] ?? self::EXTENDED_TIMEOUT,
            rememberMeTimeout: $s['remember_me_timeout'] ?? self::REMEMBER_ME_TIMEOUT,
            maxConcurrentSessions: $s['max_concurrent'] ?? self::MAX_CONCURRENT_SESSIONS,
            slidingExpiration: $s['sliding_expiration'] ?? true,
            cookieSettings: [
                'name' => $s['cookie_name'] ?? 'PHPSESSID',
                'secure' => $s['cookie_secure'] ?? true,
                'httponly' => $s['cookie_httponly'] ?? true,
                'samesite' => $s['cookie_samesite'] ?? 'Lax'
            ]
        );
    }

    public function getTimeout(bool $rememberMe = false, bool $extended = false): int
    {
        if ($rememberMe) {
            return $this->rememberMeTimeout;
        }

        if ($extended) {
            return $this->extendedTimeout;
        }

        return $this->defaultTimeout;
    }

    public function calculateExpiry(
        \DateTimeImmutable $from,
        bool $rememberMe = false,
        bool $extended = false
    ): \DateTimeImmutable {
        return $from->modify('+' . $this->getTimeout($rememberMe, $extended) . ' seconds');
    }

    public function canCreateSession(int $activeSessionCount): bool
    {
        return $activeSessionCount < $this->maxConcurrentSessions;
    }
}
