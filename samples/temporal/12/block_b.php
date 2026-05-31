<?php
declare(strict_types=1);

namespace Slack\Workflow\Service;

use Slack\Workflow\Repository\WorkflowInstanceRepository;
use Slack\Workflow\Repository\StepExecutionRepository;
use Slack\Workflow\Entity\WorkflowDefinition;
use Slack\Workflow\Entity\StepInstance;
use Slack\Workflow\Entity\ExecutionContext;
use Slack\Workflow\Exception\StepExecutionException;
use Psr\Log\LoggerInterface;
use RdKafka\Producer;

final class WorkflowExecutionService
{
    private WorkflowInstanceRepository $instanceRepo;
    private StepExecutionRepository $stepRepo;
    private Producer $kafkaProducer;
    private LoggerInterface $logger;

    public function __construct(
        WorkflowInstanceRepository $instanceRepo,
        StepExecutionRepository $stepRepo,
        Producer $kafkaProducer,
        LoggerInterface $logger
    ) {
        $this->instanceRepo = $instanceRepo;
        $this->stepRepo = $stepRepo;
        $this->kafkaProducer = $kafkaProducer;
        $this->logger = $logger;
    }

    public function executeWorkflow(string $workflowId, array $inputData): WorkflowExecutionResult
    {
        $this->logger->info('Starting workflow execution', [
            'workflow_id' => $workflowId,
            'input_keys' => array_keys($inputData)
        ]);

        $workflow = $this->instanceRepo->findWorkflowDefinition($workflowId);
        if ($workflow === null) {
            throw new \InvalidArgumentException("Workflow not found: {$workflowId}");
        }

        $instance = $this->instanceRepo->createInstance($workflowId, [
            'status' => 'running',
            'started_at' => new \DateTimeImmutable(),
            'input_data' => json_encode($inputData)
        ]);

        $context = new ExecutionContext($instance->getId(), $inputData);
        $this->logger->debug('Workflow instance created', ['instance_id' => $instance->getId()]);

        try {
            $currentStep = $this->stepRepo->getFirstStep($workflowId);

            while ($currentStep !== null) {
                $this->logger->info('Executing workflow step', [
                    'instance_id' => $instance->getId(),
                    'step_id' => $currentStep->getId(),
                    'step_type' => $currentStep->getType()
                ]);

                $stepResult = $this->executeStep($currentStep, $context);

                $this->stepRepo->recordStepCompletion($currentStep->getId(), [
                    'status' => 'completed',
                    'output' => json_encode($stepResult),
                    'completed_at' => (new \DateTimeImmutable())->format('c')
                ]);

                $this->publishStepCompletedEvent($instance, $currentStep, $stepResult);

                $currentStep = $this->stepRepo->getNextStep($workflowId, $currentStep->getOrder());
            }

            $this->instanceRepo->updateInstanceStatus($instance->getId(), 'completed', [
                'completed_at' => (new \DateTimeImmutable())->format('c'),
                'output_data' => json_encode($context->getOutput())
            ]);

            $this->logger->info('Workflow execution completed', [
                'instance_id' => $instance->getId()
            ]);

            return new WorkflowExecutionResult([
                'success' => true,
                'instance_id' => $instance->getId(),
                'output' => $context->getOutput()
            ]);

        } catch (\Throwable $e) {
            $this->instanceRepo->updateInstanceStatus($instance->getId(), 'failed', [
                'error' => $e->getMessage(),
                'failed_at' => (new \DateTimeImmutable())->format('c')
            ]);

            $this->logger->error('Workflow execution failed', [
                'instance_id' => $instance->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new StepExecutionException(
                "Workflow {$workflowId} failed: " . $e->getMessage(),
                $instance->getId(),
                $e
            );
        }
    }

    private function executeStep(StepInstance $step, ExecutionContext $context): array
    {
        $handler = $step->getHandler();
        return $handler($context);
    }

    private function publishStepCompletedEvent($instance, StepInstance $step, array $result): void
    {
        $this->kafkaProducer->produce(
            'workflow.step.completed',
            0,
            $instance->getId(),
            json_encode([
                'instance_id' => $instance->getId(),
                'step_id' => $step->getId(),
                'result' => $result
            ])
        );
    }
}
