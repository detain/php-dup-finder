<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use Phpdup\Extraction\Block;

/**
 * Contract for the option-6 ML pair-similarity scoring tier.
 *
 * Implemented by {@see MlPairClient} — the production HTTP-backed
 * scorer — and by test doubles. The {@see \Phpdup\Clustering\Clusterer}
 * + {@see \Phpdup\Parallel\PairScoreWorker} accept this interface so
 * unit tests can substitute deterministic in-memory implementations
 * without spinning up a real sidecar.
 */
interface PairScorer
{
    /**
     * Score a (A, B) block pair.
     *
     * @return array{similarity: float, confidence: float}|null Null when
     *         the scorer cannot produce a result (transport error,
     *         disabled, malformed response). The Clusterer treats
     *         null as "skip this tier for this pair" — same
     *         fail-graceful contract as the IR lifter.
     */
    public function score(Block $a, Block $b): ?array;
}
