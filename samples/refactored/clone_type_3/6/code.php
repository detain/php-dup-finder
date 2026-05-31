<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

interface MiddlewareInterface
{
    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface;
}

abstract class AbstractMiddleware
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function logDebug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    protected function getClientIp(ServerRequestInterface $request): string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }

    protected function getRequestPath(ServerRequestInterface $request): string
    {
        return $request->getUri()->getPath();
    }
}
