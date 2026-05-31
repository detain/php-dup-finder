<?php
declare(strict_types=1);

namespace App\Core\Etl\Pipeline;

use Psr\Log\LoggerInterface;

interface PipelineStepInterface
{
    public function execute(mixed $pipelineRun): void;
    public function getName(): string;
}

abstract class BaseEtlPipeline
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function execute(string $pipelineRunId): void
    {
        $pipelineRun = $this->findPipelineRun($pipelineRunId);
        $this->validatePipelineRun($pipelineRun);

        $this->logger->info("Starting ETL pipeline", ['pipeline_run_id' => $pipelineRunId]);
        $this->updateStatus($pipelineRun, 'running');

        try {
            foreach ($this->getSteps() as $step) {
                $this->executeStep($step, $pipelineRun);
            }

            $this->finalize($pipelineRun, 'success');
            $this->logger->info("ETL pipeline completed", ['pipeline_run_id' => $pipelineRunId]);
        } catch (\Throwable $e) {
            $this->handleFailure($pipelineRun, $e);
            throw $e;
        }
    }

    protected function executeStep(PipelineStepInterface $step, mixed $pipelineRun): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['pipeline_run_id' => $pipelineRun->getId()->toString()]);
        $step->execute($pipelineRun);
    }

    protected function recordProgress(mixed $pipelineRun, string $stage, array $data = []): void
    {
        $this->logger->info("Pipeline progress: {$stage}", array_merge([
            'pipeline_run_id' => $pipelineRun->getId()->toString(),
        ], $data));
    }

    abstract protected function findPipelineRun(string $id): mixed;
    abstract protected function validatePipelineRun(mixed $pipelineRun): void;
    abstract protected function getSteps(): array;
    abstract protected function updateStatus(mixed $pipelineRun, string $status): void;
    abstract protected function finalize(mixed $pipelineRun, string $status): void;
    abstract protected function handleFailure(mixed $pipelineRun, \Throwable $e): void;
}

final class CustomerDataPipeline extends BaseEtlPipeline
{
    protected function findPipelineRun(string $id): mixed { throw new \RuntimeException('Not implemented'); }
    protected function validatePipelineRun(mixed $pipelineRun): void { }
    protected function getSteps(): array { return []; }
    protected function updateStatus(mixed $pipelineRun, string $status): void { }
    protected function finalize(mixed $pipelineRun, string $status): void { }
    protected function handleFailure(mixed $pipelineRun, \Throwable $e): void { }
}
final class OrderDataPipeline extends BaseEtlPipeline
{
    protected function findPipelineRun(string $id): mixed { throw new \RuntimeException('Not implemented'); }
    protected function validatePipelineRun(mixed $pipelineRun): void { }
    protected function getSteps(): array { return []; }
    protected function updateStatus(mixed $pipelineRun, string $status): void { }
    protected function finalize(mixed $pipelineRun, string $status): void { }
    protected function handleFailure(mixed $pipelineRun, \Throwable $e): void { }
}
final class InventoryDataPipeline extends BaseEtlPipeline
{
    protected function findPipelineRun(string $id): mixed { throw new \RuntimeException('Not implemented'); }
    protected function validatePipelineRun(mixed $pipelineRun): void { }
    protected function getSteps(): array { return []; }
    protected function updateStatus(mixed $pipelineRun, string $status): void { }
    protected function finalize(mixed $pipelineRun, string $status): void { }
    protected function handleFailure(mixed $pipelineRun, \Throwable $e): void { }
}
