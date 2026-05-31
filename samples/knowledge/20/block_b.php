<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class RateLimitConfigLoader
{
    public const DEFAULT_REQUESTS_PER_MINUTE = 60;
    public const DEFAULT_REQUESTS_PER_HOUR = 1000;
    public const DEFAULT_REQUESTS_PER_DAY = 10000;
    public const DEFAULT_BURST_SIZE = 10;

    private array $config;

    public function __construct(string $configPath)
    {
        $this->config = Yaml::parseFile($configPath);
    }

    public function getRateLimitForEndpoint(string $endpointType): int
    {
        $limits = $this->config['rate_limiting']['endpoint_limits'] ?? [];

        $defaultLimits = [
            'auth' => 5,
            'api' => 100,
            'webhook' => 30,
            'default' => self::DEFAULT_REQUESTS_PER_MINUTE
        ];

        return $limits[$endpointType] ?? $defaultLimits[$endpointType] ?? self::DEFAULT_REQUESTS_PER_MINUTE;
    }

    public function getWindowSecondsForEndpoint(string $endpointType): int
    {
        $windows = $this->config['rate_limiting']['windows'] ?? [];

        $defaultWindows = [
            'auth' => 60,
            'api' => 60,
            'webhook' => 60,
            'default' => 60
        ];

        return $windows[$endpointType] ?? $defaultWindows[$endpointType] ?? 60;
    }

    public function getBurstSizeForEndpoint(string $endpointType): int
    {
        $burstSizes = $this->config['rate_limiting']['burst_sizes'] ?? [];

        $defaultBurstSizes = [
            'auth' => 3,
            'api' => self::DEFAULT_BURST_SIZE,
            'webhook' => 5,
            'default' => self::DEFAULT_BURST_SIZE
        ];

        return $burstSizes[$endpointType] ?? $defaultBurstSizes[$endpointType] ?? self::DEFAULT_BURST_SIZE;
    }

    public function getAllRateLimits(): array
    {
        $limits = $this->config['rate_limiting']['endpoint_limits'] ?? [];

        return [
            'auth' => [
                'limit' => $limits['auth'] ?? 5,
                'window' => $this->getWindowSecondsForEndpoint('auth'),
                'burst' => $this->getBurstSizeForEndpoint('auth')
            ],
            'api' => [
                'limit' => $limits['api'] ?? 100,
                'window' => $this->getWindowSecondsForEndpoint('api'),
                'burst' => $this->getBurstSizeForEndpoint('api')
            ],
            'webhook' => [
                'limit' => $limits['webhook'] ?? 30,
                'window' => $this->getWindowSecondsForEndpoint('webhook'),
                'burst' => $this->getBurstSizeForEndpoint('webhook')
            ]
        ];
    }

    public function getGlobalRateLimit(): array
    {
        $global = $this->config['rate_limiting']['global'] ?? [];

        return [
            'per_minute' => $global['per_minute'] ?? self::DEFAULT_REQUESTS_PER_MINUTE,
            'per_hour' => $global['per_hour'] ?? self::DEFAULT_REQUESTS_PER_HOUR,
            'per_day' => $global['per_day'] ?? self::DEFAULT_REQUESTS_PER_DAY
        ];
    }

    public function isRateLimitingEnabled(): bool
    {
        return $this->config['rate_limiting']['enabled'] ?? true;
    }

    public function shouldApplyRateLimiting(string $endpointType): bool
    {
        $disabledEndpoints = $this->config['rate_limiting']['disabled_endpoints'] ?? [];
        return !in_array($endpointType, $disabledEndpoints, true);
    }

    public function validateRateLimitConfig(int $limit, int $windowSeconds): bool
    {
        $minLimit = 1;
        $maxLimit = 100000;
        $minWindow = 1;
        $maxWindow = 86400;

        return $limit >= $minLimit
            && $limit <= $maxLimit
            && $windowSeconds >= $minWindow
            && $windowSeconds <= $maxWindow;
    }
}
