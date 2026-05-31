<?php
declare(strict_types=1);

namespace App\Temporal;

use App\Contract\LockableResource;

interface TemporalSequenceInterface
{
    public function begin(): bool;
    public function validate(): bool;
    public function execute(): mixed;
    public function commit(): void;
    public function rollback(): void;
}

abstract class AbstractTemporalSequence implements TemporalSequenceInterface
{
    protected ?LockableResource $lock = null;
    protected bool $began = false;
    protected bool $committed = false;

    public function executeSequence(): mixed
    {
        if (!$this->begin()) {
            throw new \RuntimeException('Failed to begin sequence');
        }

        try {
            if (!$this->validate()) {
                $this->rollback();
                throw new \RuntimeException('Validation failed');
            }

            $result = $this->execute();
            $this->commit();
            $this->committed = true;
            return $result;
        } catch (\Throwable $e) {
            if (!$this->committed) {
                $this->rollback();
            }
            throw $e;
        }
    }

    abstract public function begin(): bool;
    abstract public function validate(): bool;
    abstract public function execute(): mixed;
    abstract public function commit(): void;
    abstract public function rollback(): void;
}

trait LockableResourceManagement
{
    protected function withResourceLock(string $resourceId, callable $operation): mixed
    {
        $lock = $this->acquireLock($resourceId);
        if ($lock === null) {
            throw new \RuntimeException("Failed to acquire lock: {$resourceId}");
        }

        try {
            return $operation();
        } finally {
            $this->releaseLock($lock);
        }
    }

    abstract protected function acquireLock(string $resourceId): ?LockableResource;
    abstract protected function releaseLock(LockableResource $lock): void;
}
