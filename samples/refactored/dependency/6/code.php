<?php

declare(strict_types=1);

namespace App\Application;

use Psr\Log\LoggerInterface;

/**
 * Base service class with logging capability.
 * Centralizes LoggerInterface injection.
 */
abstract class BaseService
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
}
