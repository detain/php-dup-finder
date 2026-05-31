<?php
declare(strict_types=1);

namespace Billing\Core\Logging;

use Psr\Log\LoggerInterface;
use Throwable;

final class ExceptionLogger
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function logServiceException(
        Throwable $e,
        string $context,
        array $additionalData = []
    ): void {
        $isCritical = !$this->isRecoverable($e);

        $logFn = $isCritical ? 'critical' : ($this->isWarning($e) ? 'warning' : 'error');

        $this->logger->{$logFn}('Service exception occurred', [
            'context' => $context,
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            ...$additionalData
        ]);

        if ($isCritical) {
            $this->logger->debug('Critical exception stack trace', [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function isRecoverable(Throwable $e): bool
    {
        return !($e instanceof \Error) && !($e instanceof \AssertionError);
    }

    private function isWarning(Throwable $e): bool
    {
        return $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
    }
}
