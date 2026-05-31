<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class SessionConfigLoader
{
    public const DEFAULT_TIMEOUT = 1800;
    public const EXTENDED_TIMEOUT = 7200;
    public const REMEMBER_ME_TIMEOUT = 1209600;
    public const DEFAULT_MAX_SESSIONS = 3;

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getSessionTimeout(bool $rememberMe = false, bool $extended = false): int
    {
        if ($rememberMe) {
            return $this->config['session']['remember_me_timeout']
                ?? self::REMEMBER_ME_TIMEOUT;
        }

        if ($extended) {
            return $this->config['session']['extended_timeout']
                ?? self::EXTENDED_TIMEOUT;
        }

        return $this->config['session']['default_timeout']
            ?? self::DEFAULT_TIMEOUT;
    }

    public function getMaxConcurrentSessions(): int
    {
        return $this->config['session']['max_concurrent_sessions']
            ?? self::DEFAULT_MAX_SESSIONS;
    }

    public function getCookieSettings(): array
    {
        $cookie = $this->config['session']['cookie'] ?? [];

        return [
            'name' => $cookie['name'] ?? 'PHPSESSID',
            'lifetime' => $cookie['lifetime'] ?? 0,
            'path' => $cookie['path'] ?? '/',
            'secure' => $cookie['secure'] ?? true,
            'httponly' => $cookie['httponly'] ?? true,
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ];
    }

    public function isSlidingExpirationEnabled(): bool
    {
        return $this->config['session']['sliding_expiration'] ?? true;
    }

    public function getSessionRules(): array
    {
        return [
            'timeouts' => [
                'default' => $this->getSessionTimeout(),
                'extended' => $this->getSessionTimeout(false, true),
                'remember_me' => $this->getSessionTimeout(true),
            ],
            'max_concurrent' => $this->getMaxConcurrentSessions(),
            'sliding_expiration' => $this->isSlidingExpirationEnabled(),
            'cookie' => $this->getCookieSettings(),
        ];
    }

    public function validateSessionTimeout(int $timeout): bool
    {
        $minTimeout = 60;
        $maxTimeout = 31536000;

        return $timeout >= $minTimeout && $timeout <= $maxTimeout;
    }

    public function isSessionCookieSecure(): bool
    {
        return $this->getCookieSettings()['secure'];
    }

    public function isSessionCookieHttpOnly(): bool
    {
        return $this->getCookieSettings()['httponly'];
    }
}
