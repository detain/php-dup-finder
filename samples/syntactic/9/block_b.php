<?php
declare(strict_types=1);

namespace Acme\Audit;

final class AuditEventStream
{
    public function __construct(
        private AuditStore $store,
        private EventEnricher $enricher,
    ) {
    }

    /**
     * @return \Generator<int, array{0:string, 1:array<string,mixed>}>
     */
    public function stream(string $traceId): \Generator
    {
        foreach ($this->store->forTrace($traceId) as $eventSeq => $event) {
            if (isset($event['children'])) {
                yield from $this->expandChildren($eventSeq, $event);
            } else {
                yield [
                    sprintf('evt-%d', $eventSeq),
                    $this->enricher->enrich($event),
                ];
            }
        }
    }

    /**
     * @return \Generator<int, array{0:string, 1:array<string,mixed>}>
     */
    private function expandChildren(int $eventSeq, array $event): \Generator
    {
        foreach ($event['children'] as $childIndex => $child) {
            yield [
                sprintf('evt-%d.%d', $eventSeq, $childIndex),
                $this->enricher->enrich($child),
            ];
        }
    }
}
