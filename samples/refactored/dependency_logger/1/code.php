<?php
declare(strict_types=1);

namespace Billing\Core\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Centralized logger injection via autowiring.
 *
 * By extending the base service class, all services automatically
 * receive LoggerInterface without explicit constructor injection.
 */
abstract class LoggedService
{
    public function __construct(
        protected readonly LoggerInterface $logger
    ) {}

    protected function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($this->getServiceName() . ': ' . $message, $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        $this->logger->warning($this->getServiceName() . ': ' . $message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->logger->error($this->getServiceName() . ': ' . $message, $context);
    }

    protected function getServiceName(): string
    {
        return static::class;
    }
}

// Usage: services simply extend LoggedService instead of receiving logger via constructor
// class PaymentGatewayHandler extends LoggedService { ... }
