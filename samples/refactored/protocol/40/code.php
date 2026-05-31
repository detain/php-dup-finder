<?php
declare(strict_types=1);

namespace App\Services\Tracing;

use App\Logging\LoggerInterface;

final class RequestIdPropagator
{
    private LoggerInterface $logger;
    private string $headerName;
    private string $correlationHeaderName;
    private ?string $currentRequestId = null;

    public function __construct(
        LoggerInterface $logger,
        string $headerName = 'X-Request-ID',
        ?string $correlationHeaderName = null
    ) {
        $this->logger = $logger;
        $this->headerName = $headerName;
        $this->correlationHeaderName = $correlationHeaderName ?? 'X-Correlation-ID';
    }

    public function extractRequestId(array $headers): ?string
    {
        $requestId = $headers[$this->headerName] ?? 
                     $headers[strtolower($this->headerName)] ?? 
                     $headers[$this->correlationHeaderName] ?? 
                     null;
        
        if ($requestId === null) {
            $requestId = $this->generateRequestId();
            $this->logger->debug('Generated new request ID', [
                'request_id' => $requestId,
            ]);
        }
        
        $this->currentRequestId = $requestId;
        
        return $requestId;
    }

    public function getRequestId(): ?string
    {
        return $this->currentRequestId;
    }

    public function setRequestId(string $requestId): void
    {
        $this->currentRequestId = $requestId;
    }

    public function propagateToHeaders(array $headers): array
    {
        if ($this->currentRequestId !== null) {
            $headers[$this->headerName] = $this->currentRequestId;
            $headers[$this->correlationHeaderName] = $this->currentRequestId;
        }
        
        return $headers;
    }

    public function attachToLoggerContext(array $context): array
    {
        if ($this->currentRequestId !== null) {
            $context['request_id'] = $this->currentRequestId;
        }
        
        return $context;
    }

    public function logWithRequestId(string $level, string $message, array $context = []): void
    {
        $enrichedContext = $this->attachToLoggerContext($context);
        $this->logger->log($level, $message, $enrichedContext);
    }

    private function generateRequestId(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
    }
}
