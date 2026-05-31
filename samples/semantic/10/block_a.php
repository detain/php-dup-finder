<?php

declare(strict_types=1);

namespace Acme\Http\Middleware;

use Acme\Http\Request;
use Acme\Http\Response;
use Acme\Http\Exception\UnauthorizedException;
use Acme\Auth\Adapter\JwtDecoder;
use Acme\Auth\Cache\RevocationCache;

final class AuthMiddleware
{
    public function __construct(
        private JwtDecoder $jwt,
        private RevocationCache $revoked,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = (string) $request->header('Authorization');
        $token = preg_replace('/^Bearer\s+/', '', $token);

        try {
            $claims = $this->jwt->decode($token);
        } catch (\Throwable) {
            throw new UnauthorizedException('Bad token.');
        }

        $exp = (int) ($claims['exp'] ?? 0);
        $jti = (string) ($claims['jti'] ?? '');

        $valid = $exp > time() && !$this->revoked->contains($jti);

        if (!$valid) {
            throw new UnauthorizedException('Session expired or revoked.');
        }

        return $next($request->withUser((string) $claims['sub']));
    }
}
