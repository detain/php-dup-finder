<?php

declare(strict_types=1);

namespace App\Authentication;

use App\Entity\AuthenticatableInterface;
use Psr\Log\LoggerInterface;

interface AuthenticationRuleInterface
{
    public function validate(AuthenticatableInterface $entity, array $context = []): ?string;
}

abstract class AbstractAuthenticationService
{
    protected array $rules = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function authenticateAndValidate(AuthenticatableInterface $entity, array $context = []): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($entity, $context);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}

final class StatusCheckRule implements AuthenticationRuleInterface
{
    private array $allowedStatuses;
    private array $blockedStatuses;

    public function __construct(array $allowedStatuses, array $blockedStatuses = ['locked'])
    {
        $this->allowedStatuses = $allowedStatuses;
        $this->blockedStatuses = $blockedStatuses;
    }

    public function validate(AuthenticatableInterface $entity, array $context = []): ?string
    {
        $status = $entity->getStatus();

        if (in_array($status, $this->blockedStatuses, true)) {
            return "Account status is: {$status}";
        }

        if (!empty($this->allowedStatuses) && !in_array($status, $this->allowedStatuses, true)) {
            return "Account status '{$status}' does not allow this operation";
        }

        return null;
    }
}

final class FailedAttemptsRule implements AuthenticationRuleInterface
{
    private int $maxAttempts;

    public function __construct(int $maxAttempts = 5)
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function validate(AuthenticatableInterface $entity, array $context = []): ?string
    {
        if ($entity->getFailedLoginAttempts() >= $this->maxAttempts) {
            return 'Account temporarily locked due to failed attempts';
        }
        return null;
    }
}

final class AuthenticationOrchestrator
{
    /** @var AuthenticationRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(AuthenticationRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validate(AuthenticatableInterface $entity, array $context = []): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($entity, $context);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
