<?php
declare(strict_types=1);

namespace Acme\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Redis;
use Slim\Psr7\Response;

final class RateLimitPolicy implements MiddlewareInterface
{
    public const WINDOW_SECONDS = 60;
    public const MAX_REQUESTS   = 30;

    public function __construct(
        private Redis $redis,
        private string $scope,
    ) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip     = $req->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $bucket = (int) floor(time() / self::WINDOW_SECONDS);
        $key    = sprintf('rl:%s:%s:%d', $this->scope, $ip, $bucket);

        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, self::WINDOW_SECONDS);
        }

        if ($count > self::MAX_REQUESTS) {
            $resp = new Response(429);
            $resp->getBody()->write(json_encode([
                'error'       => 'rate_limited',
                'retry_after' => self::WINDOW_SECONDS,
            ], JSON_THROW_ON_ERROR));

            return $resp->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($req);
    }
}

// Usage: new RateLimitPolicy($redis, 'login'); new RateLimitPolicy($redis, 'signup');
