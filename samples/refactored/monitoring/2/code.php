<?php

declare(strict_types=1);

namespace App\Analytics\Core;

interface BusinessEventTrackerInterface
{
    public function trackEvent(string $eventName, string $userId, array $properties = []): void;
    public function trackUserProperty(string $userId, array $properties): void;
    public function incrementCounter(string $metric, array $labels = []): void;
    public function recordGauge(string $metric, int $value, array $labels = []): void;
}

abstract class AbstractBusinessEventTracker implements BusinessEventTrackerInterface
{
    protected AnalyticsBackend $backend;
    protected LoggerInterface $logger;

    public function trackEvent(string $eventName, string $userId, array $properties = []): void
    {
        $enrichedProperties = $this->enrichProperties($eventName, $properties);

        $this->backend->track($eventName, $userId, $enrichedProperties);

        $this->incrementCounter($this->getMetricPrefix() . '_' . $this->normalizeEventName($eventName), [
            'event' => $eventName
        ]);

        $this->logger->info("Business event tracked: {$eventName}", [
            'user_id' => $userId,
            'properties' => $enrichedProperties
        ]);
    }

    public function trackUserProperty(string $userId, array $properties): void
    {
        $this->backend->identify($userId, $properties);
    }

    protected function enrichProperties(string $eventName, array $properties): array
    {
        return array_merge($properties, [
            'tracked_at' => date('c'),
            'source' => $this->getEventSource()
        ]);
    }

    abstract protected function getMetricPrefix(): string;
    abstract protected function getEventSource(): string;

    private function normalizeEventName(string $eventName): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $eventName));
    }
}

class UserLifecycleTracker extends AbstractBusinessEventTracker
{
    protected function getMetricPrefix(): string
    {
        return 'user_lifecycle';
    }

    protected function getEventSource(): string
    {
        return 'user_lifecycle_module';
    }
}

class SubscriptionTracker extends AbstractBusinessEventTracker
{
    protected function getMetricPrefix(): string
    {
        return 'subscription';
    }

    protected function getEventSource(): string
    {
        return 'subscription_module';
    }
}

class UnifiedBusinessEventService
{
    private array $trackers = [];

    public function registerTracker(string $domain, BusinessEventTrackerInterface $tracker): void
    {
        $this->trackers[$domain] = $tracker;
    }

    public function track(string $domain, string $event, string $userId, array $properties = []): void
    {
        if (!isset($this->trackers[$domain])) {
            throw new \RuntimeException("No tracker registered for domain: {$domain}");
        }

        $this->trackers[$domain]->trackEvent($event, $userId, $properties);
    }

    public function identify(string $domain, string $userId, array $properties): void
    {
        if (!isset($this->trackers[$domain])) {
            throw new \RuntimeException("No tracker registered for domain: {$domain}");
        }

        $this->trackers[$domain]->trackUserProperty($userId, $properties);
    }
}
