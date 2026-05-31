<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\RateLimitService;
use App\Exception\RateLimitException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitService $rateLimitService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $identifier = $userId ?? $request->getServerParams()['REMOTE_ADDR'] ?? 'anonymous';

        $result = $this->rateLimitService->checkLimit($identifier, 'api', 100, 60);

        if (!$result['allowed']) {
            $retryAfter = $result['retry_after'] ?? 60;

            $this->logger->warning('Rate limit exceeded', [
                'identifier' => $identifier,
                'path' => $request->getUri()->getPath(),
                'retry_after' => $retryAfter,
            ]);

            throw new RateLimitException(
                'Rate limit exceeded. Please retry after ' . $retryAfter . ' seconds.',
                $retryAfter
            );
        }

        $request = $request->withAttribute('rate_limit_remaining', $result['remaining']);
        $request = $request->withAttribute('rate_limit_limit', $result['limit']);

        $this->logger->debug('Rate limit check passed', [
            'identifier' => $identifier,
            'remaining' => $result['remaining'],
            'path' => $request->getUri()->getPath(),
        ]);

        return $next($request);
    }
}
