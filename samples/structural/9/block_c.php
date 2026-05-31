<?php

declare(strict_types=1);

namespace Acme\Http\Router;

use Acme\Http\ActionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Acme\Metrics\Counter;
use Psr\Log\LoggerInterface;

final class ActionDispatcher
{
    /** @param array<string, ActionInterface> $actions */
    public function __construct(
        private readonly array $actions,
        private readonly ResponseFactoryInterface $responses,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $route = (string) $request->getAttribute('route');
        $action = $this->actions[$route] ?? null;
        if ($action === null) {
            $this->counter->inc('action.unknown', ['route' => $route]);
            return $this->responses->createResponse(404);
        }

        $start = microtime(true);
        try {
            $response = $action->run($request);
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            $this->counter->inc('action.success', ['route' => $route]);
            $this->counter->observe('action.duration_ms', $elapsedMs, ['route' => $route]);
            $this->logger->info('action handled', ['route' => $route, 'ms' => $elapsedMs]);

            return $response;
        } catch (\Throwable $e) {
            $this->counter->inc('action.error', ['route' => $route]);
            $this->logger->error('action error', ['route' => $route, 'error' => $e->getMessage()]);
            return $this->responses->createResponse(500);
        }
    }
}
