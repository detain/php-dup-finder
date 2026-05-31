<?php
declare(strict_types=1);

namespace Acme\Auth\Sessions;

use Psr\Http\Message\ResponseInterface;

final class OpaqueDbTokenIssuer
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $cookieName = 'sid',
        private readonly int $lifetimeSeconds = 1209600,
    ) {
        if ($lifetimeSeconds < 60) {
            throw new \InvalidArgumentException('lifetime too short');
        }
    }

    /** @param array{id:int,email:string,role:string} $user */
    public function start(array $user, ResponseInterface $response): ResponseInterface
    {
        $raw    = bin2hex(random_bytes(32));
        $hash   = hash('sha256', $raw);
        $exp    = (new \DateTimeImmutable('@' . (time() + $this->lifetimeSeconds)))->format('Y-m-d H:i:s');
        $stmt   = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, token_hash, role_snapshot, expires_at, created_at)
             VALUES (:uid, :hash, :role, :exp, NOW())'
        );
        $stmt->execute([
            ':uid'  => $user['id'],
            ':hash' => $hash,
            ':role' => $user['role'],
            ':exp'  => $exp,
        ]);
        $cookieParts = [
            $this->cookieName . '=' . $raw,
            'Path=/',
            'HttpOnly',
            'Secure',
            'SameSite=Lax',
            'Max-Age=' . $this->lifetimeSeconds,
        ];
        return $response->withAddedHeader('Set-Cookie', implode('; ', $cookieParts));
    }
}
