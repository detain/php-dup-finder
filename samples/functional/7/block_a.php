<?php
declare(strict_types=1);

namespace Acme\Auth\Sessions;

use Psr\Http\Message\ResponseInterface;

final class SignedCookieIssuer
{
    public function __construct(
        private readonly string $secret,
        private readonly string $cookieName = 'session',
        private readonly int $lifetimeSeconds = 86400,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('secret too short');
        }
        if ($lifetimeSeconds < 60) {
            throw new \InvalidArgumentException('lifetime too short');
        }
    }

    /** @param array{id:int,email:string,role:string} $user */
    public function issue(array $user, ResponseInterface $response): ResponseInterface
    {
        $expires = time() + $this->lifetimeSeconds;
        $payload = [
            'uid' => $user['id'],
            'em'  => $user['email'],
            'rl'  => $user['role'],
            'exp' => $expires,
            'nce' => bin2hex(random_bytes(8)),
        ];
        $encoded = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig     = $this->base64Url(hash_hmac('sha256', $encoded, $this->secret, true));
        $token   = $encoded . '.' . $sig;
        $attrs = [
            $this->cookieName . '=' . $token,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
            'Secure',
            'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT',
        ];
        return $response->withAddedHeader('Set-Cookie', implode('; ', $attrs));
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
