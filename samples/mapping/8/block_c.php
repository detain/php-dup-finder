<?php
declare(strict_types=1);

namespace App\Analytics\Export\Mapper;

use App\Domain\Entity\AnalyticsEvent;
use App\Analytics\Export\DTO\ExportEventDTO;

final readonly class AnalyticsExportMapper
{
    public function toExportDTO(AnalyticsEvent $event): ExportEventDTO
    {
        $dto = new ExportEventDTO();
        $dto->id = $event->getId()->toString();
        $dto->eventType = $event->getEventType();
        $dto->eventName = $event->getEventName();
        $dto->category = $event->getCategory();
        $dto->source = $event->getSource();
        $dto->userId = $event->getUserId()?->toString();
        $dto->sessionId = $event->getSessionId();
        $dto->pageUrl = $event->getPageUrl();
        $dto->pageTitle = $event->getPageTitle();
        $dto->referrerUrl = $event->getReferrerUrl();
        $dto->ipAddress = $event->getIpAddress();
        $dto->userAgent = $event->getUserAgent();
        $dto->country = $event->getCountry();
        $dto->city = $event->getCity();
        $dto->deviceType = $event->getDeviceType();
        $dto->browserType = $event->getBrowserType();
        $dto->operatingSystem = $event->getOperatingSystem();
        $dto->screenResolution = $event->getScreenResolution();
        $dto->timestamp = $event->getTimestamp()->format(\DateTimeInterface::ATOM);
        $dto->properties = $event->getProperties();
        $dto->exportedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $dto->exportBatchId = $this->generateBatchId();

        return $dto;
    }

    private function generateBatchId(): string
    {
        return 'EXP-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }
}
