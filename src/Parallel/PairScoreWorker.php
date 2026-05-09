<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Index\BlockIndex;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Worker routine for parallelized candidate-pair scoring.
 *
 * Each fork-child inherits the parent's BlockIndex via copy-on-write
 * memory (no serialization), processes its assigned chunk of (a_id,
 * b_id) candidate pairs through Jaccard + bounded TED, and emits the
 * surviving edges back to the master via the WorkerPool tempfile
 * channel. The master combines edges and runs union-find serially.
 *
 * Edge format: {a_id, b_id, similarity}
 */
final class PairScoreWorker
{
    public function __construct(
        private readonly BlockIndex $index,
        private readonly float $similarityThreshold,
        private readonly float $treeThreshold,
    ) {
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @return list<array{0: string, 1: string, 2: float}>
     */
    public function score(array $pairs): array
    {
        $jaccard = new JaccardSimilarity();
        $ted = new TreeEditDistance();
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
            $jac = $jaccard->similarity($a->ngramBag ?? [], $b->ngramBag ?? []);
            if ($jac < $this->similarityThreshold) continue;
            $tedSim = $ted->similarity($a->canonical, $b->canonical, $this->treeThreshold);
            if ($tedSim < $this->treeThreshold) continue;
            $edges[] = [$aId, $bId, min($jac, $tedSim)];
        }
        return $edges;
    }
}
