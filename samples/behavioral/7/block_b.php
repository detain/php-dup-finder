<?php

declare(strict_types=1);

namespace Ingest\Events\Filter;

final class SortedWindowDeduper
{
    /**
     * @param list<array{event:string,user:string,ts:int}> $events
     * @return list<array{event:string,user:string,ts:int}>
     */
    public function filter(array $events): array
    {
        $indexed = [];
        foreach ($events as $i => $event) {
            $indexed[] = ['_idx' => $i, '_key' => $this->keyOf($event), 'evt' => $event];
        }

        usort($indexed, static fn(array $a, array $b): int => $a['_key'] <=> $b['_key']);

        $result = [];
        $previousKey = null;
        foreach ($indexed as $row) {
            if ($row['_key'] === $previousKey) {
                continue;
            }
            $result[] = $row;
            $previousKey = $row['_key'];
        }

        usort($result, static fn(array $a, array $b): int => $a['_idx'] <=> $b['_idx']);

        return array_map(static fn(array $r): array => $r['evt'], $result);
    }

    /**
     * @param array{event:string,user:string,ts:int} $event
     */
    private function keyOf(array $event): string
    {
        return $event['event'] . "\x1f" . $event['user'] . "\x1f" . $event['ts'];
    }
}
