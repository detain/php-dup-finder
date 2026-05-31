<?php
declare(strict_types=1);

namespace Acme\Auth\Sessions;

use Psr\Http\Message\ResponseInterface;

interface TokenMinter
{
    /** @param array{id:int,email:string,role:string} $user */
    public function mint(array $user, int $lifetimeSeconds): string;
}

final class SessionIssuer
{
    public function __construct(
        private readonly TokenMinter $minter,
        private readonly string $cookieName = 'session',
        private readonly int $lifetimeSeconds = 86400,
    ) {
        if ($lifetimeSeconds < 60) {
            throw new \InvalidArgumentException('lifetime too short');
        }
    }

    /** @param array{id:int,email:string,role:string} $user */
    public function issue(array $user, ResponseInterface $response): ResponseInterface
    {
        $token   = $this->minter->mint($user, $this->lifetimeSeconds);
        $expires = time() + $this->lifetimeSeconds;
        $parts   = [
            $this->cookieName . '=' . $token,
            'Path=/',
            'HttpOnly',
            'Secure',
            'SameSite=Lax',
            'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT',
        ];
        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }
}
