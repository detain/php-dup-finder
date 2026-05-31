<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\Resource;
use Psr\Log\LoggerInterface;

interface AccessControlStrategyInterface
{
    public function canAccess(User $user, Resource $resource): bool;
    public function getName(): string;
}

final class AccessControlFacade
{
    /** @var AccessControlStrategyInterface[] */
    private array $strategies = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerStrategy(AccessControlStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
    }

    public function canAccess(User $user, Resource $resource): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->canAccess($user, $resource)) {
                return true;
            }
        }

        return false;
    }

    public function getAccessDecision(User $user, Resource $resource): AccessDecision
    {
        $results = [];
        foreach ($this->strategies as $strategy) {
            $results[$strategy->getName()] = $strategy->canAccess($user, $resource);
        }

        $anyAllowed = in_array(true, $results, true);
        $allAllowed = !in_array(false, $results, true);

        return new AccessDecision(
            allowed: $anyAllowed,
            unanimous: $allAllowed,
            strategies: $results
        );
    }
}

final class AccessDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $unanimous,
        public readonly array $strategies,
    ) {}
}
