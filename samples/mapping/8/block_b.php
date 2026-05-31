<?php
declare(strict_types=1);

namespace App\Analytics\Historical\Mapper;

use App\Domain\Entity\AnalyticsEvent;
use App\Analytics\Historical\DTO\HistoricalEventDTO;
use App\Analytics\Historical\DTO\ReportEventDTO;

final readonly class AnalyticsHistoricalMapper
{
    public function toHistoricalDTO(AnalyticsEvent $event): HistoricalEventDTO
    {
        $dto = new HistoricalEventDTO();
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
        $dto->processedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $dto;
    }

    public function toReportDTO(AnalyticsEvent $event): ReportEventDTO
    {
        $dto = new ReportEventDTO();
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
        $dto->weekNumber = $event->getTimestamp()->format('W');
        $dto->monthYear = $event->getTimestamp()->format('Y-m');

        return $dto;
    }
}
