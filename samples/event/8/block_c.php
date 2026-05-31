<?php
declare(strict_types=1);

namespace App\Support\Handlers;

use App\Entity\SupportTicket;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\EscalationService;
use App\Service\NotificationService;
use App\Service\PriorityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TicketEscalatedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly EscalationService $escalationService,
        private readonly NotificationService $notificationService,
        private readonly PriorityService $priorityService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SupportTicket $ticket, int $escalationLevel, string $reason): void
    {
        $this->logger->info('Processing ticket escalated event', [
            'ticket_id' => $ticket->getId(),
            'escalation_level' => $escalationLevel,
            'reason' => $reason,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateEscalation($ticket);
            $this->applyEscalation($ticket, $escalationLevel);
            $this->reassignToSeniorAgent($ticket, $escalationLevel);
            $this->increasePriority($ticket, $escalationLevel);
            $this->notifyEscalationTeam($ticket, $escalationLevel);
            $this->sendCustomerUpdate($ticket);
            $this->recordEscalationMetrics($ticket, $escalationLevel, $reason);
            $this->createAuditEntry($ticket, $escalationLevel, $reason);
            $this->adjustSlaDeadlines($ticket);
            $this->triggerEmergencyProcedures($ticket, $escalationLevel);

            $this->entityManager->commit();

            $this->logger->info('Ticket escalated event processed', [
                'ticket_id' => $ticket->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process ticket escalated event', [
                'ticket_id' => $ticket->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateEscalation(SupportTicket $ticket): void
    {
        if ($ticket->getStatus() === 'closed') {
            throw new \DomainException('Cannot escalate a closed ticket');
        }

        if ($ticket->getEscalationLevel() >= 3) {
            throw new \DomainException('Maximum escalation level reached');
        }

        if ($ticket->getAssignedAgentId() === null) {
            throw new \DomainException('Cannot escalate unassigned ticket');
        }

        $this->logger->debug('Validated escalation', [
            'ticket_id' => $ticket->getId(),
            'current_level' => $ticket->getEscalationLevel(),
        ]);
    }

    private function applyEscalation(SupportTicket $ticket, int $escalationLevel): void
    {
        $ticket->setEscalationLevel($escalationLevel);
        $ticket->setEscalatedAt(new \DateTimeImmutable());
        $ticket->setStatus('escalated');

        $escalationRecord = new \App\Entity\TicketEscalation();
        $escalationRecord->setTicket($ticket);
        $escalationRecord->setFromLevel($ticket->getEscalationLevel() - 1);
        $escalationRecord->setToLevel($escalationLevel);
        $escalationRecord->setReason($this->getEscalationReason($escalationLevel));
        $escalationRecord->setStatus('active');
        $escalationRecord->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($escalationRecord);
        $this->entityManager->persist($ticket);

        $this->logger->debug('Applied escalation', [
            'ticket_id' => $ticket->getId(),
            'new_level' => $escalationLevel,
        ]);
    }

    private function reassignToSeniorAgent(SupportTicket $ticket, int $escalationLevel): void
    {
        $seniorAgent = $this->escalationService->findSeniorAgent(
            $ticket->getCategory(),
            $escalationLevel
        );

        if ($seniorAgent === null) {
            $this->logger->warning('No senior agent available for escalation', [
                'ticket_id' => $ticket->getId(),
            ]);
            return;
        }

        $previousAgentId = $ticket->getAssignedAgentId();

        $ticket->setAssignedAgentId($seniorAgent->getId());
        $ticket->setAssignedAt(new \DateTimeImmutable());

        $reassignmentRecord = new \App\Entity\TicketReassignment();
        $reassignmentRecord->setTicket($ticket);
        $reassignmentRecord->setFromAgentId($previousAgentId);
        $reassignmentRecord->setToAgentId($seniorAgent->getId());
        $reassignmentRecord->setReason('escalation_level_' . $escalationLevel);
        $reassignmentRecord->setEscalationLevel($escalationLevel);
        $reassignmentRecord->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($reassignmentRecord);
        $this->entityManager->persist($ticket);

        $this->queueService->publish('notifications.agent.escalation', [
            'agent_id' => $seniorAgent->getId(),
            'ticket_id' => $ticket->getId(),
            'previous_agent_id' => $previousAgentId,
            'escalation_level' => $escalationLevel,
        ]);

        $this->logger->debug('Reassigned to senior agent', [
            'ticket_id' => $ticket->getId(),
            'new_agent_id' => $seniorAgent->getId(),
        ]);
    }

    private function increasePriority(SupportTicket $ticket, int $escalationLevel): void
    {
        $newPriority = $this->priorityService->getEscalatedPriority(
            $ticket->getPriority(),
            $escalationLevel
        );

        $previousPriority = $ticket->getPriority();
        $ticket->setPriority($newPriority);
        $ticket->setPriorityIncreasedAt(new \DateTimeImmutable());

        $priorityHistory = new \App\Entity\PriorityChange();
        $priorityHistory->setTicket($ticket);
        $priorityHistory->setFromPriority($previousPriority);
        $priorityHistory->setToPriority($newPriority);
        $priorityHistory->setReason('escalation');
        $priorityHistory->setChangedBy(0);
        $priorityHistory->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($priorityHistory);
        $this->entityManager->persist($ticket);

        $this->logger->debug('Increased ticket priority', [
            'ticket_id' => $ticket->getId(),
            'from' => $previousPriority,
            'to' => $newPriority,
        ]);
    }

    private function notifyEscalationTeam(SupportTicket $ticket, int $escalationLevel): void
    {
        $escalationContacts = $this->entityManager
            ->getRepository(\App\Entity\EscalationContact::class)
            ->findByLevel($escalationLevel);

        foreach ($escalationContacts as $contact) {
            $notification = new \App\Entity\EscalationNotification();
            $notification->setTicketId($ticket->getId());
            $notification->setContactType($contact->getType());
            $notification->setContactValue($contact->getValue());
            $notification->setEscalationLevel($escalationLevel);
            $notification->setStatus('pending');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);

            $this->queueService->publish('notifications.escalation', [
                'notification_id' => $notification->getId(),
                'type' => $contact->getType(),
                'value' => $contact->getValue(),
                'ticket_id' => $ticket->getId(),
                'priority' => $ticket->getPriority(),
                'escalation_level' => $escalationLevel,
            ]);
        }

        if ($escalationLevel >= 2) {
            $managers = $this->entityManager
                ->getRepository(\App\Entity\Manager::class)
                ->findSupportManagers();

            foreach ($managers as $manager) {
                $notification = new \App\Entity\ManagerAlert();
                $notification->setManager($manager);
                $notification->setType('high_priority_escalation');
                $notification->setTicketId($ticket->getId());
                $notification->setPriority('high');
                $notification->setMessage(sprintf(
                    'Ticket #%s has been escalated to level %d',
                    $ticket->getTicketNumber(),
                    $escalationLevel
                ));
                $notification->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($notification);
            }
        }

        $this->logger->debug('Notified escalation team', [
            'ticket_id' => $ticket->getId(),
            'contact_count' => count($escalationContacts),
        ]);
    }

    private function sendCustomerUpdate(SupportTicket $ticket): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($ticket->getCustomerId());

        if ($customer === null || $customer->getEmail() === null) {
            return;
        }

        $agent = $this->entityManager
            ->getRepository(\App\Entity\Agent::class)
            ->find($ticket->getAssignedAgentId());

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'ticket_escalated']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $customer->getEmail(),
            'variables' => [
                'customer_name' => $customer->getFirstName(),
                'ticket_number' => $ticket->getTicketNumber(),
                'new_priority' => $ticket->getPriority(),
                'agent_name' => $agent?->getName() ?? 'Senior Support',
                'escalation_reason' => $ticket->getEscalationReason(),
            ],
            'priority' => 'high',
        ]);

        $this->logger->debug('Sent customer update', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function recordEscalationMetrics(SupportTicket $ticket, int $escalationLevel, string $reason): void
    {
        $metrics = new \App\Entity\EscalationMetrics();
        $metrics->setTicketId($ticket->getId());
        $metrics->setCustomerId($ticket->getCustomerId());
        $metrics->setAgentId($ticket->getAssignedAgentId());
        $metrics->setFromLevel($escalationLevel - 1);
        $metrics->setToLevel($escalationLevel);
        $metrics->setReason($reason);
        $metrics->setTicketAge($ticket->getCreatedAt()->diff(new \DateTimeImmutable())->h);
        $metrics->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($metrics);

        $this->queueService->publish('metrics.ticket_escalated', [
            'ticket_id' => $ticket->getId(),
            'escalation_level' => $escalationLevel,
            'reason' => $reason,
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Recorded escalation metrics', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function createAuditEntry(SupportTicket $ticket, int $escalationLevel, string $reason): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('TICKET_ESCALATED');
        $auditEntry->setEntityType('support_ticket');
        $auditEntry->setEntityId($ticket->getId());
        $auditEntry->setUserId($ticket->getAssignedAgentId());
        $auditEntry->setMetadata([
            'ticket_number' => $ticket->getTicketNumber(),
            'escalation_level' => $escalationLevel,
            'reason' => $reason,
            'new_priority' => $ticket->getPriority(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function adjustSlaDeadlines(SupportTicket $ticket): void
    {
        $slaConfig = $this->entityManager
            ->getRepository(\App\Entity\SlaConfiguration::class)
            ->find($ticket->getSlaConfigurationId());

        if ($slaConfig === null) {
            return;
        }

        $escalationMultiplier = 1 - (0.25 * $ticket->getEscalationLevel());

        $newFirstResponseDeadline = (new \DateTimeImmutable())
            ->modify("+" . (int) ($slaConfig->getResponseTimeMinutes() * $escalationMultiplier) . " minutes");

        $newResolutionDeadline = (new \DateTimeImmutable())
            ->modify("+" . (int) ($slaConfig->getResolutionTimeHours() * $escalationMultiplier) . " hours");

        $ticket->setFirstResponseDeadline($newFirstResponseDeadline);
        $ticket->setResolutionDeadline($newResolutionDeadline);

        $this->entityManager->persist($ticket);

        $this->logger->debug('Adjusted SLA deadlines', [
            'ticket_id' => $ticket->getId(),
            'escalation_level' => $ticket->getEscalationLevel(),
        ]);
    }

    private function triggerEmergencyProcedures(SupportTicket $ticket, int $escalationLevel): void
    {
        if ($escalationLevel < 2) {
            return;
        }

        $emergencyContacts = $this->entityManager
            ->getRepository(\App\Entity\EmergencyContact::class)
            ->findActive();

        foreach ($emergencyContacts as $contact) {
            $this->queueService->publish('notifications.emergency', [
                'ticket_id' => $ticket->getId(),
                'contact_type' => $contact->getType(),
                'contact_value' => $contact->getValue(),
                'priority' => 'critical',
                'message' => sprintf(
                    'URGENT: Ticket #%s requires immediate attention',
                    $ticket->getTicketNumber()
                ),
            ]);
        }

        $this->logger->debug('Triggered emergency procedures', [
            'ticket_id' => $ticket->getId(),
            'escalation_level' => $escalationLevel,
        ]);
    }

    private function getEscalationReason(int $level): string
    {
        return match ($level) {
            1 => 'customer_requested',
            2 => 'sla_breach_risk',
            3 => 'complex_issue',
            default => 'general_escalation',
        };
    }
}
