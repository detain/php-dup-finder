<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\DomainEventInterface;
use App\Service\NotificationServiceInterface;
use App\Service\AnalyticsServiceInterface;
use App\Service\LoggingServiceInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractEventSubscriber
{
    public function __construct(
        protected readonly NotificationServiceInterface $notificationService,
        protected readonly AnalyticsServiceInterface $analyticsService,
        protected readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        $events = static::getEventMappings();
        $handlers = [];

        foreach ($events as $eventClass => $handler) {
            $handlers[$eventClass] = $handler;
        }

        return $handlers;
    }

    abstract protected static function getEventMappings(): array;

    protected function trackEvent(string $eventName, array $properties = []): void
    {
        $this->analyticsService->trackEvent($eventName, $properties);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}
