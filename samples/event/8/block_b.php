<?php
declare(strict_types=1);

namespace App\Support\Handlers;

use App\Entity\SupportTicket;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\AgentService;
use App\Service\CustomerService;
use App\Service\SatisfactionSurveyService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TicketResolvedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly AgentService $agentService,
        private readonly CustomerService $customerService,
        private readonly SatisfactionSurveyService $surveyService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SupportTicket $ticket): void
    {
        $this->logger->info('Processing ticket resolved event', [
            'ticket_id' => $ticket->getId(),
            'agent_id' => $ticket->getAssignedAgentId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateResolution($ticket);
            $this->closeTicket($ticket);
            $this->calculateResolutionMetrics($ticket);
            $this->updateAgentStats($ticket);
            $this->recordSlaCompliance($ticket);
            $this->sendResolutionNotification($ticket);
            $this->sendSatisfactionSurvey($ticket);
            $this->recordResolutionAnalytics($ticket);
            $this->createAuditEntry($ticket);
            $this->processTicketClosure($ticket);

            $this->entityManager->commit();

            $this->logger->info('Ticket resolved event processed', [
                'ticket_id' => $ticket->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process ticket resolved event', [
                'ticket_id' => $ticket->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateResolution(SupportTicket $ticket): void
    {
        $responses = $this->entityManager
            ->getRepository(\App\Entity\TicketResponse::class)
            ->findByTicket($ticket->getId());

        if (count($responses) === 0) {
            throw new \DomainException('Ticket must have at least one response before resolution');
        }

        $lastResponse = end($responses);
        if ($lastResponse->getAgentId() === 0) {
            throw new \DomainException('Ticket cannot be resolved with only auto-responses');
        }

        if ($ticket->getAssignedAgentId() === null) {
            throw new \DomainException('Ticket must be assigned before resolution');
        }

        $this->logger->debug('Validated resolution', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function closeTicket(SupportTicket $ticket): void
    {
        $ticket->setStatus('resolved');
        $ticket->setResolvedAt(new \DateTimeImmutable());
        $ticket->setResolutionTime(
            $ticket->getResolvedAt()->getTimestamp() - $ticket->getCreatedAt()->getTimestamp()
        );

        if ($ticket->getFirstResponseAt() !== null) {
            $firstResponseTime = $ticket->getFirstResponseAt()->getTimestamp() - $ticket->getCreatedAt()->getTimestamp();
            $ticket->setFirstResponseTime($firstResponseTime);
        }

        $this->entityManager->persist($ticket);

        $this->logger->debug('Closed ticket', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function calculateResolutionMetrics(SupportTicket $ticket): void
    {
        $resolutionMetrics = new \App\Entity\ResolutionMetrics();
        $resolutionMetrics->setTicketId($ticket->getId());
        $resolutionMetrics->setAgentId($ticket->getAssignedAgentId());
        $resolutionMetrics->setCustomerId($ticket->getCustomerId());
        $resolutionMetrics->setCategory($ticket->getCategory());
        $resolutionMetrics->setPriority($ticket->getPriority());

        $resolutionMetrics->setTotalResolutionTime($ticket->getResolutionTime());
        $resolutionMetrics->setFirstResponseTime($ticket->getFirstResponseTime());

        $resolutionMetrics->setResponseCount($ticket->getResponseCount());
        $resolutionMetrics->setAgentResponseCount($ticket->getResponseCount());

        $resolutionMetrics->setCustomerSatisfactionRating(null);
        $resolutionMetrics->setSurveySent(false);
        $resolutionMetrics->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($resolutionMetrics);

        $this->queueService->publish('metrics.ticket_resolved', [
            'ticket_id' => $ticket->getId(),
            'agent_id' => $ticket->getAssignedAgentId(),
            'resolution_time' => $ticket->getResolutionTime(),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Calculated resolution metrics', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function updateAgentStats(SupportTicket $ticket): void
    {
        $agent = $this->entityManager
            ->getRepository(\App\Entity\Agent::class)
            ->find($ticket->getAssignedAgentId());

        if ($agent === null) {
            return;
        }

        $agent->setTicketsResolved($agent->getTicketsResolved() + 1);
        $agent->setTotalResolutionTime(
            $agent->getTotalResolutionTime() + $ticket->getResolutionTime()
        );
        $agent->setAverageResolutionTime(
            $agent->getTotalResolutionTime() / $agent->getTicketsResolved()
        );
        $agent->setLastTicketResolvedAt(new \DateTimeImmutable());

        if ($ticket->getPriority() === 'urgent') {
            $agent->setUrgentTicketsResolved($agent->getUrgentTicketsResolved() + 1);
        }

        $this->entityManager->persist($agent);

        $this->logger->debug('Updated agent stats', [
            'agent_id' => $agent->getId(),
            'tickets_resolved' => $agent->getTicketsResolved(),
        ]);
    }

    private function recordSlaCompliance(SupportTicket $ticket): void
    {
        $slaCompliance = new \App\Entity\SlaComplianceRecord();
        $slaCompliance->setTicketId($ticket->getId());
        $slaCompliance->setConfigurationId($ticket->getSlaConfigurationId());
        $slaCompliance->setAgentId($ticket->getAssignedAgentId());

        $slaConfig = $this->entityManager
            ->getRepository(\App\Entity\SlaConfiguration::class)
            ->find($ticket->getSlaConfigurationId());

        $firstResponseMet = $slaConfig === null ||
            ($ticket->getFirstResponseTime() ?? PHP_INT_MAX) <= ($slaConfig->getResponseTimeMinutes() * 60);

        $resolutionMet = $slaConfig === null ||
            $ticket->getResolutionTime() <= ($slaConfig->getResolutionTimeHours() * 3600);

        $slaCompliance->setFirstResponseSlaMet($firstResponseMet);
        $slaCompliance->setResolutionSlaMet($resolutionMet);
        $slaCompliance->setOverallSlaMet($firstResponseMet && $resolutionMet);
        $slaCompliance->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($slaCompliance);

        $this->logger->debug('Recorded SLA compliance', [
            'ticket_id' => $ticket->getId(),
            'first_response_met' => $firstResponseMet,
            'resolution_met' => $resolutionMet,
        ]);
    }

    private function sendResolutionNotification(SupportTicket $ticket): void
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
            ->findOneBy(['code' => 'ticket_resolved']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $customer->getEmail(),
            'variables' => [
                'customer_name' => $customer->getFirstName(),
                'ticket_number' => $ticket->getTicketNumber(),
                'agent_name' => $agent?->getName() ?? 'Support Team',
                'resolution_summary' => $this->getResolutionSummary($ticket),
                'survey_url' => '/support/tickets/' . $ticket->getId() . '/survey',
                'feedback_url' => '/support/tickets/' . $ticket->getId() . '/feedback',
            ],
            'priority' => 'normal',
        ]);

        $this->logger->debug('Sent resolution notification', [
            'ticket_id' => $ticket->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function sendSatisfactionSurvey(SupportTicket $ticket): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($ticket->getCustomerId());

        if ($customer === null) {
            return;
        }

        $survey = new \App\Entity\SatisfactionSurvey();
        $survey->setTicketId($ticket->getId());
        $survey->setCustomerId($ticket->getCustomerId());
        $survey->setAgentId($ticket->getAssignedAgentId());
        $survey->setStatus('pending');
        $survey->setScheduledFor(
            (new \DateTimeImmutable())->modify('+1 hour')
        );
        $survey->setToken(bin2hex(random_bytes(32)));
        $survey->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($survey);

        $this->queueService->publish('email.satisfaction_survey', [
            'survey_id' => $survey->getId(),
            'customer_id' => $ticket->getCustomerId(),
            'ticket_id' => $ticket->getId(),
            'token' => $survey->getToken(),
            'scheduled_for' => $survey->getScheduledFor()->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Sent satisfaction survey', [
            'ticket_id' => $ticket->getId(),
            'survey_id' => $survey->getId(),
        ]);
    }

    private function recordResolutionAnalytics(SupportTicket $ticket): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('ticket_resolved');
        $analyticsEvent->setCustomerId($ticket->getCustomerId());
        $analyticsEvent->setPayload([
            'ticket_id' => $ticket->getId(),
            'agent_id' => $ticket->getAssignedAgentId(),
            'category' => $ticket->getCategory(),
            'priority' => $ticket->getPriority(),
            'resolution_time' => $ticket->getResolutionTime(),
            'first_response_time' => $ticket->getFirstResponseTime(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded resolution analytics', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function createAuditEntry(SupportTicket $ticket): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('TICKET_RESOLVED');
        $auditEntry->setEntityType('support_ticket');
        $auditEntry->setEntityId($ticket->getId());
        $auditEntry->setUserId($ticket->getAssignedAgentId());
        $auditEntry->setMetadata([
            'ticket_number' => $ticket->getTicketNumber(),
            'category' => $ticket->getCategory(),
            'resolution_time' => $ticket->getResolutionTime(),
            'response_count' => $ticket->getResponseCount(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function processTicketClosure(SupportTicket $ticket): void
    {
        $followUpActions = $this->entityManager
            ->getRepository(\App\Entity\FollowUpAction::class)
            ->findPendingByTicket($ticket->getId());

        foreach ($followUpActions as $action) {
            $action->setStatus('cancelled');
            $action->setCancelledReason('ticket_resolved');
            $action->setCancelledAt(new \DateTimeImmutable());

            $this->entityManager->persist($action);
        }

        $escalations = $this->entityManager
            ->getRepository(\App\Entity\TicketEscalation::class)
            ->findActiveByTicket($ticket->getId());

        foreach ($escalations as $escalation) {
            $escalation->setStatus('resolved');
            $escalation->setResolvedAt(new \DateTimeImmutable());

            $this->entityManager->persist($escalation);
        }

        $this->logger->debug('Processed ticket closure', [
            'ticket_id' => $ticket->getId(),
            'cancelled_actions' => count($followUpActions),
            'resolved_escalations' => count($escalations),
        ]);
    }

    private function getResolutionSummary(SupportTicket $ticket): string
    {
        $responses = $this->entityManager
            ->getRepository(\App\Entity\TicketResponse::class)
            ->findByTicket($ticket->getId());

        $lastAgentResponse = null;
        foreach (array_reverse($responses) as $response) {
            if ($response->getAgentId() > 0) {
                $lastAgentResponse = $response;
                break;
            }
        }

        return $lastAgentResponse?->getBody() ?? 'Your support request has been resolved.';
    }
}
