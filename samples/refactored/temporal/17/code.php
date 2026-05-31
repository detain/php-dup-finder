<?php
declare(strict_types=1);

namespace App\Lifecycle;

use App\Contract\ResourceLockInterface;

interface LifecycleManagerInterface
{
    public function acquireLock(string $resourceId): ?ResourceLockInterface;
    public function releaseLock(ResourceLockInterface $lock): void;
    public function executeWithLock(string $resourceId, callable $operation): mixed;
}

trait LifecycleManagerTrait
{
    private array $locks = [];

    public function executeWithLock(string $resourceId, callable $operation): mixed
    {
        $lock = $this->acquireLock($resourceId);
        if ($lock === null) {
            throw new \RuntimeException("Failed to acquire lock for: {$resourceId}");
        }

        $this->locks[$resourceId] = $lock;

        try {
            return $operation();
        } finally {
            $this->releaseLock($lock);
            unset($this->locks[$resourceId]);
        }
    }

    public function cleanupLocks(): void
    {
        foreach ($this->locks as $lock) {
            $this->releaseLock($lock);
        }
        $this->locks = [];
    }
}

abstract class BaseLifecycleService
{
    use LifecycleManagerTrait;

    abstract protected function acquireLock(string $resourceId): ?ResourceLockInterface;
    abstract protected function releaseLock(ResourceLockInterface $lock): void;

    abstract protected function onCreate(): mixed;
    abstract protected function onStart(): mixed;
    abstract protected function onStop(): void;

    public function run(): mixed
    {
        $this->validate();

        $createResult = $this->executeWithLock($this->getResourceId(), fn() => $this->onCreate());
        $startResult = $this->onStart();

        return $this->buildResult($createResult, $startResult);
    }

    public function terminate(): void
    {
        $this->executeWithLock($this->getResourceId(), fn() => $this->onStop());
        $this->cleanupLocks();
    }

    abstract protected function getResourceId(): string;
    abstract protected function validate(): void;
    abstract protected function buildResult(mixed $createResult, mixed $startResult): mixed;
}
