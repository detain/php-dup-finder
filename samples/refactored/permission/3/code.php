<?php
declare(strict_types=1);

namespace App\Core\Api\OAuth\Validation;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

enum ScopeAction: string
{
    case Read = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

interface ScopeValidationStrategy
{
    public function getAction(): ScopeAction;
    public function getRequiredScope(): string;
    public function validatePreconditions(User $user): bool;
}

abstract class BaseScopeValidator
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function validate(User $user, ScopeValidationStrategy $strategy): bool
    {
        if (!$this->validatePreconditions($user, $strategy)) {
            return false;
        }

        if (!$this->hasScope($user, $strategy->getRequiredScope(), $strategy->getAction())) {
            $this->logFailure('missing scope', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $this->logSuccess($strategy, ['user_id' => $user->getId()->toString()]);

        return true;
    }

    protected function validatePreconditions(User $user, ScopeValidationStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if (!$strategy->validatePreconditions($user)) {
            $this->logFailure('preconditions failed', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        return true;
    }

    abstract protected function hasScope(User $user, string $scope, ScopeAction $action): bool;

    private function logFailure(string $reason, ScopeValidationStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Scope validation failed: {$reason}", array_merge(
            ['strategy' => $strategy::class, 'action' => $strategy->getAction()->value],
            $context
        ));
    }

    private function logSuccess(ScopeValidationStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Scope validation passed', array_merge(
            ['strategy' => $strategy::class, 'action' => $strategy->getAction()->value],
            $context
        ));
    }
}

final class UserScopeValidator extends BaseScopeValidator
{
    protected function hasScope(User $user, string $scope, ScopeAction $action): bool
    {
        $fullScope = "{$scope}:{$action->value}";
        foreach ($user->getApiScopes() as $userScope) {
            if ($userScope === $fullScope || $userScope === "{$scope}:*") {
                return true;
            }
        }
        return false;
    }
}
