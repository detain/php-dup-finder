<?php

declare(strict_types=1);

namespace Pipeline\Events\Unique;

final class ArrayUniqueDeduper
{
    /**
     * @param list<array{event:string,user:string,ts:int}> $events
     * @return list<array{event:string,user:string,ts:int}>
     */
    public function uniqueEvents(array $events): array
    {
        $serialized = array_map(
            static fn(array $e): string => json_encode($e, JSON_THROW_ON_ERROR),
            $events,
        );

        $uniqueSerialized = array_unique($serialized, SORT_STRING);

        $out = [];
        foreach (array_keys($uniqueSerialized) as $i) {
            $out[] = $events[$i];
        }

        return $out;
    }
}
