<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\WorkflowEntityInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

interface StatusTransitionRuleInterface
{
    public function validate(WorkflowEntityInterface $entity): ?string;
    public function getFromStatus(): string;
    public function getToStatus(): string;
}

abstract class StatusTransitionHandler
{
    public function __construct(
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoggerInterface $logger,
    ) {}

    abstract public function canTransition(string $fromStatus, string $toStatus): bool;
    abstract protected function getValidTransitions(string $status): array;
    abstract protected function getEntityStatus(object $entity): string;
    abstract protected function setEntityStatus(object $entity, string $status): void;

    public function transition(object $entity, string $toStatus): object
    {
        $fromStatus = $this->getEntityStatus($entity);

        if (!$this->canTransition($fromStatus, $toStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$fromStatus}' to '{$toStatus}'"
            );
        }

        $this->setEntityStatus($entity, $toStatus);
        $this->performTransitionSideEffects($entity, $fromStatus, $toStatus);

        $this->logger->info('Entity transitioned', [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]);

        return $entity;
    }

    protected function performTransitionSideEffects(
        object $entity,
        string $fromStatus,
        string $toStatus
    ): void {
    }
}

final class OrderStatusService extends StatusTransitionHandler
{
    private const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['processing'],
        'processing' => ['shipped'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true);
    }

    protected function getValidTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    protected function getEntityStatus(object $entity): string
    {
        return $entity->getStatus();
    }

    protected function setEntityStatus(object $entity, string $status): void
    {
        $entity->setStatus($status);
    }
}

final class StatusTransitionValidator
{
    /** @var StatusTransitionRuleInterface[] */
    private array $rules = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerRule(StatusTransitionRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function validate(WorkflowEntityInterface $entity): void
    {
        foreach ($this->rules as $rule) {
            $error = $rule->validate($entity);
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }
}
