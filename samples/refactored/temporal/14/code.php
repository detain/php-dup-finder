<?php
declare(strict_types=1);

namespace App\Pattern;

interface TransactionalResource
{
    public function acquire(): bool;
    public function release(): void;
}

trait TransactionalOperations
{
    protected ?object $resourceLock = null;

    protected function withTransactionalResource(
        string $resourceId,
        callable $operations
    ): mixed {
        $resource = $this->createResource($resourceId);

        if (!$resource->acquire()) {
            throw new \RuntimeException("Failed to acquire resource: {$resourceId}");
        }

        $this->resourceLock = $resource;

        try {
            $result = $operations();
            $resource->release();
            $this->resourceLock = null;
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            $resource->release();
            $this->resourceLock = null;
            throw $e;
        }
    }

    abstract protected function createResource(string $resourceId): TransactionalResource;
    protected function rollback(): void {}
}

final class MultipartUploadTransaction implements TransactionalResource
{
    private bool $acquired = false;

    public function acquire(): bool
    {
        $this->acquired = true;
        return true;
    }

    public function release(): void
    {
        $this->acquired = false;
    }
}
