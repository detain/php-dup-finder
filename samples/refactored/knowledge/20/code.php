<?php
declare(strict_types=1);

namespace App\RateLimit\Policy;

final class RateLimitPolicy
{
    public const DEFAULT_REQUESTS_PER_MINUTE = 60;
    public const DEFAULT_BURST_SIZE = 10;

    public function __construct(
        public readonly array $endpointConfigs = [
            'auth' => ['limit' => 5, 'window' => 60, 'burst' => 3],
            'api' => ['limit' => 100, 'window' => 60, 'burst' => 10],
            'webhook' => ['limit' => 30, 'window' => 60, 'burst' => 5]
        ],
        public readonly bool $enabled = true,
        public readonly array $disabledEndpoints = []
    ) {}

    public static function fromConfig(array $config): self
    {
        $rl = $config['rate_limiting'] ?? [];

        return new self(
            endpointConfigs: $rl['endpoints'] ?? [
                'auth' => ['limit' => 5, 'window' => 60, 'burst' => 3],
                'api' => ['limit' => 100, 'window' => 60, 'burst' => 10],
                'webhook' => ['limit' => 30, 'window' => 60, 'burst' => 5]
            ],
            enabled: $rl['enabled'] ?? true,
            disabledEndpoints: $rl['disabled'] ?? []
        );
    }

    public function getConfig(string $endpoint): array
    {
        if (in_array($endpoint, $this->disabledEndpoints, true)) {
            return ['limit' => PHP_INT_MAX, 'window' => 1, 'burst' => PHP_INT_MAX];
        }

        return $this->endpointConfigs[$endpoint] ?? [
            'limit' => self::DEFAULT_REQUESTS_PER_MINUTE,
            'window' => 60,
            'burst' => self::DEFAULT_BURST_SIZE
        ];
    }

    public function getLimit(string $endpoint): int
    {
        return $this->getConfig($endpoint)['limit'];
    }

    public function getWindow(string $endpoint): int
    {
        return $this->getConfig($endpoint)['window'];
    }

    public function getBurst(string $endpoint): int
    {
        return $this->getConfig($endpoint)['burst'];
    }

    public function shouldRateLimit(string $endpoint): bool
    {
        return $this->enabled && !in_array($endpoint, $this->disabledEndpoints, true);
    }

    public function calculateRefillRate(string $endpoint): float
    {
        $config = $this->getConfig($endpoint);
        return $config['limit'] / $config['window'];
    }
}
