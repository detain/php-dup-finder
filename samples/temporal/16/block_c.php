<?php
declare(strict_types=1);

namespace SendGrid\Mail\Service;

use SendGrid\Mail\Repository\CampaignRepository;
use SendGrid\Mail\Repository\ContactRepository;
use SendGrid\Mail\Repository\TemplateRepository;
use SendGrid\Mail\Entity\Campaign;
use SendGrid\Mail\Entity\Contact;
use SendGrid\Mail\Entity\EmailMessage;
use SendGrid\Mail\Entity\MessageVariant;
use SendGrid\Mail\Exception\CampaignException;
use SendGrid\Mail\Service\Validation\ContentValidator;
use SendGrid\Mail\Service\Throttling\SendThrottler;
use Psr\Log\LoggerInterface;

final class CampaignDispatchService
{
    private CampaignRepository $campaignRepo;
    private ContactRepository $contactRepo;
    private TemplateRepository $templateRepo;
    private ContentValidator $contentValidator;
    private SendThrottler $throttler;
    private LoggerInterface $logger;

    public function __construct(
        CampaignRepository $campaignRepo,
        ContactRepository $contactRepo,
        TemplateRepository $templateRepo,
        ContentValidator $contentValidator,
        SendThrottler $throttler,
        LoggerInterface $logger
    ) {
        $this->campaignRepo = $campaignRepo;
        $this->contactRepo = $contactRepo;
        $this->templateRepo = $templateRepo;
        $this->contentValidator = $contentValidator;
        $this->throttler = $throttler;
        $this->logger = $logger;
    }

    public function scheduleCampaign(string $campaignId, \DateTimeImmutable $sendAt): ScheduleResult
    {
        $this->logger->info('Scheduling campaign', [
            'campaign_id' => $campaignId,
            'send_at' => $sendAt->format('c')
        ]);

        $campaign = $this->campaignRepo->findById($campaignId);
        if ($campaign === null) {
            throw new CampaignException("Campaign not found: {$campaignId}");
        }

        if ($campaign->getStatus() !== 'draft') {
            throw new CampaignException("Campaign cannot be scheduled in status: {$campaign->getStatus()}");
        }

        $template = $this->templateRepo->findById($campaign->getTemplateId());
        if ($template === null) {
            throw new CampaignException("Template not found: {$campaign->getTemplateId()}");
        }

        $validationResult = $this->contentValidator->validateCampaign($campaign, $template);
        if (!$validationResult->isValid()) {
            throw new CampaignException("Campaign validation failed: " . implode(', ', $validationResult->getErrors()));
        }

        $recipientsCount = $this->contactRepo->countRecipientsForSegment(
            $campaign->getSegmentId()
        );

        if ($recipientsCount === 0) {
            throw new CampaignException('Campaign has no recipients');
        }

        $sendLock = $this->campaignRepo->acquireSendLock($campaignId);
        if ($sendLock === null) {
            throw new CampaignException('Could not acquire send lock for campaign');
        }

        $this->logger->debug('Send lock acquired', ['campaign_id' => $campaignId]);

        try {
            $this->campaignRepo->updateStatus($campaignId, 'scheduled', [
                'send_at' => $sendAt,
                'recipients_count' => $recipientsCount,
                'scheduled_at' => new \DateTimeImmutable()
            ]);

            $estimatedDuration = $this->throttler->estimateSendDuration($recipientsCount);
            $estimatedCompletion = (clone $sendAt)->modify("+{$estimatedDuration} seconds");

            $this->campaignRepo->updateMetadata($campaignId, [
                'estimated_duration_seconds' => $estimatedDuration,
                'estimated_completion_at' => $estimatedCompletion
            ]);

            $this->campaignRepo->releaseSendLock($sendLock);

            $this->logger->info('Campaign scheduled successfully', [
                'campaign_id' => $campaignId,
                'send_at' => $sendAt->format('c'),
                'recipients_count' => $recipientsCount,
                'estimated_completion' => $estimatedCompletion->format('c')
            ]);

            return new ScheduleResult([
                'success' => true,
                'campaign_id' => $campaignId,
                'send_at' => $sendAt->format('c'),
                'recipients_count' => $recipientsCount,
                'estimated_completion_at' => $estimatedCompletion->format('c')
            ]);

        } catch (\Throwable $e) {
            $this->campaignRepo->releaseSendLock($sendLock);
            $this->logger->error('Campaign scheduling failed', [
                'campaign_id' => $campaignId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function sendNow(string $campaignId): SendResult
    {
        return $this->scheduleCampaign($campaignId, new \DateTimeImmutable());
    }

    public function cancelCampaign(string $campaignId): CancelResult
    {
        $campaign = $this->campaignRepo->findById($campaignId);
        if ($campaign === null) {
            throw new CampaignException("Campaign not found: {$campaignId}");
        }

        $cancellableStatuses = ['draft', 'scheduled'];
        if (!in_array($campaign->getStatus(), $cancellableStatuses, true)) {
            throw new CampaignException("Campaign cannot be cancelled in status: {$campaign->getStatus()}");
        }

        if ($campaign->getStatus() === 'scheduled') {
            $this->campaignRepo->updateStatus($campaignId, 'cancelled', [
                'cancelled_at' => new \DateTimeImmutable()
            ]);

            $this->logger->info('Scheduled campaign cancelled', ['campaign_id' => $campaignId]);
        } else {
            $this->campaignRepo->updateStatus($campaignId, 'cancelled');

            $this->logger->info('Draft campaign cancelled', ['campaign_id' => $campaignId]);
        }

        return new CancelResult([
            'success' => true,
            'campaign_id' => $campaignId,
            'cancelled_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    public function pauseCampaign(string $campaignId): PauseResult
    {
        $campaign = $this->campaignRepo->findById($campaignId);
        if ($campaign === null) {
            throw new CampaignException("Campaign not found: {$campaignId}");
        }

        if ($campaign->getStatus() !== 'sending') {
            throw new CampaignException("Campaign cannot be paused in status: {$campaign->getStatus()}");
        }

        $this->campaignRepo->updateStatus($campaignId, 'paused', [
            'paused_at' => new \DateTimeImmutable()
        ]);

        $this->logger->info('Campaign paused', ['campaign_id' => $campaignId]);

        return new PauseResult([
            'success' => true,
            'campaign_id' => $campaignId,
            'paused_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }
}
