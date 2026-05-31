<?php
declare(strict_types=1);

namespace App\RateLimit\Policy;

final class RateLimitPolicy
{
    public const DEFAULT_REQUESTS_PER_MINUTE = 60;
    public const DEFAULT_REQUESTS_PER_HOUR = 1000;
    public const DEFAULT_BURST_SIZE = 10;

    public const ENDPOINT_AUTH = 'auth';
    public const ENDPOINT_API = 'api';
    public const ENDPOINT_WEBHOOK = 'webhook';

    private const ENDPOINT_LIMITS = [
        self::ENDPOINT_AUTH => [
            'limit' => 5,
            'window' => 60,
            'burst' => 3
        ],
        self::ENDPOINT_API => [
            'limit' => 100,
            'window' => 60,
            'burst' => self::DEFAULT_BURST_SIZE
        ],
        self::ENDPOINT_WEBHOOK => [
            'limit' => 30,
            'window' => 60,
            'burst' => 5
        ]
    ];

    public function __construct(
        public readonly array $customLimits = [],
        public readonly bool $enabled = true,
        public readonly array $disabledEndpoints = []
    ) {}

    public static function fromConfig(array $config): self
    {
        $rl = $config['rate_limiting'] ?? [];

        return new self(
            customLimits: $rl['limits'] ?? [],
            enabled: $rl['enabled'] ?? true,
            disabledEndpoints: $rl['disabled'] ?? []
        );
    }

    public function getLimitForEndpoint(string $endpoint): int
    {
        if (in_array($endpoint, $this->disabledEndpoints, true)) {
            return PHP_INT_MAX;
        }

        if (isset($this->customLimits[$endpoint]['limit'])) {
            return $this->customLimits[$endpoint]['limit'];
        }

        return self::ENDPOINT_LIMITS[$endpoint]['limit'] ?? self::DEFAULT_REQUESTS_PER_MINUTE;
    }

    public function getWindowForEndpoint(string $endpoint): int
    {
        if (in_array($endpoint, $this->disabledEndpoints, true)) {
            return 1;
        }

        if (isset($this->customLimits[$endpoint]['window'])) {
            return $this->customLimits[$endpoint]['window'];
        }

        return self::ENDPOINT_LIMITS[$endpoint]['window'] ?? 60;
    }

    public function getBurstForEndpoint(string $endpoint): int
    {
        if (isset($this->customLimits[$endpoint]['burst'])) {
            return $this->customLimits[$endpoint]['burst'];
        }

        return self::ENDPOINT_LIMITS[$endpoint]['burst'] ?? self::DEFAULT_BURST_SIZE;
    }

    public function getConfigForEndpoint(string $endpoint): array
    {
        return [
            'limit' => $this->getLimitForEndpoint($endpoint),
            'window' => $this->getWindowForEndpoint($endpoint),
            'burst' => $this->getBurstForEndpoint($endpoint)
        ];
    }

    public function shouldRateLimit(string $endpoint): bool
    {
        return $this->enabled && !in_array($endpoint, $this->disabledEndpoints, true);
    }

    public function calculateRefillRate(string $endpoint): float
    {
        $limit = $this->getLimitForEndpoint($endpoint);
        $window = $this->getWindowForEndpoint($endpoint);

        return $limit / $window;
    }

    public function getRetryAfterSeconds(string $endpoint, int $tokensAvailable): int
    {
        if ($tokensAvailable > 0) {
            return 0;
        }

        $window = $this->getWindowForEndpoint($endpoint);
        $burst = $this->getBurstForEndpoint($endpoint);

        return max(1, (int) ceil($window / max(1, $burst)));
    }
}
