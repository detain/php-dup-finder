<?php
declare(strict_types=1);

namespace App\Core\Crm\LeadNurturing;

use App\Domain\Entity\Lead;
use Psr\Log\LoggerInterface;

interface LeadWorkflowStepInterface
{
    public function execute(Lead $lead): void;
    public function getName(): string;
}

abstract class BaseLeadWorkflow
{
    protected readonly \DateTimeImmutable $startedAt;

    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function process(string $leadId): void
    {
        $lead = $this->findLead($leadId);
        $this->validateLead($lead);
        $this->logger->info("Starting lead workflow", ['lead_id' => $leadId]);

        foreach ($this->getSteps() as $step) {
            $this->executeStep($step, $lead);
        }

        $this->completeWorkflow($lead);
        $this->logger->info("Lead workflow completed", ['lead_id' => $leadId]);
    }

    protected function executeStep(LeadWorkflowStepInterface $step, Lead $lead): void
    {
        $this->logger->debug("Executing step: {$step->getName()}", ['lead_id' => $lead->getId()->toString()]);
        $step->execute($lead);
    }

    protected function recordActivity(Lead $lead, string $activityType, array $data = []): void
    {
        $this->logger->debug("Activity: {$activityType}", array_merge(
            ['lead_id' => $lead->getId()->toString()],
            $data
        ));
    }

    abstract protected function findLead(string $leadId): Lead;
    abstract protected function validateLead(Lead $lead): void;
    abstract protected function getSteps(): array;
    abstract protected function completeWorkflow(Lead $lead): void;
}

final class LeadNurturingWorkflow extends BaseLeadWorkflow
{
    protected function findLead(string $leadId): Lead { throw new \RuntimeException('Not implemented'); }
    protected function validateLead(Lead $lead): void { }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(Lead $lead): void { }
}
final class LeadReengagementWorkflow extends BaseLeadWorkflow
{
    protected function findLead(string $leadId): Lead { throw new \RuntimeException('Not implemented'); }
    protected function validateLead(Lead $lead): void { }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(Lead $lead): void { }
}
final class LeadConversionWorkflow extends BaseLeadWorkflow
{
    protected function findLead(string $leadId): Lead { throw new \RuntimeException('Not implemented'); }
    protected function validateLead(Lead $lead): void { }
    protected function getSteps(): array { return []; }
    protected function completeWorkflow(Lead $lead): void { }
}
