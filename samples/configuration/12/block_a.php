<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class ApiRateLimitMiddleware
{
    private const RATE_LIMIT_REQUESTS = 100;
    private const RATE_LIMIT_WINDOW = 60;
    private const RATE_LIMIT_BURST = 20;
    private const RATE_LIMIT_BACKOFF = 30;
    private const RATE_LIMIT_STRATEGY = 'sliding';

    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $this->resolveRequestIdentifier($request);
        $key = 'rate_limit:' . $identifier;

        $maxAttempts = self::RATE_LIMIT_REQUESTS;
        $decayMinutes = self::RATE_LIMIT_WINDOW;
        $burstAllowance = self::RATE_LIMIT_BURST;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts + $burstAllowance)) {
            $retryAfter = RateLimiter::availableIn($key);

            Log::warning('Rate limit exceeded', [
                'identifier' => $identifier,
                'ip' => $request->ip(),
                'endpoint' => $request->path(),
                'retry_after' => $retryAfter,
                'max_requests' => $maxAttempts,
                'burst' => $burstAllowance,
            ]);

            return response()->json([
                'error' => 'Too many requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $retryAfter,
                'limit' => $maxAttempts,
                'window' => $decayMinutes,
            ], 429, [
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => time() + $retryAfter,
                'Retry-After' => $retryAfter,
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        $remaining = RateLimiter::remaining($key, $maxAttempts);
        $limit = RateLimiter::limit($key, $maxAttempts);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));
        $response->headers->set('X-RateLimit-Reset', (string) RateLimiter::availableIn($key));

        if ($remaining < $burstAllowance) {
            $backoffTime = self::RATE_LIMIT_BACKOFF * ($burstAllowance - $remaining);

            Log::info('Rate limit approaching burst threshold', [
                'identifier' => $identifier,
                'remaining' => $remaining,
                'backoff' => $backoffTime,
            ]);

            $response->headers->set('X-RateLimit-Backoff', (string) $backoffTime);
        }

        return $response;
    }

    private function resolveRequestIdentifier(Request $request): string
    {
        if ($request->user()) {
            return 'user:' . $request->user()->id;
        }

        return 'ip:' . $request->ip();
    }
}
