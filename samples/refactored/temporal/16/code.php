<?php
declare(strict_types=1);

namespace App\Temporal;

interface TemporalOperationHandler
{
    public function begin(string $resourceId): bool;
    public function validate(): bool;
    public function execute(): mixed;
    public function commit(): void;
    public function rollback(): void;
}

abstract class BaseTemporalHandler implements TemporalOperationHandler
{
    protected ?object $resource = null;
    protected bool $begun = false;
    protected bool $executed = false;

    public function handle(string $resourceId): mixed
    {
        if (!$this->begin($resourceId)) {
            throw new \RuntimeException("Failed to begin operation on: {$resourceId}");
        }

        if (!$this->validate()) {
            $this->rollback();
            throw new \RuntimeException('Operation validation failed');
        }

        try {
            $result = $this->execute();
            $this->executed = true;
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->executed) {
                $this->rollback();
            }
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    public function begin(string $resourceId): bool
    {
        $this->resource = $this->acquireResource($resourceId);
        $this->begun = true;
        return $this->resource !== null;
    }

    public function validate(): bool
    {
        return $this->begun && $this->resource !== null;
    }

    abstract protected function acquireResource(string $resourceId): ?object;
    abstract public function execute(): mixed;
    abstract public function commit(): void;
    abstract public function rollback(): void;

    protected function cleanup(): void
    {
        $this->resource = null;
        $this->begun = false;
        $this->executed = false;
    }
}
