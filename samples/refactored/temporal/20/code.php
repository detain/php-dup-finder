<?php
declare(strict_types=1);

namespace App\Lifecycle;

use App\Contract\Lockable;

interface LifecycleStateMachine
{
    public function transition(string $fromState, string $toState): bool;
    public function getCurrentState(): string;
}

trait StateTransitionLocking
{
    protected ?Lockable $transitionLock = null;

    protected function withTransitionLock(string $resourceId, callable $operation): mixed
    {
        $lock = $this->acquireTransitionLock($resourceId);
        if ($lock === null) {
            throw new \RuntimeException("Failed to acquire transition lock: {$resourceId}");
        }

        $this->transitionLock = $lock;

        try {
            return $operation();
        } finally {
            $this->releaseTransitionLock($lock);
            $this->transitionLock = null;
        }
    }

    abstract protected function acquireTransitionLock(string $resourceId): ?Lockable;
    abstract protected function releaseTransitionLock(Lockable $lock): void;
}

abstract class BaseStatefulService
{
    use StateTransitionLocking;

    abstract protected function getStateMachine(): LifecycleStateMachine;

    public function executeTransition(string $toState): TransitionResult
    {
        $currentState = $this->getStateMachine()->getCurrentState();

        if (!$this->getStateMachine()->transition($currentState, $toState)) {
            throw new \RuntimeException("Invalid transition from {$currentState} to {$toState}");
        }

        return $this->withTransitionLock($this->getResourceId(), function () use ($toState) {
            $this->preTransition($toState);
            $result = $this->performTransition($toState);
            $this->postTransition($toState);
            return $result;
        });
    }

    abstract protected function getResourceId(): string;
    abstract protected function preTransition(string $toState): void;
    abstract protected function performTransition(string $toState): mixed;
    abstract protected function postTransition(string $toState): void;
}
