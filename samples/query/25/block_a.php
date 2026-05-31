<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitMiddleware
{
    private const DEFAULT_LIMITS = [
        'anonymous' => 60,
        'authenticated' => 1000,
        'premium' => 5000,
    ];
    private const WINDOW_SECONDS = 60;
    private const HEADER_LIMIT = 'X-RateLimit-Limit';
    private const HEADER_REMAINING = 'X-RateLimit-Remaining';
    private const HEADER_RESET = 'X-RateLimit-Reset';
    private const BURST_ALLOWANCE = 10;
    private const BYPASS_IPS = ['127.0.0.1', '10.0.0.1'];
    private const BYPASS_ROUTES = ['/health', '/status', '/api/health'];
    private const CACHE_PREFIX = 'rate_limit:';
    private const ENABLE_LOGGING = true;
    private const STRICT_MODE = false;
    private const RETRY_AFTER_HEADER = 'Retry-After';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        $identifier = $this->resolveIdentifier($request);
        $limit = $this->resolveLimit($request);

        $key = $this->buildCacheKey($identifier, $request->path());

        $current = $this->getCurrentCount($key);
        $remaining = max(0, $limit - $current);
        $resetTime = $this->getWindowResetTime();

        if ($current >= $limit) {
            if (self::ENABLE_LOGGING) {
                $this->logRateLimitExceeded($identifier, $request->path(), $current, $limit);
            }

            return $this->buildRateLimitResponse($limit, 0, $resetTime, 'Rate limit exceeded');
        }

        $this->incrementCount($key);

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set(self::HEADER_LIMIT, (string) $limit);
            $response->headers->set(self::HEADER_REMAINING, (string) ($remaining - 1));
            $response->headers->set(self::HEADER_RESET, (string) $resetTime);

            if ($remaining <= self::BURST_ALLOWANCE) {
                $retryAfter = $resetTime - time();
                $response->headers->set(self::RETRY_AFTER_HEADER, (string) max(1, $retryAfter));
            }
        }

        return $response;
    }

    private function shouldBypass(Request $request): bool
    {
        if (in_array($request->ip(), self::BYPASS_IPS, true)) {
            return true;
        }

        if (in_array($request->path(), self::BYPASS_ROUTES, true)) {
            return true;
        }

        return false;
    }

    private function resolveIdentifier(Request $request): string
    {
        if ($request->user()) {
            return 'user:' . $request->user()->id;
        }

        return 'ip:' . $request->ip();
    }

    private function resolveLimit(Request $request): int
    {
        if ($request->user()) {
            $user = $request->user();

            if ($user->is_premium) {
                return self::DEFAULT_LIMITS['premium'];
            }

            return self::DEFAULT_LIMITS['authenticated'];
        }

        return self::DEFAULT_LIMITS['anonymous'];
    }

    private function buildCacheKey(string $identifier, string $path): string
    {
        $sanitizedPath = preg_replace('/[^a-zA-Z0-9\/]/', '_', ltrim($path, '/'));
        return self::CACHE_PREFIX . $identifier . ':' . $sanitizedPath;
    }

    private function getCurrentCount(string $key): int
    {
        $count = Redis::get($key);

        return $count !== null ? (int) $count : 0;
    }

    private function incrementCount(string $key): void
    {
        $exists = Redis::exists($key);

        if ($exists) {
            Redis::incr($key);
        } else {
            Redis::setex($key, self::WINDOW_SECONDS, 1);
        }
    }

    private function getWindowResetTime(): int
    {
        return time() + self::WINDOW_SECONDS;
    }

    private function buildRateLimitResponse(int $limit, int $remaining, int $reset, string $message): Response
    {
        $response = response()->json([
            'error' => 'rate_limit_exceeded',
            'message' => $message,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset_at' => $reset,
        ], 429);

        $response->headers->set(self::HEADER_LIMIT, (string) $limit);
        $response->headers->set(self::HEADER_REMAINING, (string) $remaining);
        $response->headers->set(self::HEADER_RESET, (string) $reset);
        $response->headers->set(self::RETRY_AFTER_HEADER, (string) max(1, $reset - time()));

        return $response;
    }

    private function logRateLimitExceeded(string $identifier, string $path, int $current, int $limit): void
    {
        \Illuminate\Support\Facades\Log::warning('Rate limit exceeded', [
            'identifier' => $identifier,
            'path' => $path,
            'current_count' => $current,
            'limit' => $limit,
            'window_seconds' => self::WINDOW_SECONDS,
            'strict_mode' => self::STRICT_MODE,
        ]);
    }
}
