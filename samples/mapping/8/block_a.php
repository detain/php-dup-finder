<?php
declare(strict_types=1);

namespace App\Analytics\RealTime\Mapper;

use App\Domain\Entity\AnalyticsEvent;
use App\Analytics\RealTime\DTO\RealTimeEventDTO;
use App\Analytics\RealTime\DTO\DashboardEventDTO;
use App\Analytics\RealTime\DTO\AlertEventDTO;

final readonly class AnalyticsRealTimeMapper
{
    public function toRealTimeDTO(AnalyticsEvent $event): RealTimeEventDTO
    {
        $dto = new RealTimeEventDTO();
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

        return $dto;
    }

    public function toDashboardDTO(AnalyticsEvent $event): DashboardEventDTO
    {
        $dto = new DashboardEventDTO();
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
        $dto->hourlyBucket = $event->getTimestamp()->format('Y-m-d H:00');
        $dto->dailyBucket = $event->getTimestamp()->format('Y-m-d');

        return $dto;
    }

    public function toAlertDTO(AnalyticsEvent $event): AlertEventDTO
    {
        $dto = new AlertEventDTO();
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
        $dto->alertLevel = $this->calculateAlertLevel($event);
        $dto->alertMessage = $this->generateAlertMessage($event);

        return $dto;
    }

    private function calculateAlertLevel(AnalyticsEvent $event): string
    {
        $props = $event->getProperties();
        if (($props['error'] ?? false) === true) {
            return 'critical';
        }
        if (($props['warning'] ?? false) === true) {
            return 'warning';
        }
        return 'info';
    }

    private function generateAlertMessage(AnalyticsEvent $event): string
    {
        return sprintf(
            '%s event detected from %s',
            $event->getEventName(),
            $event->getCountry()
        );
    }
}
