<?php
declare(strict_types=1);

namespace App\Crm\LeadNurturing;

use App\Domain\Entity\Lead;
use App\Domain\Entity\LeadActivity;
use App\Domain\Repository\LeadRepositoryInterface;
use App\Domain\Repository\LeadActivityRepositoryInterface;
use App\Domain\Service\EmailServiceInterface;
use App\Domain\Service\CrmServiceInterface;
use App\Domain\Service\ScoringServiceInterface;
use Psr\Log\LoggerInterface;

final readonly class LeadReengagementWorkflow
{
    public function __construct(
        private LeadRepositoryInterface $leadRepository,
        private LeadActivityRepositoryInterface $activityRepository,
        private EmailServiceInterface $emailService,
        private CrmServiceInterface $crmService,
        private ScoringServiceInterface $scoringService,
        private LoggerInterface $logger,
    ) {}

    public function reengageLead(string $leadId): void
    {
        $lead = $this->leadRepository->findById($leadId);
        if ($lead === null) {
            throw new \RuntimeException("Lead not found: {$leadId}");
        }

        $this->logger->info('Starting lead reengagement workflow', ['lead_id' => $leadId]);

        $this->analyzeEngagementHistory($lead);

        $this->scoreLead($lead);

        $this->sendReengagementEmail($lead);

        $this->updateLeadStatus($lead, 'reengagement_sent');

        $this->recordActivity($lead, 'reengagement_email_sent');

        $this->scheduleFollowUp($lead);

        $this->logger->info('Lead reengagement workflow completed', ['lead_id' => $leadId]);
    }

    private function analyzeEngagementHistory(Lead $lead): void
    {
        $lastActivity = $this->activityRepository->findLastActivity($lead->getId());
        $daysSinceLastActivity = $lastActivity !== null
            ? (new \DateTimeImmutable())->diff($lastActivity->getCreatedAt())->days
            : 999;

        $emailOpens = $this->countEmailOpens($lead->getId());
        $emailClicks = $this->countEmailClicks($lead->getId());
        $pageViews = $this->countPageViews($lead->getId());

        $lead->setDaysSinceLastActivity($daysSinceLastActivity);
        $lead->setEngagementMetrics([
            'email_opens' => $emailOpens,
            'email_clicks' => $emailClicks,
            'page_views' => $pageViews,
        ]);

        $this->recordActivity($lead, 'engagement_analyzed', [
            'days_since_last_activity' => $daysSinceLastActivity,
            'total_engagements' => $emailOpens + $emailClicks + $pageViews,
        ]);

        $this->logger->debug('Lead engagement history analyzed', [
            'lead_id' => $lead->getId()->toString(),
            'days_since' => $daysSinceLastActivity,
        ]);
    }

    private function scoreLead(Lead $lead): void
    {
        $currentScore = $lead->getScore();
        $demographicScore = $this->scoringService->calculateDemographicScore($lead);
        $behavioralScore = $this->scoringService->calculateBehavioralScore($lead);
        $engagementScore = $this->scoringService->calculateEngagementScore($lead);

        $totalScore = $demographicScore + $behavioralScore + $engagementScore;
        $lead->setScore($totalScore);

        if ($totalScore > $currentScore) {
            $lead->setScoreLastUpdated(new \DateTimeImmutable());
        }

        $lead->setLifecycleStage($this->determineLifecycleStage($totalScore));

        $this->leadRepository->save($lead);

        $this->recordActivity($lead, 'lead_scored', [
            'demographic_score' => $demographicScore,
            'behavioral_score' => $behavioralScore,
            'engagement_score' => $engagementScore,
            'total_score' => $totalScore,
        ]);

        $this->logger->debug('Lead scored', [
            'lead_id' => $lead->getId()->toString(),
            'score' => $totalScore,
        ]);
    }

    private function sendReengagementEmail(Lead $lead): void
    {
        $template = $this->getReengagementTemplate($lead);

        $this->emailService->sendTemplate(
            $lead->getEmail(),
            $template,
            [
                'lead_name' => $lead->getFirstName(),
                'company_name' => $lead->getCompany(),
                'last_activity_date' => $lead->getDaysSinceLastActivity(),
                'unsubscribe_link' => $this->generateUnsubscribeLink($lead),
            ]
        );

        $this->recordActivity($lead, 'reengagement_email_sent', [
            'template' => $template,
        ]);

        $this->logger->debug('Reengagement email sent', [
            'lead_id' => $lead->getId()->toString(),
            'template' => $template,
        ]);
    }

    private function updateLeadStatus(Lead $lead, string $status): void
    {
        $lead->setStatus($status);
        $lead->setUpdatedAt(new \DateTimeImmutable());
        $this->leadRepository->save($lead);
    }

    private function recordActivity(Lead $lead, string $activityType, array $data = []): void
    {
        $activity = new LeadActivity();
        $activity->setLeadId($lead->getId());
        $activity->setType($activityType);
        $activity->setData($data);
        $activity->setCreatedAt(new \DateTimeImmutable());

        $this->activityRepository->save($activity);
    }

    private function scheduleFollowUp(Lead $lead): void
    {
        $this->crmService->scheduleFollowUp($lead->getId()->toString(), [
            'scheduled_for' => (new \DateTimeImmutable('+3 days'))->format(\DateTimeInterface::ATOM),
            'reason' => 'reengagement_sent',
        ]);

        $this->recordActivity($lead, 'follow_up_scheduled', [
            'scheduled_for' => '+3 days',
        ]);

        $this->logger->debug('Follow-up scheduled', ['lead_id' => $lead->getId()->toString()]);
    }

    private function determineLifecycleStage(int $score): string
    {
        if ($score >= 80) {
            return 'opportunity';
        }
        if ($score >= 50) {
            return 'mql';
        }
        if ($score >= 20) {
            return 'sql';
        }
        return 'lead';
    }

    private function getReengagementTemplate(Lead $lead): string
    {
        $days = $lead->getDaysSinceLastActivity();
        if ($days > 90) {
            return 'email_reengagement_long_inactive';
        }
        if ($days > 30) {
            return 'email_reengagement_inactive';
        }
        return 'email_reengagement_recent';
    }

    private function generateUnsubscribeLink(Lead $lead): string
    {
        return "https://example.com/unsubscribe?token=" . bin2hex(random_bytes(16));
    }

    private function countEmailOpens(string $leadId): int
    {
        return $this->activityRepository->countByType($leadId, 'email_opened');
    }

    private function countEmailClicks(string $leadId): int
    {
        return $this->activityRepository->countByType($leadId, 'email_clicked');
    }

    private function countPageViews(string $leadId): int
    {
        return $this->activityRepository->countByType($leadId, 'page_viewed');
    }
}
