<?php

declare(strict_types=1);

namespace App\Processor;

use App\Entity\ProcessableInterface;
use App\Repository\ProcessableRepositoryInterface;
use Psr\Log\LoggerInterface;

interface BatchProcessorInterface
{
    public function process(array $ids, ...$args): ProcessingResult;
}

abstract class AbstractBatchProcessor implements BatchProcessorInterface
{
    public function __construct(
        protected readonly ProcessableRepositoryInterface $repository,
        protected readonly LoggerInterface $logger,
    ) {}

    public function process(array $ids, ...$args): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $entities = $this->repository->findByIds($ids);

        foreach ($entities as $entity) {
            try {
                $this->validate($entity);

                $this->processEntity($entity, $args);

                $this->repository->save($entity);

                $this->notify($entity, $args);

                $this->audit($entity);

                $this->logger->info($this->getSuccessMessage(), [
                    'entity_type' => $this->getEntityType(),
                    'entity_id' => $this->getEntityId($entity),
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'entity_id' => $this->getEntityId($entity),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error($this->getFailureMessage(), [
                    'entity_type' => $this->getEntityType(),
                    'entity_id' => $this->getEntityId($entity),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    abstract protected function getEntityType(): string;
    abstract protected function getEntityId(ProcessableInterface $entity): int;
    abstract protected function validate(ProcessableInterface $entity): void;
    abstract protected function processEntity(ProcessableInterface $entity, array $args): void;
    abstract protected function notify(ProcessableInterface $entity, array $args): void;
    abstract protected function audit(ProcessableInterface $entity): void;
    abstract protected function getSuccessMessage(): string;
    abstract protected function getFailureMessage(): string;
}
