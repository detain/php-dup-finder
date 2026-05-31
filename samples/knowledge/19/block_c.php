<?php
declare(strict_types=1);

namespace App\Security\Policy;

final class SessionPolicy
{
    public const DEFAULT_TIMEOUT = 1800;
    public const EXTENDED_TIMEOUT = 7200;
    public const REMEMBER_ME_TIMEOUT = 1209600;
    public const MAX_CONCURRENT_SESSIONS = 3;

    public const COOKIE_NAME = 'PHPSESSID';
    public const COOKIE_SECURE = true;
    public const COOKIE_HTTP_ONLY = true;
    public const COOKIE_SAMESITE = 'Lax';

    public function __construct(
        public readonly int $defaultTimeout = self::DEFAULT_TIMEOUT,
        public readonly int $extendedTimeout = self::EXTENDED_TIMEOUT,
        public readonly int $rememberMeTimeout = self::REMEMBER_ME_TIMEOUT,
        public readonly int $maxConcurrentSessions = self::MAX_CONCURRENT_SESSIONS,
        public readonly bool $slidingExpiration = true,
        public readonly string $cookieName = self::COOKIE_NAME,
        public readonly bool $cookieSecure = self::COOKIE_SECURE,
        public readonly bool $cookieHttpOnly = self::COOKIE_HTTP_ONLY,
        public readonly string $cookieSameSite = self::COOKIE_SAMESITE
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
            cookieName: $s['cookie_name'] ?? self::COOKIE_NAME,
            cookieSecure: $s['cookie_secure'] ?? self::COOKIE_SECURE,
            cookieHttpOnly: $s['cookie_httponly'] ?? self::COOKIE_HTTP_ONLY,
            cookieSameSite: $s['cookie_samesite'] ?? self::COOKIE_SAMESITE
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

    public function canCreateSession(int $currentSessionCount): bool
    {
        return $currentSessionCount < $this->maxConcurrentSessions;
    }

    public function calculateExpiry(\DateTimeImmutable $from, bool $rememberMe = false, bool $extended = false): \DateTimeImmutable
    {
        $timeout = $this->getTimeout($rememberMe, $extended);
        return $from->modify("+{$timeout} seconds");
    }

    public function isValidTimeout(int $timeout): bool
    {
        $minTimeout = 60;
        $maxTimeout = 31536000;

        return $timeout >= $minTimeout && $timeout <= $maxTimeout;
    }

    public function getCookieParams(): array
    {
        return [
            'name' => $this->cookieName,
            'secure' => $this->cookieSecure,
            'httponly' => $this->cookieHttpOnly,
            'samesite' => $this->cookieSameSite,
        ];
    }
}
