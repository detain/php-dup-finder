<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequestLogger
{
    private LoggerInterface $logger;
    private bool $logHeaders;
    private bool $logBody;
    private string $prefix;

    public function __construct(
        LoggerInterface $logger,
        string $prefix = 'Request',
        bool $logHeaders = false,
        bool $logBody = false
    ) {
        $this->logger = $logger;
        $this->prefix = $prefix;
        $this->logHeaders = $logHeaders;
        $this->logBody = $logBody;
    }

    public function log(Request $request, Response $response, float $durationMs): void
    {
        $context = $this->buildContext($request, $response, $durationMs);
        
        $level = $this->determineLogLevel($response->getStatusCode());
        $this->logger->log($level, "{$this->prefix} completed", $context);
    }

    public function logStart(Request $request): void
    {
        $this->logger->info("{$this->prefix} started", [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'client_ip' => $request->getClientIp(),
        ]);
    }

    public function logError(Request $request, \Throwable $exception): void
    {
        $this->logger->error("{$this->prefix} failed with exception", [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private function buildContext(Request $request, Response $response, float $durationMs): array
    {
        $context = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 2),
            'client_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
        ];
        
        if ($this->logHeaders) {
            $context['request_headers'] = $request->headers->all();
            $context['response_headers'] = $response->headers->all();
        }
        
        if ($this->logBody && $request->getContent()) {
            $context['request_body'] = $request->getContent();
        }
        
        $context['response_size'] = strlen($response->getContent() ?: '');
        
        return $context;
    }

    private function determineLogLevel(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'error';
        }
        
        if ($statusCode >= 400) {
            return 'warning';
        }
        
        return 'info';
    }
}
