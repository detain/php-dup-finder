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

final readonly class LeadNurturingWorkflow
{
    public function __construct(
        private LeadRepositoryInterface $leadRepository,
        private LeadActivityRepositoryInterface $activityRepository,
        private EmailServiceInterface $emailService,
        private CrmServiceInterface $crmService,
        private ScoringServiceInterface $scoringService,
        private LoggerInterface $logger,
    ) {}

    public function processNewLead(string $leadId): void
    {
        $lead = $this->leadRepository->findById($leadId);
        if ($lead === null) {
            throw new \RuntimeException("Lead not found: {$leadId}");
        }

        $this->logger->info('Starting lead nurturing workflow', ['lead_id' => $leadId]);

        $this->captureLeadSource($lead);

        $this->scoreLead($lead);

        $this->assignToRep($lead);

        $this->enrollInCampaign($lead);

        $this->sendInitialEmail($lead);

        $this->recordActivity($lead, 'workflow_started');

        $this->updateLeadStatus($lead, 'nurturing');

        $this->logger->info('Lead nurturing workflow completed', ['lead_id' => $leadId]);
    }

    private function captureLeadSource(Lead $lead): void
    {
        $leadSource = $lead->getSource();
        $leadSourceData = $lead->getSourceData();

        $this->crmService->trackLeadSource($lead->getId()->toString(), [
            'source' => $leadSource,
            'medium' => $leadSourceData['medium'] ?? null,
            'campaign' => $leadSourceData['campaign'] ?? null,
            'referrer' => $leadSourceData['referrer'] ?? null,
            'landing_page' => $leadSourceData['landing_page'] ?? null,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $this->recordActivity($lead, 'source_captured', [
            'source' => $leadSource,
        ]);

        $this->logger->debug('Lead source captured', [
            'lead_id' => $lead->getId()->toString(),
            'source' => $leadSource,
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

    private function assignToRep(Lead $lead): void
    {
        $territory = $lead->getTerritory();
        $rep = $this->crmService->findAvailableRep($territory);

        if ($rep !== null) {
            $lead->setAssignedTo($rep->getId());
            $lead->setAssignedAt(new \DateTimeImmutable());
            $this->leadRepository->save($lead);

            $this->crmService->notifyRepOfAssignment($rep, $lead);

            $this->recordActivity($lead, 'rep_assigned', [
                'rep_id' => $rep->getId()->toString(),
            ]);

            $this->logger->debug('Lead assigned to rep', [
                'lead_id' => $lead->getId()->toString(),
                'rep_id' => $rep->getId()->toString(),
            ]);
        } else {
            $this->recordActivity($lead, 'rep_assignment_pending', [
                'reason' => 'no_available_rep',
            ]);
        }
    }

    private function enrollInCampaign(Lead $lead): void
    {
        $lifecycleStage = $lead->getLifecycleStage();
        $campaignId = $this->getCampaignForStage($lifecycleStage);

        if ($campaignId !== null) {
            $this->crmService->enrollInCampaign($lead->getId()->toString(), $campaignId);

            $this->recordActivity($lead, 'enrolled_in_campaign', [
                'campaign_id' => $campaignId,
                'lifecycle_stage' => $lifecycleStage,
            ]);

            $this->logger->debug('Lead enrolled in campaign', [
                'lead_id' => $lead->getId()->toString(),
                'campaign_id' => $campaignId,
            ]);
        }
    }

    private function sendInitialEmail(Lead $lead): void
    {
        $template = $this->getEmailTemplateForLead($lead);

        $this->emailService->sendTemplate(
            $lead->getEmail(),
            $template,
            [
                'lead_name' => $lead->getFirstName(),
                'company_name' => $lead->getCompany(),
                'unsubscribe_link' => $this->generateUnsubscribeLink($lead),
            ]
        );

        $this->recordActivity($lead, 'initial_email_sent', [
            'template' => $template,
        ]);

        $this->logger->debug('Initial email sent', [
            'lead_id' => $lead->getId()->toString(),
            'template' => $template,
        ]);
    }

    private function recordActivity(Lead $lead, string $activityType, array $data = []): void
    {
        $activity = new LeadActivity();
        $activity->setLeadId($lead->getId());
        $activity->setType($activityType);
        $activity->setData($data);
        $activity->setCreatedAt(new \DateTimeImmutable());

        $this->activityRepository->save($activity);

        $this->logger->debug('Activity recorded', [
            'lead_id' => $lead->getId()->toString(),
            'activity_type' => $activityType,
        ]);
    }

    private function updateLeadStatus(Lead $lead, string $status): void
    {
        $lead->setStatus($status);
        $lead->setUpdatedAt(new \DateTimeImmutable());
        $this->leadRepository->save($lead);
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

    private function getCampaignForStage(string $lifecycleStage): ?string
    {
        return match ($lifecycleStage) {
            'opportunity' => 'campaign_opportunity',
            'mql' => 'campaign_mql',
            'sql' => 'campaign_sql',
            'lead' => 'campaign_lead',
            default => null,
        };
    }

    private function getEmailTemplateForLead(Lead $lead): string
    {
        return match ($lead->getLifecycleStage()) {
            'opportunity' => 'email_opportunity_welcome',
            'mql' => 'email_mql_welcome',
            'sql' => 'email_sql_welcome',
            default => 'email_lead_welcome',
        };
    }

    private function generateUnsubscribeLink(Lead $lead): string
    {
        return "https://example.com/unsubscribe?token=" . bin2hex(random_bytes(16));
    }
}
