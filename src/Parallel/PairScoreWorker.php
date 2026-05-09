<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Index\BlockIndex;
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
    public function __construct(
        private readonly BlockIndex $index,
        private readonly float $similarityThreshold,
        private readonly float $treeThreshold,
        private readonly bool $optionalBlocksEnabled = true,
        private readonly float $containmentThreshold = 0.85,
        private readonly float $optionalBlocksMinOverlap = 0.6,
    ) {
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return list<array{0: string, 1: string, 2: float}>
     */
    public function score(array $pairs): array
    {
        $jaccard     = new JaccardSimilarity();
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
            if (!$this->optionalBlocksEnabled) continue;
            $cont  = $containment->similarity($bagA, $bagB);
            $ratio = $containment->sizeRatio($bagA, $bagB);
            if ($cont < $this->containmentThreshold || $ratio < $this->optionalBlocksMinOverlap) continue;
            $edges[] = [$aId, $bId, $cont];
        }
        return $edges;
    }
}
