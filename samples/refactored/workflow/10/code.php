<?php
declare(strict_types=1);

namespace App\Core\Api\RateLimiting;

use Psr\Log\LoggerInterface;

interface RateLimitStepInterface
{
    public function execute(mixed $context): void;
    public function getName(): string;
}

abstract class BaseRateLimitWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    protected function executeStep(RateLimitStepInterface $step, mixed $context): void
    {
        $this->logger->debug("Executing step: {$step->getName()}");
        $step->execute($context);
    }

    protected function logAuditEvent(string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    protected function maskIdentifier(string $identifier): string
    {
        if (strlen($identifier) <= 8) {
            return '****';
        }
        return substr($identifier, 0, 4) . '****' . substr($identifier, -4);
    }

    abstract protected function getSteps(): array;
}

final class ApiRateLimitWorkflow extends BaseRateLimitWorkflow
{
    protected function getSteps(): array { return []; }
}
final class EndpointRateLimitWorkflow extends BaseRateLimitWorkflow
{
    protected function getSteps(): array { return []; }
}
final class GlobalRateLimitWorkflow extends BaseRateLimitWorkflow
{
    protected function getSteps(): array { return []; }
}
