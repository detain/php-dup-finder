<?php
declare(strict_types=1);

namespace App\Marketing\Handlers;

use App\Entity\Campaign;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SchedulingService;
use App\Service\NotificationService;
use App\Service\PauseService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CampaignPausedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SchedulingService $schedulingService,
        private readonly NotificationService $notificationService,
        private readonly PauseService $pauseService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Campaign $campaign, string $reason): void
    {
        $this->logger->info('Processing campaign paused event', [
            'campaign_id' => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
            'reason' => $reason,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->pauseCampaign($campaign, $reason);
            $this->cancelScheduledJobs($campaign);
            $this->recordPauseMetrics($campaign, $reason);
            $this->notifyMarketingTeam($campaign, $reason);
            $this->freezeBudgetAllocation($campaign);
            $this->pauseAudienceSegments($campaign);
            $this->preserveCurrentProgress($campaign);
            $this->recordPauseAnalytics($campaign, $reason);
            $this->createAuditEntry($campaign, $reason);
            $this->scheduleAutoResumeIfNeeded($campaign);

            $this->entityManager->commit();

            $this->logger->info('Campaign paused event processed', [
                'campaign_id' => $campaign->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process campaign paused event', [
                'campaign_id' => $campaign->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function pauseCampaign(Campaign $campaign, string $reason): void
    {
        $campaign->setStatus('paused');
        $campaign->setPauseReason($reason);
        $campaign->setPausedAt(new \DateTimeImmutable());

        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        $campaign->setPauseProgress($metrics?->getTotalRecipients() ?? 0);

        $this->entityManager->persist($campaign);

        $this->logger->debug('Paused campaign', [
            'campaign_id' => $campaign->getId(),
            'reason' => $reason,
        ]);
    }

    private function cancelScheduledJobs(Campaign $campaign): void
    {
        $pendingJobs = $this->entityManager
            ->getRepository(\App\Entity\ScheduledJob::class)
            ->findPendingByCampaign($campaign->getId());

        $cancelledCount = 0;
        foreach ($pendingJobs as $job) {
            if ($job->getScheduledFor() > new \DateTimeImmutable()) {
                $job->setStatus('cancelled');
                $job->setCancelledReason('campaign_paused');
                $job->setCancelledAt(new \DateTimeImmutable());

                $this->entityManager->persist($job);

                $this->queueService->publish('jobs.cancel', [
                    'job_id' => $job->getId(),
                    'campaign_id' => $campaign->getId(),
                ]);

                $cancelledCount++;
            }
        }

        $this->logger->debug('Cancelled scheduled jobs', [
            'campaign_id' => $campaign->getId(),
            'cancelled_count' => $cancelledCount,
        ]);
    }

    private function recordPauseMetrics(Campaign $campaign, string $reason): void
    {
        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        if ($metrics !== null) {
            $pauseRecord = new \App\Entity\CampaignPauseRecord();
            $pauseRecord->setCampaignId($campaign->getId());
            $pauseRecord->setReason($reason);
            $pauseRecord->setPausedAt(new \DateTimeImmutable());
            $pauseRecord->setProgressAtPause($metrics->getTotalRecipients());
            $pauseRecord->setDeliveredAtPause($metrics->getDeliveredCount());
            $pauseRecord->setOpenedAtPause($metrics->getOpenedCount());
            $pauseRecord->setClickedAtPause($metrics->getClickedCount());
            $pauseRecord->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($pauseRecord);
        }

        $this->logger->debug('Recorded pause metrics', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function notifyMarketingTeam(Campaign $campaign, string $reason): void
    {
        $marketers = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->findByRole('marketing');

        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        foreach ($marketers as $marketer) {
            $notification = new \App\Entity\MarketingNotification();
            $notification->setUser($marketer);
            $notification->setType('campaign_paused');
            $notification->setTitle('Campaign Paused: ' . $campaign->getName());
            $notification->setBody(sprintf(
                'Campaign "%s" has been paused. Reason: %s. Progress: %d/%d recipients.',
                $campaign->getName(),
                $this->getHumanReadableReason($reason),
                $metrics?->getTotalRecipients() ?? 0,
                $campaign->getPauseProgress()
            ));
            $notification->setReferenceType('campaign');
            $notification->setReferenceId($campaign->getId());
            $notification->setPriority('high');
            $notification->setStatus('unread');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);
        }

        $this->queueService->publish('notifications.marketing', [
            'campaign_id' => $campaign->getId(),
            'type' => 'campaign_paused',
            'reason' => $reason,
        ]);

        $this->logger->debug('Notified marketing team', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function freezeBudgetAllocation(Campaign $campaign): void
    {
        $spent = $this->calculateCampaignSpend($campaign);

        $campaign->setBudgetAllocated($campaign->getBudget());
        $campaign->setBudgetSpent($spent);
        $campaign->setBudgetFrozenAt(new \DateTimeImmutable());

        $this->entityManager->persist($campaign);

        $this->logger->debug('Frozen budget allocation', [
            'campaign_id' => $campaign->getId(),
            'spent' => $spent,
        ]);
    }

    private function pauseAudienceSegments(Campaign $campaign): void
    {
        $segments = $this->entityManager
            ->getRepository(\App\Entity\AudienceSegment::class)
            ->findByCampaign($campaign->getId());

        foreach ($segments as $segment) {
            $segment->setStatus('paused');
            $segment->setPausedAt(new \DateTimeImmutable());

            $this->entityManager->persist($segment);

            $this->queueService->publish('segment.pause', [
                'segment_id' => $segment->getId(),
                'campaign_id' => $campaign->getId(),
            ]);
        }

        $this->logger->debug('Paused audience segments', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function preserveCurrentProgress(Campaign $campaign): void
    {
        $currentMetrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        if ($currentMetrics !== null) {
            $snapshot = new \App\Entity\CampaignProgressSnapshot();
            $snapshot->setCampaignId($campaign->getId());
            $snapshot->setSnapshotType('pause');
            $snapshot->setTotalRecipients($currentMetrics->getTotalRecipients());
            $snapshot->setDeliveredCount($currentMetrics->getDeliveredCount());
            $snapshot->setOpenedCount($currentMetrics->getOpenedCount());
            $snapshot->setClickedCount($currentMetrics->getClickedCount());
            $snapshot->setConvertedCount($currentMetrics->getConvertedCount());
            $snapshot->setBouncedCount($currentMetrics->getBouncedCount());
            $snapshot->setCapturedAt(new \DateTimeImmutable());

            $this->entityManager->persist($snapshot);
        }

        $this->logger->debug('Preserved current progress', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function recordPauseAnalytics(Campaign $campaign, string $reason): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('campaign_paused');
        $analyticsEvent->setCustomerId(0);
        $analyticsEvent->setPayload([
            'campaign_id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'reason' => $reason,
            'pause_duration' => $campaign->getPausedAt()->diff($campaign->getStartedAt())->h,
            'current_progress' => $campaign->getPauseProgress(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded pause analytics', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function createAuditEntry(Campaign $campaign, string $reason): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CAMPAIGN_PAUSED');
        $auditEntry->setEntityType('campaign');
        $auditEntry->setEntityId($campaign->getId());
        $auditEntry->setUserId($campaign->getCreatedBy());
        $auditEntry->setMetadata([
            'name' => $campaign->getName(),
            'reason' => $reason,
            'paused_at' => $campaign->getPausedAt()->format(\DATE_ATOM),
            'progress_at_pause' => $campaign->getPauseProgress(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function scheduleAutoResumeIfNeeded(Campaign $campaign): void
    {
        if (!$this->pauseService->shouldAutoResume($campaign)) {
            return;
        }

        $resumeTime = $this->pauseService->calculateResumeTime($campaign);

        $autoResumeJob = new \App\Entity\ScheduledJob();
        $autoResumeJob->setType('campaign_auto_resume');
        $autoResumeJob->setCampaignId($campaign->getId());
        $autoResumeJob->setScheduledFor($resumeTime);
        $autoResumeJob->setStatus('pending');
        $autoResumeJob->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($autoResumeJob);

        $this->queueService->publish('jobs.schedule', [
            'job_id' => $autoResumeJob->getId(),
            'type' => 'campaign_auto_resume',
            'campaign_id' => $campaign->getId(),
            'scheduled_for' => $resumeTime->format(\DATE_ATOM),
        ]);

        $campaign->setAutoResumeScheduled(true);
        $campaign->setAutoResumeAt($resumeTime);
        $this->entityManager->persist($campaign);

        $this->logger->debug('Scheduled auto-resume', [
            'campaign_id' => $campaign->getId(),
            'resume_at' => $resumeTime->format(\DATE_ATOM),
        ]);
    }

    private function calculateCampaignSpend(Campaign $campaign): int
    {
        return 0;
    }

    private function getHumanReadableReason(string $reason): string
    {
        return match ($reason) {
            'budget_threshold' => 'budget threshold reached',
            'performance_issue' => 'performance issue detected',
            'manual' => 'manually paused',
            'technical_issue' => 'technical issue',
            default => 'unspecified reason',
        };
    }
}
