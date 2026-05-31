<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('session')]
final class SessionConfig
{
    public function __construct(
        public readonly int $lifetime = 120,
        public readonly bool $extendOnActivity = true,
        public readonly int $regenerateInterval = 300,
        public readonly bool $secureCookie = true,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'lax',
    ) {}
}

#[Configuration('cache')]
final class CacheConfig
{
    public const TTL_DEFAULT = 3600;
    public const TTL_SHORT = 300;
    public const TTL_MEDIUM = 1800;
    public const TTL_LONG = 7200;

    public function __construct(
        public readonly int $ttlDefault = 3600,
        public readonly int $ttlShort = 300,
        public readonly int $ttlMedium = 1800,
        public readonly int $ttlLong = 7200,
        public readonly string $prefix = 'app:',
        public readonly bool $compression = true,
    ) {}
}

#[Configuration('security')]
final class SecurityHeadersConfig
{
    public function __construct(
        public readonly string $cspDefaultSrc = "'self'",
        public readonly string $cspScriptSrc = "'self' 'unsafe-inline'",
        public readonly bool $upgradeInsecureRequests = true,
        public readonly bool $blockMixedContent = true,
    ) {}
}

trait HasConfigurableTtl
{
    protected abstract function getCacheConfig(): CacheConfig;

    protected function getTtl(string $duration): int
    {
        $config = $this->getCacheConfig();

        return match ($duration) {
            'short' => $config->ttlShort,
            'medium' => $config->ttlMedium,
            'long' => $config->ttlLong,
            default => $config->ttlDefault,
        };
    }
}
