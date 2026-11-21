<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Clustering\Clusterer;
use Phpdup\Index\BlockIndex;
use Phpdup\Ml\MlPairClient;
use Phpdup\Similarity\ContainmentSimilarity;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Worker routine for parallelized candidate-pair scoring.
 *
 * Each fork-child inherits the parent's BlockIndex via copy-on-write
 * memory (no serialization), processes its assigned chunk of (a_id,
 * b_id) candidate pairs through Jaccard + bounded TED (and the type-3
 * containment fallback when enabled), and emits the surviving edges
 * back to the master via the WorkerPool framing channel. The master
 * combines edges and runs union-find serially.
 *
 * Edge format: {a_id, b_id, similarity}
 */
final class PairScoreWorker
{
    public const TIER_EXACT_HASH = 'exact-hash';
    public const TIER_JACCARD = 'jaccard';
    // Item 1.2: paired with the jaccard gate below. Spelled IDENTICALLY to
    // \Phpdup\Clustering\MatchTier::JaccardUnverified->value — the two
    // vocabularies drift-trap tests must pin equal (they do, by pin).
    public const TIER_JACCARD_UNVERIFIED = 'jaccard-unverified';
    public const TIER_TED = 'ted';
    public const TIER_CONTAINMENT = 'containment';
    public const TIER_IR = 'ir';
    public const TIER_ML = 'ml';

    private ?MlPairClient $mlPairClient = null;

    public function __construct(
        private readonly BlockIndex $index,
        private readonly float $similarityThreshold,
        private readonly float $treeThreshold,
        private readonly bool $optionalBlocksEnabled = true,
        private readonly float $containmentThreshold = 0.85,
        private readonly float $optionalBlocksMinOverlap = 0.6,
        // IR-tier fallback — see {@see \Phpdup\Clustering\Clusterer}
        // for the full rationale. Mirrors the serial scorer there.
        private readonly bool $irScoring = false,
        private readonly float $irThreshold = 0.85,
        // ML pair-tier (option 6). Pass an empty string to disable.
        // The client itself is constructed lazily inside the worker
        // process so each fork gets its own curl handle without the
        // master serialising one.
        private readonly string $mlPairUrl = '',
        private readonly float $mlPairThreshold = 0.80,
        private readonly int $mlPairTimeoutSec = 5,
        // Item 1.1 (§5a): un-numbered TED-label mode; MUST mirror the
        // serial Clusterer's TreeEditDistance wiring (bags arrive
        // pre-stripped via the blocks themselves — the label side is the
        // half this constructor carries). Plain bool → safe under the
        // Socketpair Law (no non-scalar ever crosses to the worker).
        private readonly bool $unnumbered = false,
        // Item 1.2: TED cost gate — MUST mirror the serial Clusterer's
        // max_ted_size wiring exactly (dual-engine parity; ClusterStage
        // threads the same Config value into both). Plain int → safe under
        // the Socketpair Law (no non-scalar ever crosses to the worker).
        private readonly int $maxTedSize = Clusterer::DEFAULT_MAX_TED_SIZE,
    ) {
        if ($this->maxTedSize < 1) {
            throw new \InvalidArgumentException(sprintf(
                'maxTedSize must be >= 1 (0 or negative would gate every TED pair); got %d.',
                $this->maxTedSize,
            ));
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return list<array{0: string, 1: string, 2: float, 3: string}>
     */
    public function score(array $pairs): array
    {
        $jaccard     = new JaccardSimilarity();
        // Pre-warm ML client in the child process before entering the scoring loop.
        // This avoids the curl-init cost on the first ML pair while preserving
        // per-fork handle isolation (runs after pcntl_fork in WorkerPool).
        $client = $this->mlPairUrl !== '' ? ($this->mlPairClient ??= new MlPairClient(
            baseUrl: $this->mlPairUrl,
            timeoutSec: $this->mlPairTimeoutSec,
        )) : null;
        $ted         = new TreeEditDistance(null, $this->unnumbered);
        $containment = new ContainmentSimilarity();
        $edges       = [];
        // Collect ML candidates for batch scoring (avoids per-pair HTTP latency).
        $mlCandidates = []; // list<array{0: Block, 1: Block, 2: string, 3: string}>
        foreach ($pairs as [$aId, $bId]) {
            $a = $this->index->get($aId);
            $b = $this->index->get($bId);
            if ($a === null || $b === null) continue;

            // identical canonical hash: skip refinement, emit the trivial edge
            if ($a->structuralHash === $b->structuralHash) {
                $edges[] = [$aId, $bId, 1.0, self::TIER_EXACT_HASH];
                continue;
            }
            $bagA = $a->ngramBag ?? [];
            $bagB = $b->ngramBag ?? [];
            $jac  = $jaccard->similarity($bagA, $bagB);
            if ($jac >= $this->similarityThreshold) {
                // Item 1.2: TED cost gate — byte-for-byte the same shape as
                // Clusterer::scorePairsSerially(). Over-cap pairs never build
                // an APTED matrix; they ride the jaccard verdict alone,
                // stamped jaccard-unverified.
                if (max($a->size, $b->size) > $this->maxTedSize) {
                    $edges[] = [$aId, $bId, $jac, self::TIER_JACCARD_UNVERIFIED];
                    continue;
                }
                $tedSim = $ted->similarity($a->canonical, $b->canonical, $this->treeThreshold);
                if ($tedSim < $this->treeThreshold) continue;
                $edges[] = [$aId, $bId, min($jac, $tedSim), self::TIER_JACCARD];
                continue;
            }
            // Jaccard rejected — try the type-3 / containment path.
            if ($this->optionalBlocksEnabled) {
                $cont  = $containment->similarity($bagA, $bagB);
                $ratio = $containment->sizeRatio($bagA, $bagB);
                if ($cont >= $this->containmentThreshold && $ratio >= $this->optionalBlocksMinOverlap) {
                    $edges[] = [$aId, $bId, $cont, self::TIER_CONTAINMENT];
                    continue;
                }
            }
            // IR-tier fallback (option 5). Mirrors the Clusterer::scorePairsSerially
            // logic: lift-rejected pairs (irBag == null on either side)
            // are silently skipped so unliftable shapes don't pollute
            // the cluster set.
            if ($this->irScoring && $a->irBag !== null && $b->irBag !== null) {
                $irSim = $jaccard->similarity($a->irBag, $b->irBag);
                if ($irSim >= $this->irThreshold) {
                    $edges[] = [$aId, $bId, $irSim, self::TIER_IR];
                    continue;
                }
            }
            // ML pair-tier (option 6). Collect for batch scoring to amortize HTTP overhead.
            if ($client !== null) {
                $mlCandidates[] = [$a, $b, $aId, $bId];
            }
        }

        // Batch ML scoring — single HTTP round-trip for all candidates.
        // Falls back to individual scoring if batch endpoint is unavailable.
        if ($mlCandidates !== []) {
            // Extract just the Block pairs for the batch API.
            $mlBlockPairs = array_map(
                static fn(array $c): array => [$c[0], $c[1]],
                $mlCandidates,
            );
            $batchResults = $client->scoreBatch($mlBlockPairs);
            if ($batchResults !== null) {
                foreach ($mlCandidates as $idx => [$a, $b, $aId, $bId]) {
                    $mlScore = $batchResults[$idx] ?? null;
                    if ($mlScore !== null && $mlScore['similarity'] >= $this->mlPairThreshold) {
                        $edges[] = [$aId, $bId, $mlScore['similarity'], self::TIER_ML];
                    }
                }
            } else {
                // Batch failed — fall back to individual scoring.
                foreach ($mlCandidates as [$a, $b, $aId, $bId]) {
                    $mlScore = $client->score($a, $b);
                    if ($mlScore !== null && $mlScore['similarity'] >= $this->mlPairThreshold) {
                        $edges[] = [$aId, $bId, $mlScore['similarity'], self::TIER_ML];
                    }
                }
            }
        }

        return $edges;
    }
}
