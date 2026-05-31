<?php
declare(strict_types=1);

namespace PagerDuty\Incident\Service;

use PagerDuty\Incident\Repository\IncidentRepository;
use PagerDuty\Incident\Repository\EscalationRepository;
use PagerDuty\Incident\Entity\Incident;
use PagerDuty\Incident\Entity\EscalationPolicy;
use PagerDuty\Incident\Entity\IncidentNote;
use PagerDuty\Incident\Exception\IncidentException;
use PagerDuty\Incident\Service\NotificationService;
use PagerDuty\Incident\Service\OnCallService;
use Psr\Log\LoggerInterface;

final class IncidentLifecycleService
{
    private IncidentRepository $incidentRepo;
    private EscalationRepository $escalationRepo;
    private OnCallService $onCallService;
    private NotificationService $notificationService;
    private LoggerInterface $logger;

    public function __construct(
        IncidentRepository $incidentRepo,
        EscalationRepository $escalationRepo,
        OnCallService $onCallService,
        NotificationService $notificationService,
        LoggerInterface $logger
    ) {
        $this->incidentRepo = $incidentRepo;
        $this->escalationRepo = $escalationRepo;
        $this->onCallService = $onCallService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    public function triggerIncident(string $serviceId, array $incidentData): IncidentResult
    {
        $this->logger->info('Triggering incident', [
            'service_id' => $serviceId,
            'title' => $incidentData['title'] ?? 'Untitled'
        ]);

        $service = $this->incidentRepo->findService($serviceId);
        if ($service === null) {
            throw new IncidentException("Service not found: {$serviceId}");
        }

        $escalationPolicy = $this->escalationRepo->findPolicyForService($serviceId);
        if ($escalationPolicy === null) {
            throw new IncidentException("No escalation policy for service: {$serviceId}");
        }

        $incidentKey = $incidentData['incident_key'] ?? null;
        if ($incidentKey !== null) {
            $existingIncident = $this->incidentRepo->findByIncidentKey($incidentKey);
            if ($existingIncident !== null && $existingIncident->getStatus() === 'triggered') {
                $this->logger->info('Deduplicating incident based on incident_key', [
                    'incident_key' => $incidentKey,
                    'existing_incident_id' => $existingIncident->getId()
                ]);

                $this->incidentRepo->addAcknowledgement(
                    $existingIncident->getId(),
                    'auto',
                    'Duplicate incident suppressed'
                );

                return new IncidentResult([
                    'success' => true,
                    'incident_id' => $existingIncident->getId(),
                    'deduplicated' => true
                ]);
            }
        }

        $incident = Incident::create([
            'service_id' => $serviceId,
            'title' => $incidentData['title'],
            'description' => $incidentData['description'] ?? null,
            'severity' => $incidentData['severity'] ?? 'critical',
            'status' => 'triggered',
            'incident_key' => $incidentKey,
            'escalation_policy_id' => $escalationPolicy->getId(),
            'assigned_to' => null,
            'triggered_at' => new \DateTimeImmutable(),
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedIncident = $this->incidentRepo->save($incident);
        $this->logger->debug('Incident record created', ['incident_id' => $savedIncident->getId()]);

        try {
            $onCallUsers = $this->onCallService->getCurrentOnCallUsers($escalationPolicy->getId());

            if (count($onCallUsers) === 0) {
                $this->logger->warning('No on-call users found for escalation policy', [
                    'policy_id' => $escalationPolicy->getId()
                ]);
            }

            foreach ($onCallUsers as $user) {
                $this->incidentRepo->assignToUser($savedIncident->getId(), $user->getId());
            }

            $this->notificationService->notifyIncidentTriggered($savedIncident, $onCallUsers);

            $this->incidentRepo->attachMetadata($savedIncident->getId(), [
                'dedup_key' => $incidentKey,
                'source' => $incidentData['source'] ?? 'api',
                'custom_details' => $incidentData['custom_details'] ?? []
            ]);

            $this->logger->info('Incident triggered and notifications sent', [
                'incident_id' => $savedIncident->getId(),
                'assigned_users' => count($onCallUsers)
            ]);

            return new IncidentResult([
                'success' => true,
                'incident_id' => $savedIncident->getId(),
                'incident_number' => $savedIncident->getIncidentNumber(),
                'assigned_users' => array_map(fn($u) => $u->getId(), $onCallUsers)
            ]);

        } catch (\Throwable $e) {
            $this->incidentRepo->updateStatus($savedIncident->getId(), 'failed');
            $this->logger->error('Incident trigger failed', [
                'incident_id' => $savedIncident->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function acknowledgeIncident(string $incidentId, string $userId, ?string $note = null): AcknowledgeResult
    {
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            throw new IncidentException("Incident not found: {$incidentId}");
        }

        if ($incident->getStatus() === 'resolved') {
            throw new IncidentException("Cannot acknowledge a resolved incident");
        }

        $user = $this->incidentRepo->findUser($userId);
        if ($user === null) {
            throw new IncidentException("User not found: {$userId}");
        }

        $this->incidentRepo->updateStatus($incidentId, 'acknowledged');
        $this->incidentRepo->updateAcknowledgement($incidentId, $userId, new \DateTimeImmutable());

        if ($note !== null) {
            $incidentNote = IncidentNote::create([
                'incident_id' => $incidentId,
                'user_id' => $userId,
                'content' => $note,
                'created_at' => new \DateTimeImmutable()
            ]);
            $this->incidentRepo->saveNote($incidentNote);
        }

        $this->notificationService->notifyIncidentAcknowledged($incident, $user);

        $this->logger->info('Incident acknowledged', [
            'incident_id' => $incidentId,
            'user_id' => $userId
        ]);

        return new AcknowledgeResult([
            'success' => true,
            'incident_id' => $incidentId,
            'acknowledged_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    public function resolveIncident(string $incidentId, string $userId, ?string $resolutionNote = null): ResolveResult
    {
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            throw new IncidentException("Incident not found: {$incidentId}");
        }

        if ($incident->getStatus() === 'resolved') {
            $this->logger->warning('Incident already resolved', ['incident_id' => $incidentId]);
            return new ResolveResult([
                'success' => true,
                'incident_id' => $incidentId,
                'already_resolved' => true
            ]);
        }

        $user = $this->incidentRepo->findUser($userId);
        if ($user === null) {
            throw new IncidentException("User not found: {$userId}");
        }

        $this->incidentRepo->updateStatus($incidentId, 'resolved');
        $this->incidentRepo->updateResolution($incidentId, $userId, new \DateTimeImmutable());

        if ($resolutionNote !== null) {
            $incidentNote = IncidentNote::create([
                'incident_id' => $incidentId,
                'user_id' => $userId,
                'content' => $resolutionNote,
                'type' => 'resolution',
                'created_at' => new \DateTimeImmutable()
            ]);
            $this->incidentRepo->saveNote($incidentNote);
        }

        $incidentDuration = (new \DateTimeImmutable())->getTimestamp() - $incident->getTriggeredAt()->getTimestamp();
        $this->incidentRepo->recordMetrics($incidentId, [
            'duration_seconds' => $incidentDuration,
            'acknowledged' => $incident->getStatus() !== 'triggered'
        ]);

        $this->notificationService->notifyIncidentResolved($incident, $user);

        $this->logger->info('Incident resolved', [
            'incident_id' => $incidentId,
            'user_id' => $userId,
            'duration_seconds' => $incidentDuration
        ]);

        return new ResolveResult([
            'success' => true,
            'incident_id' => $incidentId,
            'resolved_at' => (new \DateTimeImmutable())->format('c'),
            'duration_seconds' => $incidentDuration
        ]);
    }
}
