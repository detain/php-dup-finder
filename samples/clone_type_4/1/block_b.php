<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\Resource;
use App\Repository\PermissionRepository;
use Psr\Log\LoggerInterface;

final class CapabilityBasedAccessControl
{
    public function __construct(
        private readonly PermissionRepository $permissionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Determines if a user has access to a resource based on specific capabilities.
     *
     * This implementation checks granular capabilities mapped to each resource type.
     * Users may have multiple capabilities that collectively grant access.
     */
    public function canAccess(User $user, Resource $resource): bool
    {
        $userCapabilities = $this->permissionRepository->getUserCapabilities($user);
        $resourceCapability = $resource->getRequiredCapability();

        if (empty($userCapabilities)) {
            $this->logger->debug('Access denied - user has no capabilities', [
                'user_id' => $user->getId(),
                'resource_id' => $resource->getId(),
            ]);
            return false;
        }

        $hasCapability = in_array($resourceCapability, $userCapabilities, true);

        if (!$hasCapability) {
            $this->logger->debug('Access denied - missing required capability', [
                'user_id' => $user->getId(),
                'required_capability' => $resourceCapability,
                'user_capabilities' => $userCapabilities,
            ]);
        }

        return $hasCapability;
    }
}
