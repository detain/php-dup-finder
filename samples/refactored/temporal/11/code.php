<?php
declare(strict_types=1);

namespace App\Pattern\TemporalSequence;

use App\Contract\TemporalOperationInterface;

interface TemporalSequenceExecutor
{
    public function executeSequence(string $operationId, array $context): array;
    public function rollbackSequence(string $operationId): void;
}

abstract class BaseTemporalSequence implements TemporalSequenceExecutor
{
    protected array $acquiredResources = [];
    protected ?object $transaction = null;

    abstract protected function getSteps(): array;

    public function executeSequence(string $operationId, array $context): array
    {
        $steps = $this->getSteps();
        $results = [];

        foreach ($steps as $step) {
            $result = $this->executeStep($step, $context);
            $results[$step['name']] = $result;

            if ($step['type'] === 'acquire_resource') {
                $this->acquiredResources[] = $result;
            }
        }

        return $results;
    }

    public function rollbackSequence(string $operationId): void
    {
        foreach (array_reverse($this->acquiredResources) as $resource) {
            $this->releaseResource($resource);
        }
        $this->acquiredResources = [];
    }

    protected function executeStep(array $step, array $context): mixed
    {
        $handler = $step['handler'];
        return $handler($context);
    }

    protected function releaseResource(object $resource): void
    {
        if (method_exists($resource, 'release')) {
            $resource->release();
        }
    }
}

final class OrderFulfillmentSequence extends BaseTemporalSequence
{
    protected function getSteps(): array
    {
        return [
            ['name' => 'acquire_lock', 'type' => 'acquire_resource', 'handler' => fn($ctx) => $this->acquireLock($ctx)],
            ['name' => 'update_inventory', 'type' => 'action', 'handler' => fn($ctx) => $this->updateInventory($ctx)],
            ['name' => 'record_movement', 'type' => 'action', 'handler' => fn($ctx) => $this->recordMovement($ctx)],
        ];
    }

    private function acquireLock(array $ctx): object
    {
        return new LockHandle($ctx['sku'], $ctx['quantity']);
    }

    private function updateInventory(array $ctx): bool
    {
        return true;
    }

    private function recordMovement(array $ctx): void
    {
    }
}

final class LockHandle
{
    public function __construct(public readonly string $sku, public readonly int $quantity)
    {
    }

    public function release(): void
    {
    }
}
