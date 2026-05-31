<?php
declare(strict_types=1);

namespace App\Core\User\Account;

use Psr\Log\LoggerInterface;

interface AccountWorkflowStepInterface
{
    public function execute(mixed $context): void;
    public function getName(): string;
}

abstract class BaseAccountWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    protected function recordAuditEvent(string $userId, string $email, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'user_id' => $userId,
            'email' => $email,
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }

    protected function executeStep(AccountWorkflowStepInterface $step, mixed $context): void
    {
        $this->logger->debug("Executing step: {$step->getName()}");
        $step->execute($context);
    }
}

final class UserRegistrationWorkflow extends BaseAccountWorkflow {}
final class PasswordResetWorkflow extends BaseAccountWorkflow {}
final class AccountClosureWorkflow extends BaseAccountWorkflow {}
