<?php
declare(strict_types=1);

namespace App\Support\Handlers;

use App\Entity\SupportTicket;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\AssignmentService;
use App\Service\EmailService;
use App\Service\SlaService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TicketCreatedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly AssignmentService $assignmentService,
        private readonly EmailService $emailService,
        private readonly SlaService $slaService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SupportTicket $ticket): void
    {
        $this->logger->info('Processing ticket created event', [
            'ticket_id' => $ticket->getId(),
            'customer_id' => $ticket->getCustomerId(),
            'category' => $ticket->getCategory(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->validateTicketData($ticket);
            $this->categorizeTicket($ticket);
            $this->assignTicket($ticket);
            $this->calculateSlaDeadline($ticket);
            $this->sendAcknowledgment($ticket);
            $this->notifyAssignedAgent($ticket);
            $this->recordTicketMetrics($ticket);
            $this->createAuditEntry($ticket);
            $this->triggerAutoResponses($ticket);

            $this->entityManager->commit();

            $this->logger->info('Ticket created event processed', [
                'ticket_id' => $ticket->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process ticket created event', [
                'ticket_id' => $ticket->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateTicketData(SupportTicket $ticket): void
    {
        if (empty(trim($ticket->getSubject()))) {
            throw new \DomainException('Ticket subject cannot be empty');
        }

        if (empty(trim($ticket->getDescription()))) {
            throw new \DomainException('Ticket description cannot be empty');
        }

        if (strlen($ticket->getDescription()) < 20) {
            throw new \DomainException('Ticket description is too short');
        }

        $validPriorities = ['low', 'medium', 'high', 'urgent'];
        if (!in_array($ticket->getPriority(), $validPriorities, true)) {
            throw new \DomainException('Invalid ticket priority');
        }

        $this->logger->debug('Validated ticket data', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function categorizeTicket(SupportTicket $ticket): void
    {
        $category = $this->detectCategory($ticket);

        $ticket->setCategory($category['category']);
        $ticket->setSubcategory($category['subcategory']);
        $ticket->setTags($category['tags']);
        $ticket->setCategorizationConfidence($category['confidence']);

        $this->entityManager->persist($ticket);

        $this->logger->debug('Categorized ticket', [
            'ticket_id' => $ticket->getId(),
            'category' => $category['category'],
        ]);
    }

    private function assignTicket(SupportTicket $ticket): void
    {
        $assignment = $this->assignmentService->findBestAgent(
            $ticket->getCategory(),
            $ticket->getPriority(),
            $ticket->getCustomerId()
        );

        if ($assignment !== null) {
            $ticket->setAssignedAgentId($assignment->getAgentId());
            $ticket->setAssignedAt(new \DateTimeImmutable());
            $ticket->setStatus('assigned');

            $assignmentRecord = new \App\Entity\TicketAssignment();
            $assignmentRecord->setTicket($ticket);
            $assignmentRecord->setAgentId($assignment->getAgentId());
            $assignmentRecord->setAssignedBy('system');
            $assignmentRecord->setAssignedAt(new \DateTimeImmutable());

            $this->entityManager->persist($assignmentRecord);
        } else {
            $ticket->setStatus('pending_assignment');
        }

        $this->entityManager->persist($ticket);

        $this->logger->debug('Assigned ticket', [
            'ticket_id' => $ticket->getId(),
            'agent_id' => $ticket->getAssignedAgentId(),
        ]);
    }

    private function calculateSlaDeadline(SupportTicket $ticket): void
    {
        $slaConfig = $this->entityManager
            ->getRepository(\App\Entity\SlaConfiguration::class)
            ->findByCategoryAndPriority(
                $ticket->getCategory(),
                $ticket->getPriority()
            );

        if ($slaConfig === null) {
            $slaConfig = $this->entityManager
                ->getRepository(\App\Entity\SlaConfiguration::class)
                ->findDefault();
        }

        $responseDeadline = (new \DateTimeImmutable())
            ->modify("+{$slaConfig->getResponseTimeMinutes()} minutes");

        $resolutionDeadline = (new \DateTimeImmutable())
            ->modify("+{$slaConfig->getResolutionTimeHours()} hours");

        $ticket->setFirstResponseDeadline($responseDeadline);
        $ticket->setResolutionDeadline($resolutionDeadline);
        $ticket->setSlaConfigurationId($slaConfig->getId());

        $this->entityManager->persist($ticket);

        $this->logger->debug('Calculated SLA deadlines', [
            'ticket_id' => $ticket->getId(),
            'response_deadline' => $responseDeadline->format(\DATE_ATOM),
            'resolution_deadline' => $resolutionDeadline->format(\DATE_ATOM),
        ]);
    }

    private function sendAcknowledgment(SupportTicket $ticket): void
    {
        $customer = $this->entityManager
            ->getRepository(\App\Entity\Customer::class)
            ->find($ticket->getCustomerId());

        if ($customer === null || $customer->getEmail() === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'ticket_acknowledgment']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $customer->getEmail(),
            'variables' => [
                'customer_name' => $customer->getFirstName(),
                'ticket_number' => $ticket->getTicketNumber(),
                'subject' => $ticket->getSubject(),
                'priority' => $ticket->getPriority(),
                'expected_response_time' => $this->formatDuration(
                    $this->getSlaConfig($ticket)->getResponseTimeMinutes()
                ),
            ],
            'priority' => 'normal',
        ]);

        $this->logger->debug('Sent ticket acknowledgment', [
            'ticket_id' => $ticket->getId(),
            'customer_email' => $customer->getEmail(),
        ]);
    }

    private function notifyAssignedAgent(SupportTicket $ticket): void
    {
        if ($ticket->getAssignedAgentId() === null) {
            return;
        }

        $agent = $this->entityManager
            ->getRepository(\App\Entity\Agent::class)
            ->find($ticket->getAssignedAgentId());

        if ($agent === null) {
            return;
        }

        $notification = new \App\Entity\AgentNotification();
        $notification->setAgent($agent);
        $notification->setType('new_ticket_assigned');
        $notification->setTitle('New Ticket Assigned');
        $notification->setBody(sprintf(
            'Ticket #%s has been assigned to you. Priority: %s',
            $ticket->getTicketNumber(),
            $ticket->getPriority()
        ));
        $notification->setReferenceType('ticket');
        $notification->setReferenceId($ticket->getId());
        $notification->setPriority($ticket->getPriority());
        $notification->setStatus('unread');
        $notification->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);

        $this->queueService->publish('notifications.agent', [
            'agent_id' => $agent->getId(),
            'ticket_id' => $ticket->getId(),
            'ticket_number' => $ticket->getTicketNumber(),
            'priority' => $ticket->getPriority(),
            'category' => $ticket->getCategory(),
        ]);

        $this->logger->debug('Notified assigned agent', [
            'ticket_id' => $ticket->getId(),
            'agent_id' => $agent->getId(),
        ]);
    }

    private function recordTicketMetrics(SupportTicket $ticket): void
    {
        $metrics = new \App\Entity\SupportMetrics();
        $metrics->setTicketId($ticket->getId());
        $metrics->setCustomerId($ticket->getCustomerId());
        $metrics->setCategory($ticket->getCategory());
        $metrics->setPriority($ticket->getPriority());
        $metrics->setChannel($ticket->getChannel());
        $metrics->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($metrics);

        $this->queueService->publish('metrics.ticket_created', [
            'ticket_id' => $ticket->getId(),
            'category' => $ticket->getCategory(),
            'priority' => $ticket->getPriority(),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Recorded ticket metrics', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function createAuditEntry(SupportTicket $ticket): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('TICKET_CREATED');
        $auditEntry->setEntityType('support_ticket');
        $auditEntry->setEntityId($ticket->getId());
        $auditEntry->setUserId($ticket->getCustomerId());
        $auditEntry->setMetadata([
            'ticket_number' => $ticket->getTicketNumber(),
            'category' => $ticket->getCategory(),
            'priority' => $ticket->getPriority(),
            'assigned_agent_id' => $ticket->getAssignedAgentId(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'ticket_id' => $ticket->getId(),
        ]);
    }

    private function triggerAutoResponses(SupportTicket $ticket): void
    {
        $rules = $this->entityManager
            ->getRepository(\App\Entity\AutoResponseRule::class)
            ->findByCategory($ticket->getCategory());

        foreach ($rules as $rule) {
            if ($rule->isActive() && $this->evaluateRuleCondition($rule, $ticket)) {
                $response = new \App\Entity\TicketResponse();
                $response->setTicket($ticket);
                $response->setAgentId(0);
                $response->setBody($rule->getResponseTemplate());
                $response->setType('auto');
                $response->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($response);

                $ticket->setResponseCount($ticket->getResponseCount() + 1);
                $this->entityManager->persist($ticket);

                $this->queueService->publish('email.outbound', [
                    'template' => 'auto_response',
                    'ticket_id' => $ticket->getId(),
                    'response_body' => $rule->getResponseTemplate(),
                ]);

                $this->logger->debug('Triggered auto response', [
                    'ticket_id' => $ticket->getId(),
                    'rule_id' => $rule->getId(),
                ]);
            }
        }
    }

    private function detectCategory(SupportTicket $ticket): array
    {
        $keywords = [
            'billing' => ['invoice', 'charge', 'payment', 'refund', 'subscription'],
            'technical' => ['error', 'bug', 'crash', 'not working', 'broken'],
            'account' => ['password', 'login', 'access', 'account', 'profile'],
            'shipping' => ['delivery', 'tracking', 'lost', 'damaged', 'shipping'],
        ];

        $subjectLower = strtolower($ticket->getSubject());
        $descriptionLower = strtolower($ticket->getDescription());
        $combined = $subjectLower . ' ' . $descriptionLower;

        foreach ($keywords as $category => $categoryKeywords) {
            foreach ($categoryKeywords as $keyword) {
                if (str_contains($combined, $keyword)) {
                    return [
                        'category' => $category,
                        'subcategory' => $this->detectSubcategory($category, $combined),
                        'tags' => array_intersect($categoryKeywords, explode(' ', $combined)),
                        'confidence' => 0.85,
                    ];
                }
            }
        }

        return [
            'category' => 'general',
            'subcategory' => 'inquiry',
            'tags' => [],
            'confidence' => 0.5,
        ];
    }

    private function detectSubcategory(string $category, string $text): string
    {
        return match ($category) {
            'billing' => 'payment_issue',
            'technical' => 'bug_report',
            'account' => 'access_issue',
            default => 'general_inquiry',
        };
    }

    private function evaluateRuleCondition(\App\Entity\AutoResponseRule $rule, SupportTicket $ticket): bool
    {
        return true;
    }

    private function getSlaConfig(SupportTicket $ticket): \App\Entity\SlaConfiguration
    {
        return $this->entityManager
            ->getRepository(\App\Entity\SlaConfiguration::class)
            ->findByCategoryAndPriority($ticket->getCategory(), $ticket->getPriority())
            ?? $this->entityManager->getRepository(\App\Entity\SlaConfiguration::class)->findDefault();
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} minutes";
        }
        $hours = floor($minutes / 60);
        return "{$hours} hour" . ($hours > 1 ? 's' : '');
    }
}
