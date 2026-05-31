<?php

declare(strict_types=1);

namespace Acme\GraphQL\Resolvers;

use Acme\GraphQL\Context;
use Acme\Domain\Enum\UserRole;
use Acme\Domain\Service\FeatureFlagService;
use Acme\GraphQL\Exception\AccessDeniedException;

final class BetaSummaryResolver
{
    public function __construct(private FeatureFlagService $flags)
    {
    }

    public function resolve(Context $ctx, array $args): array
    {
        $user = $ctx->user();
        $role = $user->role();
        $plan = $user->plan();

        $hasRole = $role === UserRole::ADMIN || $role === UserRole::BETA_TESTER;
        $hasPlan = $plan->isPro() || $plan->isEnterprise();
        $hasFlag = $this->flags->isEnabled('ai_summaries', $user);

        if (!($hasRole && $hasPlan && $hasFlag)) {
            throw new AccessDeniedException('AI summaries unavailable.');
        }

        return [
            'summary_id' => $args['id'],
            'text' => $this->fetchSummary((string) $args['id']),
        ];
    }

    private function fetchSummary(string $id): string
    {
        return 'Summary for ' . $id;
    }
}
