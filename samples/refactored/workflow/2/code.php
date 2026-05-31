<?php
declare(strict_types=1);

namespace App\Core\User\Onboarding;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

enum UserType
{
    case Customer;
    case Admin;
    case Partner;
}

interface OnboardingStepInterface
{
    public function execute(User $user): void;
    public function getName(): string;
}

abstract class BaseOnboardingWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function onboard(string $userId): void
    {
        $user = $this->findUser($userId);
        $this->validateUser($user);
        $this->logger->info("Starting {$this->getWorkflowType()->value} onboarding", ['user_id' => $userId]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $user);
        }

        $this->completeOnboarding($user);
        $this->logger->info("{$this->getWorkflowType()->value} onboarding completed", ['user_id' => $userId]);
    }

    protected function executeStep(OnboardingStepInterface $step, User $user): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['user_id' => $user->getId()->toString()]);
        $step->execute($user);
    }

    protected function recordStep(string $stepName, User $user, array $context = []): void
    {
        $this->logger->debug("Onboarding step: {$stepName}", array_merge(
            ['user_id' => $user->getId()->toString()],
            $context
        ));
    }

    abstract protected function getWorkflowType(): UserType;
    abstract protected function findUser(string $userId): User;
    abstract protected function validateUser(User $user): void;
    abstract protected function getSteps(): array;
    abstract protected function completeOnboarding(User $user): void;
}

final class CustomerOnboardingWorkflow extends BaseOnboardingWorkflow
{
    protected function getWorkflowType(): UserType { return UserType::Customer; }
    protected function getSteps(): array { return []; }
    protected function findUser(string $userId): User { throw new \RuntimeException('Not implemented'); }
    protected function validateUser(User $user): void { }
    protected function completeOnboarding(User $user): void { }
}

final class AdminOnboardingWorkflow extends BaseOnboardingWorkflow
{
    protected function getWorkflowType(): UserType { return UserType::Admin; }
    protected function getSteps(): array { return []; }
    protected function findUser(string $userId): User { throw new \RuntimeException('Not implemented'); }
    protected function validateUser(User $user): void { }
    protected function completeOnboarding(User $user): void { }
}

final class PartnerOnboardingWorkflow extends BaseOnboardingWorkflow
{
    protected function getWorkflowType(): UserType { return UserType::Partner; }
    protected function getSteps(): array { return []; }
    protected function findUser(string $userId): User { throw new \RuntimeException('Not implemented'); }
    protected function validateUser(User $user): void { }
    protected function completeOnboarding(User $user): void { }
}
