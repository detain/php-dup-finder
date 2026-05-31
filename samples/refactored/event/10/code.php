<?php
declare(strict_types=1);

namespace App\Marketing;

use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractCampaignEventHandler
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly QueueService $queueService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    protected function executeWithTransaction(callable $operations, array $context): void
    {
        $this->logger->info('Processing campaign event', $context);

        $this->entityManager->beginTransaction();
        try {
            $operations();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Campaign event failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));
            throw $e;
        }
    }

    protected function recordCampaignAnalytics(string $eventName, int $campaignId, array $payload): void
    {
        $event = new AnalyticsEvent();
        $event->setEventName($eventName);
        $event->setCustomerId($campaignId);
        $event->setPayload($payload);
        $event->setOccurredAt(new \DateTimeImmutable());
        $this->entityManager->persist($event);
    }

    protected function createCampaignAuditEntry(
        string $action,
        int $campaignId,
        int $userId,
        array $metadata
    ): void {
        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setEntityType('campaign');
        $entry->setEntityId($campaignId);
        $entry->setUserId($userId);
        $entry->setMetadata($metadata);
        $entry->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($entry);
    }

    protected function notifyMarketingTeam(int $campaignId, string $notificationType, array $data): void
    {
        $this->queueService->publish('notifications.marketing', array_merge($data, [
            'campaign_id' => $campaignId,
            'type' => $notificationType,
        ]));
    }
}
