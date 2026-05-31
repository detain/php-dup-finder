<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\ProcessableEntityInterface;
use Psr\Log\LoggerInterface;

interface ProcessableRuleInterface
{
    public function validate(ProcessableEntityInterface $entity, array $context = []): ?string;
    public function getErrorMessage(): string;
}

abstract class AbstractWorkflowService
{
    /** @var ProcessableRuleInterface[] */
    protected array $rules = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function validateEntity(ProcessableEntityInterface $entity, array $context = []): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($entity, $context);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}

final class StatusCheckRule implements ProcessableRuleInterface
{
    private array $allowedStatuses;

    public function __construct(array $allowedStatuses)
    {
        $this->allowedStatuses = $allowedStatuses;
    }

    public function validate(ProcessableEntityInterface $entity, array $context = []): ?string
    {
        if (!in_array($entity->getStatus(), $this->allowedStatuses, true)) {
            return "Entity status does not allow this operation";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Status check failed';
    }
}

final class EntityStatusRule implements ProcessableRuleInterface
{
    private array $blockedStatuses;

    public function __construct(array $blockedStatuses)
    {
        $this->blockedStatuses = $blockedStatuses;
    }

    public function validate(ProcessableEntityInterface $entity, array $context = []): ?string
    {
        if (in_array($entity->getStatus(), $this->blockedStatuses, true)) {
            return "Entity is in blocked status: {$entity->getStatus()}";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Entity status is blocked';
    }
}

final class AmountRangeRule implements ProcessableRuleInterface
{
    private int $minAmount;
    private int $maxAmount;

    public function __construct(int $minAmount = 0, int $maxAmount = PHP_INT_MAX)
    {
        $this->minAmount = $minAmount;
        $this->maxAmount = $maxAmount;
    }

    public function validate(ProcessableEntityInterface $entity, array $context = []): ?string
    {
        $amount = $entity->getAmount() ?? 0;

        if ($amount < $this->minAmount) {
            return "Amount must be at least {$this->minAmount}";
        }

        if ($amount > $this->maxAmount) {
            return "Amount cannot exceed {$this->maxAmount}";
        }

        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Amount range check failed';
    }
}

final class WorkflowOrchestrator
{
    /** @var ProcessableRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(ProcessableRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validate(ProcessableEntityInterface $entity, array $context = []): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($entity, $context);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
