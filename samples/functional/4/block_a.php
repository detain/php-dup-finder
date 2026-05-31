<?php
declare(strict_types=1);

namespace Acme\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionCookieResolver implements MiddlewareInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $cookieName = 'sid',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $sid = $cookies[$this->cookieName] ?? null;
        if (!is_string($sid) || strlen($sid) !== 64) {
            return $handler->handle($request);
        }
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.role
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = :tok AND s.expires_at > NOW()'
        );
        $stmt->execute([':tok' => hash('sha256', $sid)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return $handler->handle($request);
        }
        $user = new AuthenticatedUser(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['role'],
        );
        $touched = $this->pdo->prepare('UPDATE sessions SET last_seen = NOW() WHERE token = :tok');
        $touched->execute([':tok' => hash('sha256', $sid)]);
        return $handler->handle($request->withAttribute('user', $user));
    }
}

final class AuthenticatedUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $role,
    ) {}
}
