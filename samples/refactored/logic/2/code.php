<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Log\LoggerInterface;

interface CapacityConstraintRuleInterface
{
    public function validate(array $context): ?string;
    public function getErrorMessage(): string;
}

final class PositiveQuantityRule implements CapacityConstraintRuleInterface
{
    public function validate(array $context): ?string
    {
        $quantity = $context['quantity'] ?? 0;
        if ($quantity <= 0) {
            return $this->getErrorMessage();
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Quantity must be positive';
    }
}

final class MaxQuantityRule implements CapacityConstraintRuleInterface
{
    private int $maxQuantity;

    public function __construct(int $maxQuantity)
    {
        $this->maxQuantity = $maxQuantity;
    }

    public function validate(array $context): ?string
    {
        $quantity = $context['quantity'] ?? 0;
        if ($quantity > $this->maxQuantity) {
            return "Cannot process more than {$this->maxQuantity} units at once";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Quantity exceeds maximum allowed';
    }
}

final class ValidReasonRule implements CapacityConstraintRuleInterface
{
    private array $validReasons;

    public function __construct(array $validReasons)
    {
        $this->validReasons = $validReasons;
    }

    public function validate(array $context): ?string
    {
        $reason = $context['reason'] ?? '';
        if (!in_array($reason, $this->validReasons, true)) {
            return 'Invalid reason provided';
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Reason is not valid';
    }
}

final class EntityStatusRule implements CapacityConstraintRuleInterface
{
    private array $allowedStatuses;

    public function __construct(array $allowedStatuses)
    {
        $this->allowedStatuses = $allowedStatuses;
    }

    public function validate(array $context): ?string
    {
        $status = $context['status'] ?? '';
        if (!in_array($status, $this->allowedStatuses, true)) {
            return "Entity status '{$status}' does not allow this operation";
        }
        return null;
    }

    public function getErrorMessage(): string
    {
        return 'Entity status does not permit operation';
    }
}

final class CapacityConstraintValidator
{
    /** @var CapacityConstraintRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(CapacityConstraintRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validate(array $context): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($context);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
