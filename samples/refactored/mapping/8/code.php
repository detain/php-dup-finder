<?php
declare(strict_types=1);

namespace App\Core\Analytics\Mapper;

use App\Domain\Entity\AnalyticsEvent;
use App\Core\DTO\DTOInterface;

interface AnalyticsMappingStrategy
{
    public function getExtraFields(): array;
    public function includeGeoData(): bool;
    public function includeDeviceData(): bool;
}

abstract class BaseAnalyticsMapper
{
    public function map(AnalyticsEvent $event, DTOInterface $dto, ?AnalyticsMappingStrategy $strategy = null): DTOInterface
    {
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
        $dto->timestamp = $event->getTimestamp()->format(\DateTimeInterface::ATOM);
        $dto->properties = $event->getProperties();

        if ($strategy === null || $strategy->includeGeoData()) {
            $dto->ipAddress = $event->getIpAddress();
            $dto->country = $event->getCountry();
            $dto->city = $event->getCity();
        }

        if ($strategy === null || $strategy->includeDeviceData()) {
            $dto->deviceType = $event->getDeviceType();
            $dto->browserType = $event->getBrowserType();
            $dto->operatingSystem = $event->getOperatingSystem();
            $dto->screenResolution = $event->getScreenResolution();
            $dto->userAgent = $event->getUserAgent();
        }

        if ($strategy !== null) {
            foreach ($strategy->getExtraFields() as $field => $value) {
                $dto->{$field} = $value;
            }
        }

        return $dto;
    }
}

final class RealTimeAnalyticsMapper extends BaseAnalyticsMapper {}
final class HistoricalAnalyticsMapper extends BaseAnalyticsMapper {}
final class ExportAnalyticsMapper extends BaseAnalyticsMapper {}
