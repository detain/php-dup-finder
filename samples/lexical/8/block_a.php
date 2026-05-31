<?php
declare(strict_types=1);

namespace Acme\Analytics\Pipeline;

use Acme\Analytics\Event\RawEvent;
use Acme\Analytics\Event\NormalizedEvent;

final class EventTransformer
{
    public function __construct(
        private readonly string $tenantId,
    ) {
    }

    /**
     * @param array<int, RawEvent> $events
     * @return array<int, NormalizedEvent>
     */
    public function transform(array $events): array
    {
        // canonical: map -> filter -> values pipeline
        return array_values(array_filter(array_map(
            function (RawEvent $event): ?NormalizedEvent {
                if ($event->payload() === null) {
                    return null;
                }
                return new NormalizedEvent(
                    $event->id(),
                    strtolower($event->name()),
                    $event->payload(),
                    $event->occurredAt(),
                );
            },
            $events,
        ), static fn (?NormalizedEvent $e): bool => $e !== null));
    }

    /**
     * @param array<int, RawEvent> $events
     */
    public function counts(array $events): int
    {
        return count($this->transform($events));
    }
}
