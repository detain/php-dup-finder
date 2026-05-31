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

final readonly class LeadConversionWorkflow
{
    public function __construct(
        private LeadRepositoryInterface $leadRepository,
        private LeadActivityRepositoryInterface $activityRepository,
        private EmailServiceInterface $emailService,
        private CrmServiceInterface $crmService,
        private ScoringServiceInterface $scoringService,
        private LoggerInterface $logger,
    ) {}

    public function convertLead(string $leadId): void
    {
        $lead = $this->leadRepository->findById($leadId);
        if ($lead === null) {
            throw new \RuntimeException("Lead not found: {$leadId}");
        }

        $this->logger->info('Starting lead conversion workflow', ['lead_id' => $leadId]);

        $this->validateConversionEligibility($lead);

        $this->gatherConversionData($lead);

        $this->scoreLead($lead);

        $this->createCustomerRecord($lead);

        $this->sendWelcomeEmail($lead);

        $this->enrollInCustomerJourney($lead);

        $this->notifySalesTeam($lead);

        $this->updateLeadStatus($lead, 'converted');

        $this->recordActivity($lead, 'lead_converted');

        $this->logger->info('Lead conversion workflow completed', ['lead_id' => $leadId]);
    }

    private function validateConversionEligibility(Lead $lead): void
    {
        if ($lead->getStatus() === 'converted') {
            throw new \RuntimeException("Lead {$lead->getId()} is already converted");
        }

        if ($lead->getScore() < 50) {
            throw new \RuntimeException("Lead {$lead->getId()} does not meet minimum score threshold");
        }

        if (trim($lead->getEmail()) === '') {
            throw new \RuntimeException("Lead {$lead->getId()} has no email address");
        }

        if (trim($lead->getFirstName()) === '' || trim($lead->getLastName()) === '') {
            throw new \RuntimeException("Lead {$lead->getId()} has incomplete name");
        }

        $this->recordActivity($lead, 'conversion_eligibility_validated');
        $this->logger->debug('Lead conversion eligibility validated', ['lead_id' => $lead->getId()->toString()]);
    }

    private function gatherConversionData(Lead $lead): void
    {
        $conversionData = [
            'email' => $lead->getEmail(),
            'first_name' => $lead->getFirstName(),
            'last_name' => $lead->getLastName(),
            'company' => $lead->getCompany(),
            'phone' => $lead->getPhone(),
            'lead_source' => $lead->getSource(),
            'lead_score' => $lead->getScore(),
            'lifecycle_stage' => $lead->getLifecycleStage(),
            'converted_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $lead->setConversionData($conversionData);
        $this->leadRepository->save($lead);

        $this->recordActivity($lead, 'conversion_data_gathered', $conversionData);
        $this->logger->debug('Conversion data gathered', ['lead_id' => $lead->getId()->toString()]);
    }

    private function scoreLead(Lead $lead): void
    {
        $demographicScore = $this->scoringService->calculateDemographicScore($lead);
        $behavioralScore = $this->scoringService->calculateBehavioralScore($lead);
        $engagementScore = $this->scoringService->calculateEngagementScore($lead);
        $conversionBonus = 20;

        $totalScore = $demographicScore + $behavioralScore + $engagementScore + $conversionBonus;
        $lead->setScore($totalScore);
        $lead->setScoreLastUpdated(new \DateTimeImmutable());

        $this->leadRepository->save($lead);

        $this->recordActivity($lead, 'lead_scored', [
            'conversion_bonus' => $conversionBonus,
            'total_score' => $totalScore,
        ]);

        $this->logger->debug('Lead scored with conversion bonus', [
            'lead_id' => $lead->getId()->toString(),
            'score' => $totalScore,
        ]);
    }

    private function createCustomerRecord(Lead $lead): void
    {
        $customerData = [
            'email' => $lead->getEmail(),
            'first_name' => $lead->getFirstName(),
            'last_name' => $lead->getLastName(),
            'company' => $lead->getCompany(),
            'phone' => $lead->getPhone(),
            'lead_id' => $lead->getId()->toString(),
            'converted_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $customerId = $this->crmService->createCustomer($customerData);
        $lead->setCustomerId($customerId);
        $this->leadRepository->save($lead);

        $this->recordActivity($lead, 'customer_record_created', [
            'customer_id' => $customerId,
        ]);

        $this->logger->debug('Customer record created', [
            'lead_id' => $lead->getId()->toString(),
            'customer_id' => $customerId,
        ]);
    }

    private function sendWelcomeEmail(Lead $lead): void
    {
        $this->emailService->sendTemplate(
            $lead->getEmail(),
            'email_customer_welcome',
            [
                'customer_name' => $lead->getFirstName(),
                'company_name' => $lead->getCompany(),
                'customer portal_link' => 'https://example.com/customers',
            ]
        );

        $this->recordActivity($lead, 'welcome_email_sent');
        $this->logger->debug('Welcome email sent', ['lead_id' => $lead->getId()->toString()]);
    }

    private function enrollInCustomerJourney(Lead $lead): void
    {
        $journeyId = $this->crmService->enrollInCustomerJourney($lead->getCustomerId());

        $this->recordActivity($lead, 'enrolled_in_customer_journey', [
            'journey_id' => $journeyId,
        ]);

        $this->logger->debug('Lead enrolled in customer journey', [
            'lead_id' => $lead->getId()->toString(),
            'journey_id' => $journeyId,
        ]);
    }

    private function notifySalesTeam(Lead $lead): void
    {
        $assignedRep = $lead->getAssignedTo();
        if ($assignedRep !== null) {
            $this->crmService->notifyRepOfConversion($assignedRep, $lead);

            $this->recordActivity($lead, 'sales_team_notified', [
                'rep_id' => $assignedRep,
            ]);
        }

        $this->logger->debug('Sales team notified', ['lead_id' => $lead->getId()->toString()]);
    }

    private function updateLeadStatus(Lead $lead, string $status): void
    {
        $lead->setStatus($status);
        $lead->setConvertedAt(new \DateTimeImmutable());
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
}
