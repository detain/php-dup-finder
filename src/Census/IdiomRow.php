<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * One idiom-census row (item 3.1 output shape: "ranked census
 * idiom: hash/sites/files/sample locations").
 *
 * Immutable value object produced by {@see SubtreeCensus::rows()} /
 * {@see SubtreeCensus::maximalRows()}. `locations` follows the doc's
 * two-pass memory policy: a hash seen at only ONE site stores NO
 * locations at all (the per-hash pending slot exists only long enough
 * to pair with a second sighting); hashes seen >= 2 times carry up to
 * `sampleCap` 'file:line' samples.
 */
final class IdiomRow
{
    /** @param list<string> $locations */
    public function __construct(
        public readonly string $exactHash,
        public readonly string $abstractHash,
        public readonly int $nodes,
        public readonly string $rootType,
        public readonly int $sites,
        public readonly int $files,
        public readonly array $locations,
    ) {
    }
}
