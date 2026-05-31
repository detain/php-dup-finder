<?php
declare(strict_types=1);

namespace Audit\Logging;

use Psr\Log\LoggerInterface;

final class UserAuditLogger
{
    private const BUFFER_SIZE = 100;
    private const FLUSH_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AuditEventSerializer $serializer,
        private readonly AuditEventRepository $repository,
    ) {}

    public function log(AuditEntry $entry): void
    {
        $context = $this->prepareContext($entry);
        $this->logger->info('Audit event', $context);

        $serializedEntry = $this->serializer->serialize($entry);
        $this->bufferEvent($serializedEntry);
    }

    public function logBatch(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->log($entry);
        }
    }

    public function logUserCreated(User $user, UserContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'user.created',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'user',
            entityId: $user->getId(),
            metadata: [
                'email' => $user->getEmail(),
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
            ],
        ));
    }

    public function logUserUpdated(User $user, array $changes, UserContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'user.updated',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'user',
            entityId: $user->getId(),
            metadata: [
                'changes' => $changes,
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
            ],
        ));
    }

    public function logUserDeleted(User $user, UserContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'user.deleted',
            userId: $context->getUserId(),
            actorId: $context->getActorId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'user',
            entityId: $user->getId(),
            metadata: [
                'email' => $user->getEmail(),
                'reason' => $context->getDeletionReason(),
                'ip_address' => $context->getIpAddress(),
            ],
        ));
    }

    public function logUserLogin(User $user, LoginContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'user.login',
            userId: $user->getId(),
            actorId: $user->getId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'user',
            entityId: $user->getId(),
            metadata: [
                'login_method' => $context->getLoginMethod(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'success' => $context->isSuccessful(),
            ],
        ));
    }

    public function logUserLogout(User $user, UserContext $context): void
    {
        $this->log(new AuditEntry(
            eventType: 'user.logout',
            userId: $user->getId(),
            actorId: $user->getId(),
            timestamp: new \DateTimeImmutable(),
            entityType: 'user',
            entityId: $user->getId(),
            metadata: [
                'session_duration_seconds' => $context->getSessionDuration(),
            ],
        ));
    }

    private function prepareContext(AuditEntry $entry): array
    {
        return [
            'event_type' => $entry->eventType,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'actor_id' => $entry->actorId,
            'timestamp' => $entry->timestamp->format(\DateTimeInterface::ISO8601),
        ];
    }

    private function bufferEvent(string $serializedEntry): void
    {
        static $buffer = [];
        static $lastFlush = 0;

        $buffer[] = $serializedEntry;

        if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flushBuffer($buffer);
            $buffer = [];
            $lastFlush = time();
        }
    }

    private function flushBuffer(array $buffer): void
    {
        foreach ($buffer as $serializedEntry) {
            try {
                $entry = $this->serializer->deserialize($serializedEntry);
                $this->repository->persist($entry);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist audit entry', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
