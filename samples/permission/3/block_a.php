<?php
declare(strict_types=1);

namespace App\Api\OAuth\Validation;

use App\Domain\Entity\ApiClient;
use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class ScopeValidationService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function validateReadScope(User $user, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Read scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Read scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->userHasScope($user, $requiredScope, 'read')) {
            $this->logger->info('Read scope validation failed: missing scope', [
                'user_id' => $user->getId()->toString(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Read scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    public function validateWriteScope(User $user, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Write scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Write scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->userHasScope($user, $requiredScope, 'write')) {
            $this->logger->info('Write scope validation failed: missing scope', [
                'user_id' => $user->getId()->toString(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Write scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    public function validateAdminScope(User $user, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Admin scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Admin scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->userHasScope($user, $requiredScope, 'admin')) {
            $this->logger->info('Admin scope validation failed: missing scope', [
                'user_id' => $user->getId()->toString(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Admin scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    private function userHasScope(User $user, string $scope, string $action): bool
    {
        $fullScope = "{$scope}:{$action}";
        foreach ($user->getApiScopes() as $userScope) {
            if ($userScope === $fullScope || $userScope === "{$scope}:*") {
                return true;
            }
        }
        return false;
    }
}
