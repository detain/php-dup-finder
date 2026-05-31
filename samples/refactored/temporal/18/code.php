<?php
declare(strict_types=1);

namespace App\Onboarding;

interface OnboardingStepInterface
{
    public function execute(string $entityId, array $context): StepResult;
    public function compensate(string $entityId): void;
    public function isRequired(array $context): bool;
}

abstract class BaseOnboardingOrchestrator
{
    protected string $entityId;
    protected array $context;
    protected array $completedSteps = [];

    public function __construct(string $entityId, array $context = [])
    {
        $this->entityId = $entityId;
        $this->context = $context;
    }

    public function execute(): OnboardingResult
    {
        $this->acquireGlobalLock();

        try {
            $steps = $this->getSteps();

            foreach ($steps as $step) {
                if (!$step->isRequired($this->context)) {
                    continue;
                }

                $result = $step->execute($this->entityId, $this->context);
                $this->completedSteps[] = ['step' => $step, 'result' => $result];
            }

            $this->finalize();

            return $this->buildResult();
        } catch (\Throwable $e) {
            $this->compensate();
            throw $e;
        } finally {
            $this->releaseGlobalLock();
        }
    }

    abstract protected function acquireGlobalLock(): void;
    abstract protected function releaseGlobalLock(): void;
    abstract protected function getSteps(): array;
    abstract protected function finalize(): void;
    abstract protected function buildResult(): OnboardingResult;

    private function compensate(): void
    {
        foreach (array_reverse($this->completedSteps) as $completed) {
            try {
                $completed['step']->compensate($this->entityId);
            } catch (\Throwable $e) {
            }
        }
    }
}
