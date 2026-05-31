<?php
declare(strict_types=1);

namespace App\Api\OAuth\Validation;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class TokenScopeValidationService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function validateTokenReadScope(User $user, string $tokenScope, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Token read scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Token read scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'token_scope' => $tokenScope,
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->tokenHasScope($tokenScope, $requiredScope, 'read')) {
            $this->logger->info('Token read scope validation failed: token insufficient scope', [
                'user_id' => $user->getId()->toString(),
                'token_scope' => $tokenScope,
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Token read scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'token_scope' => $tokenScope,
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    public function validateTokenWriteScope(User $user, string $tokenScope, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Token write scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Token write scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'token_scope' => $tokenScope,
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->tokenHasScope($tokenScope, $requiredScope, 'write')) {
            $this->logger->info('Token write scope validation failed: token insufficient scope', [
                'user_id' => $user->getId()->toString(),
                'token_scope' => $tokenScope,
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Token write scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'token_scope' => $tokenScope,
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    public function validateTokenAdminScope(User $user, string $tokenScope, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Token admin scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Token admin scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'token_scope' => $tokenScope,
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->tokenHasScope($tokenScope, $requiredScope, 'admin')) {
            $this->logger->info('Token admin scope validation failed: token insufficient scope', [
                'user_id' => $user->getId()->toString(),
                'token_scope' => $tokenScope,
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Token admin scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'token_scope' => $tokenScope,
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    private function tokenHasScope(string $tokenScope, string $requiredScope, string $action): bool
    {
        $fullScope = "{$requiredScope}:{$action}";
        if ($tokenScope === $fullScope || str_contains($tokenScope, "{$requiredScope}:*")) {
            return true;
        }
        if (str_contains($tokenScope, $fullScope)) {
            return true;
        }
        return false;
    }
}
