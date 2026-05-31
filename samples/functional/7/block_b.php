<?php
declare(strict_types=1);

namespace Acme\Auth\Sessions;

use Firebase\JWT\JWT;
use Psr\Http\Message\ResponseInterface;

final class JwtIssuer
{
    public function __construct(
        private readonly string $privateKey,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $lifetimeSeconds = 3600,
    ) {
        if ($lifetimeSeconds < 60) {
            throw new \InvalidArgumentException('lifetime too short');
        }
    }

    /** @param array{id:int,email:string,role:string} $user */
    public function mint(array $user, ResponseInterface $response): ResponseInterface
    {
        $now = time();
        $claims = [
            'iss'   => $this->issuer,
            'aud'   => $this->audience,
            'sub'   => (string) $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + $this->lifetimeSeconds,
            'jti'   => bin2hex(random_bytes(16)),
        ];
        try {
            $token = JWT::encode($claims, $this->privateKey, 'RS256');
        } catch (\Throwable $e) {
            throw new \RuntimeException('jwt encode failed', 0, $e);
        }
        $response = $response->withHeader('X-Auth-Token', $token);
        $cookieParts = [
            'jwt=' . $token,
            'Path=/',
            'HttpOnly',
            'Secure',
            'SameSite=Strict',
            'Max-Age=' . $this->lifetimeSeconds,
        ];
        return $response->withAddedHeader('Set-Cookie', implode('; ', $cookieParts));
    }
}
