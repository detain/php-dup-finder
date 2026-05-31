<?php
declare(strict_types=1);

namespace App\Cron\Middleware;

use App\Logging\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CronRequestLogger
{
    private LoggerInterface $logger;
    private bool $logHeaders;
    private bool $logBody;

    public function __construct(
        LoggerInterface $logger,
        bool $logHeaders = false,
        bool $logBody = false
    ) {
        $this->logger = $logger;
        $this->logHeaders = $logHeaders;
        $this->logBody = $logBody;
    }

    public function log(Request $request, Response $response, float $durationMs): void
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
        
        if ($response->getStatusCode() >= 500) {
            $this->logger->error('Cron request completed with server error', $context);
        } elseif ($response->getStatusCode() >= 400) {
            $this->logger->warning('Cron request completed with client error', $context);
        } else {
            $this->logger->info('Cron request completed', $context);
        }
    }

    public function logStart(Request $request): void
    {
        $this->logger->info('Cron request started', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'client_ip' => $request->getClientIp(),
        ]);
    }

    public function logError(Request $request, \Throwable $exception): void
    {
        $this->logger->error('Cron request failed with exception', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
