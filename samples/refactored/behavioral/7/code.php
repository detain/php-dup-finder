<?php

declare(strict_types=1);

namespace App\Events;

final class EventDeduplicator
{
    /**
     * @param iterable<array{event:string,user:string,ts:int}> $events
     * @return list<array{event:string,user:string,ts:int}>
     */
    public function unique(iterable $events): array
    {
        $seen = [];
        $out = [];

        foreach ($events as $event) {
            $key = $this->fingerprint($event);

            if (array_key_exists($key, $seen)) {
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
    private function fingerprint(array $event): string
    {
        return $event['event'] . "\x1f" . $event['user'] . "\x1f" . $event['ts'];
    }
}
