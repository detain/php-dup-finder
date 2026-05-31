<?php

declare(strict_types=1);

namespace App\Domain\Marketing;

use App\Infrastructure\EventDispatcher\EventDispatcherInterface;

/**
 * Marketing campaign management service.
 * The EventDispatcherInterface is manually injected here, duplicated from
 * BillingService, ShippingService, and other services.
 */
class CampaignService
{
    private EventDispatcherInterface $eventDispatcher;
    private CampaignRepositoryInterface $campaignRepository;
    private EmailServiceInterface $emailService;
    private AudienceServiceInterface $audienceService;

    public function __construct(
        CampaignRepositoryInterface $campaignRepository,
        EmailServiceInterface $emailService,
        AudienceServiceInterface $audienceService,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->campaignRepository = $campaignRepository;
        $this->emailService = $emailService;
        $this->audienceService = $audienceService;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function createCampaign(array $campaignData): Campaign
    {
        $campaign = Campaign::create(
            name: $campaignData['name'],
            type: $campaignData['type'],
            scheduledAt: $campaignData['scheduled_at'] ?? null,
            segmentId: $campaignData['segment_id'] ?? null,
        );

        $savedCampaign = $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignCreatedEvent($savedCampaign));

        return $savedCampaign;
    }

    public function scheduleCampaign(string $campaignId, \DateTimeImmutable $scheduledAt): void
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new CampaignNotFoundException("Campaign not found: {$campaignId}");
        }

        $campaign->schedule($scheduledAt);
        $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignScheduledEvent($campaign));
    }

    public function launchCampaign(string $campaignId): LaunchResult
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new CampaignNotFoundException("Campaign not found: {$campaignId}");
        }

        if (!$campaign->canLaunch()) {
            throw new CampaignNotLaunchableException(
                "Campaign cannot be launched in its current state"
            );
        }

        $audience = $this->audienceService->buildAudience($campaign->getSegmentId());

        $campaign->markAsLaunching();
        $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignLaunchingEvent($campaign));

        $recipientCount = $this->emailService->queueCampaign(
            campaignId: $campaign->getId(),
            template: $campaign->getTemplate(),
            recipients: $audience,
        );

        $campaign->markAsLaunched($recipientCount);
        $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignLaunchedEvent($campaign, $recipientCount));

        return new LaunchResult(
            campaignId: $campaign->getId(),
            recipientCount: $recipientCount,
            launchedAt: $campaign->getLaunchedAt(),
        );
    }

    public function pauseCampaign(string $campaignId): void
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new CampaignNotFoundException("Campaign not found: {$campaignId}");
        }

        $campaign->pause();
        $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignPausedEvent($campaign));
    }

    public function resumeCampaign(string $campaignId): void
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new CampaignNotFoundException("Campaign not found: {$campaignId}");
        }

        $campaign->resume();
        $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignResumedEvent($campaign));
    }

    public function completeCampaign(string $campaignId): CampaignStatistics
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new CampaignNotFoundException("Campaign not found: {$campaignId}");
        }

        $stats = $this->calculateStatistics($campaign);

        $campaign->markAsCompleted($stats);
        $this->campaignRepository->save($campaign);

        $this->eventDispatcher->dispatch(new CampaignCompletedEvent($campaign, $stats));

        return $stats;
    }

    private function calculateStatistics(Campaign $campaign): CampaignStatistics
    {
        return new CampaignStatistics(
            sent: $campaign->getRecipientCount(),
            delivered: $this->emailService->getDeliveryCount($campaign->getId()),
            opened: $this->emailService->getOpenCount($campaign->getId()),
            clicked: $this->emailService->getClickCount($campaign->getId()),
            converted: $this->emailService->getConversionCount($campaign->getId()),
            bounced: $this->emailService->getBounceCount($campaign->getId()),
            unsubscribed: $this->emailService->getUnsubscribeCount($campaign->getId()),
        );
    }
}
