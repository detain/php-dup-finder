<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\AuthService;
use App\Exception\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class AuthenticationMiddleware
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            throw new AuthenticationException('No authentication token provided');
        }

        try {
            $user = $this->authService->validateToken($token);
            $request = $request->withAttribute('user', $user);
            $request = $request->withAttribute('user_id', $user->getId());

            $this->logger->debug('User authenticated', [
                'user_id' => $user->getId(),
                'path' => $request->getUri()->getPath(),
            ]);

            return $next($request);
        } catch (\Exception $e) {
            $this->logger->warning('Authentication failed', [
                'error' => $e->getMessage(),
                'path' => $request->getUri()->getPath(),
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
            ]);

            throw new AuthenticationException('Invalid or expired token');
        }
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
