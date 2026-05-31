<?php
declare(strict_types=1);

namespace Acme\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiKeyResolver implements MiddlewareInterface
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $db,
        private readonly string $headerName = 'X-Api-Key',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKey = $request->getHeaderLine($this->headerName);
        if ($apiKey === '' || strlen($apiKey) < 32) {
            return $handler->handle($request);
        }
        $hashed = hash('sha256', $apiKey);
        $sql    = <<<'SQL'
            SELECT u.id, u.email, u.display_name, u.role, k.revoked_at
            FROM api_keys k
            INNER JOIN users u ON u.id = k.user_id
            WHERE k.key_hash = :hash
              AND (k.expires_at IS NULL OR k.expires_at > CURRENT_TIMESTAMP)
        SQL;
        try {
            $row = $this->db->fetchAssociative($sql, ['hash' => $hashed]);
        } catch (\Throwable $e) {
            return $handler->handle($request);
        }
        if ($row === false || $row['revoked_at'] !== null) {
            return $handler->handle($request);
        }
        $user = new AuthenticatedUser(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['role'],
        );
        $this->db->executeStatement(
            'UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE key_hash = :hash',
            ['hash' => $hashed],
        );
        return $handler->handle($request->withAttribute('user', $user));
    }
}
