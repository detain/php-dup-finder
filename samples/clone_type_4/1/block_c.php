<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\Resource;
use App\Service\PolicyEvaluator;
use Psr\Log\LoggerInterface;

final class PolicyBasedAccessControl
{
    public function __construct(
        private readonly PolicyEvaluator $policyEvaluator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Determines if a user has access to a resource based on policy rules.
     *
     * This implementation uses declarative policies that define access conditions.
     * Policies can reference any attribute of the user, resource, or context.
     */
    public function canAccess(User $user, Resource $resource): bool
    {
        $policyContext = [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'user_status' => $user->getStatus(),
            'user_roles' => $user->getRoles(),
            'resource_id' => $resource->getId(),
            'resource_type' => $resource->getType(),
            'resource_owner_id' => $resource->getOwnerId(),
            'timestamp' => time(),
        ];

        $policy = $this->resolvePolicyForResource($resource);

        $result = $this->policyEvaluator->evaluate($policy, $policyContext);

        if (!$result['allowed']) {
            $this->logger->debug('Access denied - policy evaluation failed', [
                'user_id' => $user->getId(),
                'resource_id' => $resource->getId(),
                'policy' => $policy,
                'reason' => $result['reason'] ?? 'unspecified',
            ]);
        }

        return $result['allowed'];
    }

    private function resolvePolicyForResource(Resource $resource): string
    {
        return match ($resource->getType()) {
            'document' => 'document_read_policy',
            'configuration' => 'admin_policy',
            'user_data' => 'user_data_policy',
            default => 'default_read_policy',
        };
    }
}
