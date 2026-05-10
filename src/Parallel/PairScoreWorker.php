<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

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
    ) {
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return list<array{0: string, 1: string, 2: float}>
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
        $ted         = new TreeEditDistance();
        $containment = new ContainmentSimilarity();
        $edges = [];
        foreach ($pairs as [$aId, $bId]) {
            $a = $this->index->get($aId);
            $b = $this->index->get($bId);
            if ($a === null || $b === null) continue;

            // identical canonical hash: skip refinement, emit the trivial edge
            if ($a->structuralHash === $b->structuralHash) {
                $edges[] = [$aId, $bId, 1.0];
                continue;
            }
            $bagA = $a->ngramBag ?? [];
            $bagB = $b->ngramBag ?? [];
            $jac  = $jaccard->similarity($bagA, $bagB);
            if ($jac >= $this->similarityThreshold) {
                $tedSim = $ted->similarity($a->canonical, $b->canonical, $this->treeThreshold);
                if ($tedSim < $this->treeThreshold) continue;
                $edges[] = [$aId, $bId, min($jac, $tedSim)];
                continue;
            }
            // Jaccard rejected — try the type-3 / containment path.
            if ($this->optionalBlocksEnabled) {
                $cont  = $containment->similarity($bagA, $bagB);
                $ratio = $containment->sizeRatio($bagA, $bagB);
                if ($cont >= $this->containmentThreshold && $ratio >= $this->optionalBlocksMinOverlap) {
                    $edges[] = [$aId, $bId, $cont];
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
                    $edges[] = [$aId, $bId, $irSim];
                    continue;
                }
            }
            // ML pair-tier (option 6). Last-chance scoring against
            // an external model — see {@see \Phpdup\Clustering\Clusterer}
            // for the full rationale. Client is pre-warmed at start of score().
            if ($client !== null) {
                $mlScore = $client->score($a, $b);
                if ($mlScore !== null && $mlScore['similarity'] >= $this->mlPairThreshold) {
                    $edges[] = [$aId, $bId, $mlScore['similarity']];
                }
            }
        }
        return $edges;
    }
}
