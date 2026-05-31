<?php
declare(strict_types=1);

namespace Acme\Common\Collection;

/**
 * Map each input through a transformer, drop nulls, and re-index.
 *
 * @template TIn
 * @template TOut
 */
final class MapFilterReindex
{
    /**
     * @param array<int, TIn>              $items
     * @param callable(TIn): (TOut|null)   $mapper
     * @return array<int, TOut>
     */
    public static function apply(array $items, callable $mapper): array
    {
        $mapped = array_map($mapper, $items);
        $filtered = array_filter($mapped, static fn ($x): bool => $x !== null);
        return array_values($filtered);
    }
}

// usage
// MapFilterReindex::apply(
//     $events,
//     fn(RawEvent $e) => $e->payload() === null
//         ? null
//         : new NormalizedEvent($e->id(), strtolower($e->name()), $e->payload(), $e->occurredAt()),
// );
