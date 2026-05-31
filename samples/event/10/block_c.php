<?php
declare(strict_types=1);

namespace App\Marketing\Handlers;

use App\Entity\Campaign;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\FinalizationService;
use App\Service\NotificationService;
use App\Service\ReportGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CampaignEndedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly FinalizationService $finalizationService,
        private readonly NotificationService $notificationService,
        private readonly ReportGenerationService $reportGenerationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Campaign $campaign, string $reason): void
    {
        $this->logger->info('Processing campaign ended event', [
            'campaign_id' => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
            'reason' => $reason,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->finalizeCampaign($campaign, $reason);
            $this->compileFinalMetrics($campaign);
            $this->generateFinalReport($campaign);
            $this->sendFinalNotifications($campaign);
            $this->deactivateSegments($campaign);
            $this->cleanupPendingJobs($campaign);
            $this->archiveCampaignData($campaign);
            $this->calculateRoiMetrics($campaign);
            $this->recordEndAnalytics($campaign, $reason);
            $this->createAuditEntry($campaign, $reason);

            $this->entityManager->commit();

            $this->logger->info('Campaign ended event processed', [
                'campaign_id' => $campaign->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process campaign ended event', [
                'campaign_id' => $campaign->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function finalizeCampaign(Campaign $campaign, string $reason): void
    {
        $campaign->setStatus('ended');
        $campaign->setEndedAt(new \DateTimeImmutable());
        $campaign->setEndReason($reason);

        $duration = $campaign->getEndedAt()->diff($campaign->getStartedAt());
        $campaign->setTotalDurationDays($duration->days);

        $this->entityManager->persist($campaign);

        $this->logger->debug('Finalized campaign', [
            'campaign_id' => $campaign->getId(),
            'reason' => $reason,
        ]);
    }

    private function compileFinalMetrics(Campaign $campaign): void
    {
        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        if ($metrics === null) {
            return;
        }

        $metrics->setCampaignEndedAt(new \DateTimeImmutable());

        $metrics->setOpenRate(
            $metrics->getDeliveredCount() > 0
                ? ($metrics->getOpenedCount() / $metrics->getDeliveredCount()) * 100
                : 0
        );

        $metrics->setClickRate(
            $metrics->getDeliveredCount() > 0
                ? ($metrics->getClickedCount() / $metrics->getDeliveredCount()) * 100
                : 0
        );

        $metrics->setConversionRate(
            $metrics->getClickedCount() > 0
                ? ($metrics->getConvertedCount() / $metrics->getClickedCount()) * 100
                : 0
        );

        $metrics->setBounceRate(
            $metrics->getTotalRecipients() > 0
                ? ($metrics->getBouncedCount() / $metrics->getTotalRecipients()) * 100
                : 0
        );

        $metrics->setFinalized(true);
        $metrics->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($metrics);

        $this->logger->debug('Compiled final metrics', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function generateFinalReport(Campaign $campaign): void
    {
        $report = new \App\Entity\CampaignReport();
        $report->setCampaignId($campaign->getId());
        $report->setReportType('final');
        $report->setStatus('generating');
        $report->setRequestedAt(new \DateTimeImmutable());

        $this->entityManager->persist($report);

        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        $reportData = $this->reportGenerationService->compileReportData($campaign, $metrics);

        $reportPath = $this->reportGenerationService->generatePdfReport(
            'campaign_final_report',
            $reportData
        );

        $report->setReportPath($reportPath);
        $report->setStatus('completed');
        $report->setCompletedAt(new \DateTimeImmutable());

        $this->entityManager->persist($report);

        $this->queueService->publish('report.campaign_final', [
            'report_id' => $report->getId(),
            'campaign_id' => $campaign->getId(),
            'report_path' => $reportPath,
        ]);

        $this->logger->debug('Generated final report', [
            'campaign_id' => $campaign->getId(),
            'report_id' => $report->getId(),
        ]);
    }

    private function sendFinalNotifications(Campaign $campaign): void
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
            $notification->setType('campaign_ended');
            $notification->setTitle('Campaign Ended: ' . $campaign->getName());
            $notification->setBody($this->generateEndNotificationBody($campaign, $metrics));
            $notification->setReferenceType('campaign');
            $notification->setReferenceId($campaign->getId());
            $notification->setStatus('unread');
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);
        }

        $this->queueService->publish('notifications.marketing', [
            'campaign_id' => $campaign->getId(),
            'type' => 'campaign_ended',
            'marketer_count' => count($marketers),
        ]);

        $this->logger->debug('Sent final notifications', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function deactivateSegments(Campaign $campaign): void
    {
        $segments = $this->entityManager
            ->getRepository(\App\Entity\AudienceSegment::class)
            ->findByCampaign($campaign->getId());

        foreach ($segments as $segment) {
            $segment->setStatus('completed');
            $segment->setCompletedAt(new \DateTimeImmutable());

            $this->entityManager->persist($segment);

            $this->queueService->publish('segment.complete', [
                'segment_id' => $segment->getId(),
                'campaign_id' => $campaign->getId(),
            ]);
        }

        $this->logger->debug('Deactivated segments', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function cleanupPendingJobs(Campaign $campaign): void
    {
        $pendingJobs = $this->entityManager
            ->getRepository(\App\Entity\ScheduledJob::class)
            ->findPendingByCampaign($campaign->getId());

        foreach ($pendingJobs as $job) {
            $job->setStatus('cancelled');
            $job->setCancelledReason('campaign_ended');
            $job->setCancelledAt(new \DateTimeImmutable());

            $this->entityManager->persist($job);
        }

        $this->logger->debug('Cleaned up pending jobs', [
            'campaign_id' => $campaign->getId(),
            'cancelled_count' => count($pendingJobs),
        ]);
    }

    private function archiveCampaignData(Campaign $campaign): void
    {
        $archive = new \App\Entity\CampaignArchive();
        $archive->setCampaignId($campaign->getId());
        $archive->setName($campaign->getName());
        $archive->setType($campaign->getType());
        $archive->setStatus('archived');
        $archive->setStartDate($campaign->getStartAt());
        $archive->setEndDate($campaign->getEndedAt());
        $archive->setArchivedAt(new \DateTimeImmutable());

        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        $archiveData = [
            'total_recipients' => $metrics?->getTotalRecipients() ?? 0,
            'delivered' => $metrics?->getDeliveredCount() ?? 0,
            'opened' => $metrics?->getOpenedCount() ?? 0,
            'clicked' => $metrics?->getClickedCount() ?? 0,
            'converted' => $metrics?->getConvertedCount() ?? 0,
            'bounced' => $metrics?->getBouncedCount() ?? 0,
            'revenue' => $metrics?->getRevenueGenerated() ?? 0,
        ];

        $archive->setMetricsData(json_encode($archiveData));
        $this->entityManager->persist($archive);

        $this->queueService->publish('data.archive.campaign', [
            'archive_id' => $archive->getId(),
            'campaign_id' => $campaign->getId(),
        ]);

        $this->logger->debug('Archived campaign data', [
            'campaign_id' => $campaign->getId(),
            'archive_id' => $archive->getId(),
        ]);
    }

    private function calculateRoiMetrics(Campaign $campaign): void
    {
        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        if ($metrics === null) {
            return;
        }

        $revenue = $metrics->getRevenueGenerated();
        $cost = $campaign->getBudgetSpent();

        $roi = $cost > 0 ? (($revenue - $cost) / $cost) * 100 : 0;

        $campaign->setFinalRoi($roi);
        $campaign->setFinalRevenue($revenue);
        $campaign->setFinalCost($cost);

        $this->entityManager->persist($campaign);

        $this->logger->debug('Calculated ROI metrics', [
            'campaign_id' => $campaign->getId(),
            'roi' => $roi,
            'revenue' => $revenue,
            'cost' => $cost,
        ]);
    }

    private function recordEndAnalytics(Campaign $campaign, string $reason): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('campaign_ended');
        $analyticsEvent->setCustomerId(0);
        $analyticsEvent->setPayload([
            'campaign_id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'reason' => $reason,
            'duration_days' => $campaign->getTotalDurationDays(),
            'roi' => $campaign->getFinalRoi(),
            'total_recipients' => $this->getFinalRecipientCount($campaign),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded end analytics', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function createAuditEntry(Campaign $campaign, string $reason): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('CAMPAIGN_ENDED');
        $auditEntry->setEntityType('campaign');
        $auditEntry->setEntityId($campaign->getId());
        $auditEntry->setUserId($campaign->getCreatedBy());
        $auditEntry->setMetadata([
            'name' => $campaign->getName(),
            'reason' => $reason,
            'ended_at' => $campaign->getEndedAt()->format(\DATE_ATOM),
            'duration_days' => $campaign->getTotalDurationDays(),
            'roi' => $campaign->getFinalRoi(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'campaign_id' => $campaign->getId(),
        ]);
    }

    private function generateEndNotificationBody(Campaign $campaign, ?\App\Entity\CampaignMetrics $metrics): string
    {
        $openRate = $metrics && $metrics->getDeliveredCount() > 0
            ? round(($metrics->getOpenedCount() / $metrics->getDeliveredCount()) * 100, 1)
            : 0;

        $clickRate = $metrics && $metrics->getDeliveredCount() > 0
            ? round(($metrics->getClickedCount() / $metrics->getDeliveredCount()) * 100, 1)
            : 0;

        return sprintf(
            'Campaign "%s" has ended. Results: %d delivered, %.1f%% open rate, %.1f%% click rate, $%.2f revenue.',
            $campaign->getName(),
            $metrics?->getDeliveredCount() ?? 0,
            $openRate,
            $clickRate,
            ($metrics?->getRevenueGenerated() ?? 0) / 100
        );
    }

    private function getFinalRecipientCount(Campaign $campaign): int
    {
        $metrics = $this->entityManager
            ->getRepository(\App\Entity\CampaignMetrics::class)
            ->findOneBy(['campaignId' => $campaign->getId()]);

        return $metrics?->getTotalRecipients() ?? 0;
    }
}
