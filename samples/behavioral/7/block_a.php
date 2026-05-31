<?php

declare(strict_types=1);

namespace Analytics\Stream\Dedup;

final class HashSetDeduplicator
{
    /**
     * @param iterable<array{event:string,user:string,ts:int}> $events
     * @return list<array{event:string,user:string,ts:int}>
     */
    public function dedupe(iterable $events): array
    {
        $seen = [];
        $out = [];

        foreach ($events as $event) {
            $key = $this->keyFor($event);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $event;
        }

        return $out;
    }

    /**
     * @param array{event:string,user:string,ts:int} $event
     */
    private function keyFor(array $event): string
    {
        return hash('xxh3', $event['event'] . '|' . $event['user'] . '|' . $event['ts']);
    }
}
