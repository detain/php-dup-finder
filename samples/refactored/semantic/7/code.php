<?php

declare(strict_types=1);

namespace Acme\Shared\Policy;

use Acme\Shared\Model\User;
use Acme\Shared\Service\FeatureFlagService;

final class FeatureAccessPolicy
{
    /**
     * @param list<string> $allowedRoles
     * @param list<string> $allowedPlans
     */
    public function __construct(
        private FeatureFlagService $flags,
        private array $allowedRoles = ['admin', 'beta_tester'],
        private array $allowedPlans = ['pro', 'enterprise'],
    ) {
    }

    public function canAccess(User $user, string $feature): bool
    {
        if (!in_array(strtolower($user->roleName()), $this->allowedRoles, true)) {
            return false;
        }

        if (!in_array(strtolower($user->planCode()), $this->allowedPlans, true)) {
            return false;
        }

        return $this->flags->isEnabled($feature, $user);
    }
}

final class BetaFeatureMiddleware
{
    public function __construct(private FeatureAccessPolicy $policy) {}

    public function handle(Request $req, callable $next): Response
    {
        if (!$this->policy->canAccess($req->user(), 'ai_summaries')) {
            throw new ForbiddenException('Beta feature not available.');
        }
        return $next($req);
    }
}
