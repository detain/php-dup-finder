<?php
declare(strict_types=1);

namespace App\Marketing\Handlers;

use App\Entity\Campaign;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SchedulingService;
use App\Service\NotificationService;
use App\Service\AnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CampaignStartedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SchedulingService $schedulingService,
        private readonly NotificationService $notificationService,
        private readonly AnalyticsService $analyticsService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Campaign $campaign): void
    {
        $this->logger->info('Processing campaign started event', [
            'campaign_id' => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
            'type' => $campaign->getType(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->activateCampaign($campaign);
            $this->initializeCampaignMetrics($campaign);
            $this->scheduleCampaignJobs($campaign);
            $this->notifySubscribers($campaign);
            $this->activateAudienceSegments($campaign);
            $this->warmUpContentCache($campaign);
            $this->recordStartAnalytics($campaign);
            $this->createAuditEntry($campaign);
            $this->enableTrackingPixels($campaign);

            $this->entityManager->commit();

            $this->logger->info('Campaign started event processed', [
                'campaign_id' => $campaign->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process campaign started event', [
                'campaign_id' => $campaign->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function activateCampaign(Campaign $campaign): void
    {
        $campaign->setStatus('active');
        $campaign->setStartedAt(new \DateTimeImmutable());
        $campaign->setPausedAt(null);

        $this->entityManager->persist($campaign);

        $this->logger->debug('Activated campaign', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function initializeCampaignMetrics(Campaign $campaign): void
    {
        $metrics = new \App\Entity\CampaignMetrics();
        $metrics->setCampaignId($campaign->getId());
        $metrics->setTotalRecipients(0);
        $metrics->setDeliveredCount(0);
        $metrics->setOpenedCount(0);
        $metrics->setClickedCount(0);
        $metrics->setConvertedCount(0);
        $metrics->setBouncedCount(0);
        $metrics->setUnsubscribedCount(0);
        $metrics->setRevenueGenerated(0);
        $metrics->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($metrics);

        $this->queueService->publish('metrics.campaign_initialize', [
            'campaign_id' => $campaign->getId(),
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Initialized campaign metrics', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function scheduleCampaignJobs(Campaign $campaign): void
    {
        if ($campaign->getType() === 'email') {
            $segments = $this->entityManager
                ->getRepository(\App\Entity\AudienceSegment::class)
                ->findByCampaign($campaign->getId());

            foreach ($segments as $segment) {
                $recipients = $this->fetchSegmentRecipients($segment);

                $batchSize = 1000;
                $batches = array_chunk($recipients, $batchSize);

                foreach ($batches as $index => $batch) {
                    $sendJob = new \App\Entity\ScheduledJob();
                    $sendJob->setType('campaign_send_batch');
                    $sendJob->setCampaignId($campaign->getId());
                    $sendJob->setSegmentId($segment->getId());
                    $sendJob->setBatchNumber($index + 1);
                    $sendJob->setBatchSize(count($batch));
                    $sendJob->setScheduledFor(
                        $this->calculateBatchSendTime($campaign, $index)
                    );
                    $sendJob->setStatus('pending');
                    $sendJob->setMaxRetries(3);
                    $sendJob->setCreatedAt(new \DateTimeImmutable());

                    $this->entityManager->persist($sendJob);

                    $this->queueService->publish('jobs.schedule', [
                        'job_id' => $sendJob->getId(),
                        'type' => 'campaign_send',
                        'campaign_id' => $campaign->getId(),
                        'batch' => $index + 1,
                        'scheduled_for' => $sendJob->getScheduledFor()->format(\DATE_ATOM),
                    ]);
                }
            }
        }

        $endJob = new \App\Entity\ScheduledJob();
        $endJob->setType('campaign_end');
        $endJob->setCampaignId($campaign->getId());
        $endJob->setScheduledFor($campaign->getEndAt());
        $endJob->setStatus('pending');
        $endJob->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($endJob);

        $this->logger->debug('Scheduled campaign jobs', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function notifySubscribers(Campaign $campaign): void
    {
        $marketers = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->findByRole('marketing');

        foreach ($marketers as $marketer) {
            $notification = new \App\Entity\MarketingNotification();
            $notification->setUser($marketer);
            $notification->setType('campaign_started');
            $notification->setTitle('Campaign Started: ' . $campaign->getName());
            $notification->setBody(sprintf(
                'Your campaign "%s" is now live. Target audience: %d subscribers.',
                $campaign->getName(),
                $this->getTotalAudienceSize($campaign)
            ));
            $notification->setReferenceType('campaign');
            $notification->setReferenceId($campaign->getId());
            $notification->setStatus('unread');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);
        }

        $this->queueService->publish('notifications.marketing', [
            'campaign_id' => $campaign->getId(),
            'type' => 'campaign_started',
            'marketer_count' => count($marketers),
        ]);

        $this->logger->debug('Notified subscribers', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function activateAudienceSegments(Campaign $campaign): void
    {
        $segments = $this->entityManager
            ->getRepository(\App\Entity\AudienceSegment::class)
            ->findByCampaign($campaign->getId());

        foreach ($segments as $segment) {
            $segment->setStatus('active');
            $segment->setActivatedAt(new \DateTimeImmutable());
            $segment->setLastUsedAt(new \DateTimeImmutable());

            $this->entityManager->persist($segment);

            $this->queueService->publish('segment.activate', [
                'segment_id' => $segment->getId(),
                'campaign_id' => $campaign->getId(),
            ]);
        }

        $this->logger->debug('Activated audience segments', [
            'campaign_id' => $campaign->getId(),
            'segment_count' => count($segments),
        ]);
    }

    private function warmUpContentCache(Campaign $campaign): void
    {
        $content = $this->entityManager
            ->getRepository(\App\Entity\CampaignContent::class)
            ->findByCampaign($campaign->getId());

        foreach ($content as $item) {
            $this->queueService->publish('cache.warm_campaign', [
                'campaign_id' => $campaign->getId(),
                'content_id' => $item->getId(),
                'content_type' => $item->getType(),
            ]);
        }

        $this->logger->debug('Warmed up content cache', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function recordStartAnalytics(Campaign $campaign): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('campaign_started');
        $analyticsEvent->setCustomerId(0);
        $analyticsEvent->setPayload([
            'campaign_id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'type' => $campaign->getType(),
            'start_at' => $campaign->getStartAt()->format(\DATE_ATOM),
            'end_at' => $campaign->getEndAt()->format(\DATE_ATOM),
            'budget' => $campaign->getBudget(),
            'target_audience_size' => $this->getTotalAudienceSize($campaign),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded start analytics', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function createAuditEntry(Campaign $campaign): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CAMPAIGN_STARTED');
        $auditEntry->setEntityType('campaign');
        $auditEntry->setEntityId($campaign->getId());
        $auditEntry->setUserId($campaign->getCreatedBy());
        $auditEntry->setMetadata([
            'name' => $campaign->getName(),
            'type' => $campaign->getType(),
            'start_at' => $campaign->getStartAt()->format(\DATE_ATOM),
            'audience_size' => $this->getTotalAudienceSize($campaign),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function enableTrackingPixels(Campaign $campaign): void
    {
        if ($campaign->getType() !== 'email') {
            return;
        }

        $pixels = $this->entityManager
            ->getRepository(\App\Entity\TrackingPixel::class)
            ->findActiveByCampaign($campaign->getId());

        foreach ($pixels as $pixel) {
            $pixel->setEnabled(true);
            $pixel->setCampaignId($campaign->getId());
            $pixel->setActivatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($pixel);
        }

        $this->logger->debug('Enabled tracking pixels', [
            'campaign_id' => $campaign->getId(),
            'pixel_count' => count($pixels),
        ]);
    }

    private function fetchSegmentRecipients(\App\Entity\AudienceSegment $segment): array
    {
        return [];
    }

    private function calculateBatchSendTime(Campaign $campaign, int $batchIndex): \DateTimeImmutable
    {
        $baseTime = $campaign->getStartAt();
        $delayPerBatch = 5;
        $multiplier = $batchIndex * $delayPerBatch;
        return $baseTime->modify("+{$multiplier} minutes");
    }

    private function getTotalAudienceSize(Campaign $campaign): int
    {
        $segments = $this->entityManager
            ->getRepository(\App\Entity\AudienceSegment::class)
            ->findByCampaign($campaign->getId());

        $total = 0;
        foreach ($segments as $segment) {
            $total += $segment->getSubscriberCount();
        }

        return $total;
    }
}
