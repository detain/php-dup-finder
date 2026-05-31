<?php
declare(strict_types=1);

namespace Acme\Middleware\Signup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Redis;
use Slim\Psr7\Response;

final class SignupRateLimit implements MiddlewareInterface
{
    public function __construct(private Redis $redis) {}

    public function process(ServerRequestInterface $req, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip       = $req->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        $bucket   = (int) floor(time() / 60);
        $key      = sprintf('rl:signup:%s:%d', $ip, $bucket);

        $count = $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, 60);
        }

        if ($count > 30) {
            $resp = new Response(429);
            $resp->getBody()->write(json_encode([
                'error' => 'rate_limited',
                'retry_after' => 60,
            ], JSON_THROW_ON_ERROR));
            return $resp->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($req);
    }
}
