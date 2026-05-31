<?php
declare(strict_types=1);

namespace Acme\Http\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JwtBearerResolver implements MiddlewareInterface
{
    public function __construct(
        private readonly string $publicKey,
        private readonly string $issuer,
        private readonly UserRepository $users,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return $handler->handle($request);
        }
        $token = substr($header, 7);
        if ($token === '') {
            return $handler->handle($request);
        }
        try {
            $decoded = JWT::decode($token, new Key($this->publicKey, 'RS256'));
        } catch (\Throwable $e) {
            return $handler->handle($request);
        }
        if (!isset($decoded->iss) || $decoded->iss !== $this->issuer) {
            return $handler->handle($request);
        }
        if (!isset($decoded->sub) || !is_string($decoded->sub) && !is_int($decoded->sub)) {
            return $handler->handle($request);
        }
        $userId = (int) $decoded->sub;
        $row    = $this->users->findById($userId);
        if ($row === null) {
            return $handler->handle($request);
        }
        $user = new AuthenticatedUser(
            $row['id'],
            $row['email'],
            $row['display_name'],
            $row['role'],
        );
        return $handler->handle($request->withAttribute('user', $user));
    }
}

interface UserRepository
{
    /** @return array{id:int,email:string,display_name:string,role:string}|null */
    public function findById(int $id): ?array;
}
