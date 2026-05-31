<?php
declare(strict_types=1);

namespace Acme\Common;

/**
 * Generic flatten-or-expand streamer. Each domain provides:
 *   - the source iterable
 *   - the key under which "expand-me" children live
 *   - the per-item enricher / id prefix
 * and the same yield/yield-from skeleton runs once here.
 *
 * @template T
 * @template R
 */
final class ExpandingStreamer
{
    /**
     * @param iterable<int, T>             $source
     * @param string                       $expandKey   array key on the item that, if set, holds sub-items
     * @param callable(T):R                $project     normalize/enrich an item to its emitted shape
     * @param string                       $idPrefix    "row" / "evt" / "url"
     * @return \Generator<int, array{0:string, 1:R}>
     */
    public function stream(iterable $source, string $expandKey, callable $project, string $idPrefix): \Generator
    {
        foreach ($source as $index => $item) {
            if (isset($item[$expandKey])) {
                foreach ($item[$expandKey] as $subIndex => $sub) {
                    yield [sprintf('%s-%d.%d', $idPrefix, $index, $subIndex), $project($sub)];
                }
            } else {
                yield [sprintf('%s-%d', $idPrefix, $index), $project($item)];
            }
        }
    }
}

/* Example wiring (replaces all three classes' emit/stream/feed bodies):
 *
 *  $streamer->stream(
 *      $this->reader->rows($path),
 *      '__expanded',
 *      fn(array $r) => $this->normalizer->normalize($r),
 *      'row',
 *  );
 */
