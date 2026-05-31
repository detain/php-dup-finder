<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Psr\Log\LoggerInterface;

final class ThrottleService
{
    private const THROTTLE_LIMIT_ANON = 60;
    private const THROTTLE_LIMIT_AUTH = 1000;
    private const THROTTLE_LIMIT_ADMIN = 10000;
    private const THROTTLE_WINDOW = 60;
    private const THROTTLE_HEADER_MAX = 'X-Throttle-Limit';
    private const THROTTLE_HEADER_LEFT = 'X-Throttle-Remaining';
    private const THROTTLE_HEADER_AT = 'X-Throttle-Reset';
    private const THROTTLE_BURST = 5;
    private const THROTTLE_WHITELIST_IPS = ['127.0.0.1', '10.0.0.1'];
    private const THROTTLE_SKIP_PATHS = ['/health', '/ready', '/metrics'];
    private const THROTTLE_KEY_PREFIX = 'throttle:';
    private const THROTTLE_LOG = true;
    private const THROTTLE_ENFORCE = true;

    private LoggerInterface $log;

    public function __construct(LoggerInterface $log)
    {
        $this->log = $log;
    }

    public function attempt(string $key, int $limit, callable $callback): mixed
    {
        $cacheKey = $this->formatKey($key);
        $current = $this->getCurrent($cacheKey);

        if ($current >= $limit) {
            $this->logAttempt($key, $current, $limit, false);
            return $this->handleExceeded($limit, $this->getResetTime());
        }

        $this->increment($cacheKey);
        $this->logAttempt($key, $current, $limit, true);

        return $callback();
    }

    public function check(string $key, int $tier): array
    {
        $limit = $this->getTierLimit($tier);
        $cacheKey = $this->formatKey($key);
        $current = $this->getCurrent($cacheKey);
        $remaining = max(0, $limit - $current);

        return [
            'allowed' => $current < $limit,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $this->getResetTime(),
            'current' => $current,
        ];
    }

    public function isWhitelisted(Request $request): bool
    {
        $clientIp = $request->ip();

        if (in_array($clientIp, self::THROTTLE_WHITELIST_IPS, true)) {
            return true;
        }

        $path = $request->path();
        foreach (self::THROTTLE_SKIP_PATHS as $skipPath) {
            if ($path === $skipPath || str_starts_with($path, $skipPath)) {
                return true;
            }
        }

        return false;
    }

    public function getTierLimit(int $tier): int
    {
        return match ($tier) {
            0 => self::THROTTLE_LIMIT_ANON,
            1 => self::THROTTLE_LIMIT_AUTH,
            2 => self::THROTTLE_LIMIT_ADMIN,
            default => self::THROTTLE_LIMIT_AUTH,
        };
    }

    public function clear(string $key): bool
    {
        $cacheKey = $this->formatKey($key);
        return Redis::del($cacheKey) > 0;
    }

    private function formatKey(string $key): string
    {
        return self::THROTTLE_KEY_PREFIX . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
    }

    private function getCurrent(string $key): int
    {
        $val = Redis::get($key);
        return $val !== null ? (int) $val : 0;
    }

    private function increment(string $key): void
    {
        if (Redis::exists($key)) {
            Redis::incr($key);
        } else {
            Redis::setex($key, self::THROTTLE_WINDOW, 1);
        }
    }

    private function getResetTime(): int
    {
        return time() + self::THROTTLE_WINDOW;
    }

    private function logAttempt(string $key, int $current, int $limit, bool $allowed): void
    {
        if (!self::THROTTLE_LOG) {
            return;
        }

        $level = $allowed ? 'debug' : 'warning';
        $message = $allowed ? 'Throttle check passed' : 'Throttle limit reached';

        $this->log->log($level, $message, [
            'key' => $key,
            'current' => $current,
            'limit' => $limit,
            'window' => self::THROTTLE_WINDOW,
            'enforce' => self::THROTTLE_ENFORCE,
        ]);
    }

    private function handleExceeded(int $limit, int $reset): mixed
    {
        if (!self::THROTTLE_ENFORCE) {
            return null;
        }

        throw new \RuntimeException(sprintf(
            'Throttle limit exceeded. Limit: %d, Reset at: %d',
            $limit,
            $reset
        ));
    }

    public function getHeadersData(int $limit, int $remaining, int $reset): array
    {
        return [
            self::THROTTLE_HEADER_MAX => (string) $limit,
            self::THROTTLE_HEADER_LEFT => (string) max(0, $remaining),
            self::THROTTLE_HEADER_AT => (string) $reset,
        ];
    }
}
