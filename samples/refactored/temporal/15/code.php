<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Contract\WorkflowStageInterface;

abstract class WorkflowOrchestrator
{
    protected array $stages = [];
    protected string $entityId;
    protected array $context = [];

    public function __construct(string $entityId)
    {
        $this->entityId = $entityId;
    }

    public function execute(): WorkflowResult
    {
        $this->validateEntity();

        foreach ($this->getStages() as $stage) {
            $stage->setContext($this->context);
            $stage->preExecute();

            $result = $stage->execute();
            $this->handleStageResult($stage, $result);

            $stage->postExecute();
        }

        return $this->buildResult();
    }

    public function compensate(): void
    {
        foreach (array_reverse($this->stages) as $stage) {
            if ($stage->wasExecuted()) {
                $stage->compensate();
            }
        }
    }

    abstract protected function getStages(): array;
    abstract protected function validateEntity(): void;
    abstract protected function buildResult(): WorkflowResult;
    abstract protected function handleStageResult(WorkflowStageInterface $stage, mixed $result): void;
}

final class VideoUploadWorkflow extends WorkflowOrchestrator
{
    protected function getStages(): array
    {
        return [
            new InitializeUploadStage($this->entityId),
            new ValidateContentStage($this->entityId),
            new StoreAssetStage($this->entityId),
            new GenerateThumbnailsStage($this->entityId),
            new PublishStage($this->entityId),
        ];
    }

    protected function validateEntity(): void
    {
        $video = $this->videoRepository->findById($this->entityId);
        if ($video === null) {
            throw new \RuntimeException("Video not found: {$this->entityId}");
        }
    }

    protected function buildResult(): WorkflowResult
    {
        return new WorkflowResult(['entity_id' => $this->entityId, 'success' => true]);
    }

    protected function handleStageResult(WorkflowStageInterface $stage, mixed $result): void
    {
        $this->context[$stage->getName()] = $result;
    }
}
