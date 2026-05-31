<?php
declare(strict_types=1);

namespace App\Services\Tracing;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

final class MicroserviceRequestIdPropagator
{
    private LoggerInterface $logger;
    private string $headerName = 'X-Request-ID';
    private ?string $currentRequestId = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function extractRequestId(array $headers): ?string
    {
        $requestId = $headers[$this->headerName] ?? 
                     $headers[strtolower($this->headerName)] ?? 
                     $headers['X-Correlation-ID'] ?? 
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
            $headers['X-Correlation-ID'] = $this->currentRequestId;
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
