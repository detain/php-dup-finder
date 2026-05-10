<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\NgramInvertedIndex;
use Phpdup\Ml\PairScorer;
use Phpdup\Similarity\ContainmentSimilarity;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Two-phase clustering:
 *
 *   1. Hash buckets — blocks with identical structuralHash form an
 *      exact-clone cluster. No similarity computation needed; cheap.
 *
 *   2. Near-duplicate edges — for each block, pull candidates from the
 *      n-gram inverted index (rare-gram filter), score each candidate
 *      by Jaccard, keep pairs above similarityThreshold. Remaining
 *      pairs are refined by bounded tree-edit-distance and kept above
 *      treeThreshold. Survivors become similarity edges; union-find
 *      forms clusters from the edges.
 *
 * If a non-null `edges` parameter is passed to `cluster()`, phase 2
 * pair scoring is skipped and the supplied edges are used directly —
 * this is the integration point for the parallel pair-scoring path.
 */
final class Clusterer
{
    private readonly ContainmentSimilarity $containment;

    public function __construct(
        private readonly JaccardSimilarity $similarity,
        private readonly TreeEditDistance $tree,
        private readonly float $similarityThreshold = 0.80,
        private readonly float $treeThreshold = 0.85,
        private readonly float $maxDocumentFrequency = 0.01,
        private readonly bool $exactOnly = false,
        // Type-3 "near-subset" detection: when Jaccard fails but the smaller bag is
        // contained in the larger above this threshold AND the size ratio between
        // them is above minOverlap, accept the pair anyway. Disabled = legacy
        // Jaccard-only behaviour.
        private readonly bool $optionalBlocksEnabled = true,
        private readonly float $containmentThreshold = 0.85,
        private readonly float $optionalBlocksMinOverlap = 0.6,
        // IR-tier (option 5 of docs/plans/orm-db-semantic-dedup.md):
        // when enabled, after the AST-level Jaccard / TED / containment
        // chain rejects a pair, fall back to multiset-Jaccard over the
        // pre-computed IR token bags ({@see Block::$irBag}). Pairs at
        // or above $irThreshold emit edges weighted by the IR
        // similarity. The IR bag is null when lifting failed for
        // either block; in that case the IR tier is silently skipped
        // (per the plan's risk-mitigation note).
        private readonly bool $irScoring = false,
        private readonly float $irThreshold = 0.85,
        // ML pair-tier (option 6). When non-null, the very last
        // chance for a pair to form an edge: phpdup posts the
        // PairFeatures vector to the configured /score-pair sidecar
        // and accepts pairs at or above $mlPairThreshold. The
        // sidecar returns null on transport failure so unavailability
        // never breaks the run; this is the same fail-graceful
        // contract as the IR lifter (per the plan's risk-mitigation
        // note).
        private readonly ?PairScorer $mlPairClient = null,
        private readonly float $mlPairThreshold = 0.80,
    ) {
        $this->containment = new ContainmentSimilarity();
    }

    /**
     * Generate the candidate-pair list (a_id, b_id) for parallel scoring.
     *
     * Used when the caller wants to dispatch pair scoring to a worker
     * pool: it builds the inverted index, walks each block's candidates,
     * and yields each pair exactly once (canonicalized so a_id < b_id).
     *
     * @return list<array{0:string,1:string}>
     */
    public function generateCandidatePairs(BlockIndex $index): array
    {
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);
        $pairs = [];
        $seen = [];
        foreach ($index->all() as $a) {
            foreach ($inverted->candidatesFor($a, $this->maxDocumentFrequency) as $bid) {
                $key = strcmp($a->id, $bid) < 0 ? $a->id . '|' . $bid : $bid . '|' . $a->id;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $pairs[] = [strcmp($a->id, $bid) < 0 ? $a->id : $bid, strcmp($a->id, $bid) < 0 ? $bid : $a->id];
            }
        }
        return $pairs;
    }

    /**
     * @param list<array{0:string,1:string,2:float}>|null $edges  pre-computed
     *        edges from a parallel scoring run; null means score serially.
     * @return list<Cluster>
     */
    public function cluster(BlockIndex $index, ?array $edges = null): array
    {
        $clusters = [];
        $exactlyClustered = [];

        // Phase 1: hash buckets — always done serially, dirt cheap.
        foreach ($index->hashBuckets() as $hash => $blocks) {
            if (count($blocks) < 2) continue;
            $clusters[] = new Cluster(
                id: 'X' . substr($hash, 0, 8),
                members: $blocks,
                similarity: 1.0,
                exact: true,
            );
            foreach ($blocks as $b) {
                $exactlyClustered[$b->id] = true;
            }
        }

        if ($this->exactOnly) {
            return $clusters;
        }

        // Phase 2: edges (either supplied externally or computed serially)
        $uf = new UnionFind();
        foreach ($index->all() as $b) {
            $uf->add($b->id);
        }
        $edgeMap = [];

        if ($edges === null) {
            $edges = $this->scorePairsSerially($index);
        }
        foreach ($edges as [$aId, $bId, $sim]) {
            $key = $aId . '|' . $bId;
            $edgeMap[$key] = $sim;
            $uf->union($aId, $bId);
        }

        // Components → clusters
        $byComponent = [];
        foreach ($index->all() as $b) {
            $root = $uf->find($b->id);
            $byComponent[$root][] = $b;
        }

        $merged = [];
        $emittedBlocks = [];
        foreach ($byComponent as $root => $members) {
            if (count($members) < 2) continue;
            $h = $members[0]->structuralHash;
            $allIdenticalHash = true;
            foreach ($members as $m) {
                if ($m->structuralHash !== $h) {
                    $allIdenticalHash = false;
                    break;
                }
            }
            $minSim = 1.0;
            if (!$allIdenticalHash) {
                for ($i = 0; $i < count($members); $i++) {
                    for ($j = $i + 1; $j < count($members); $j++) {
                        $a = $members[$i]->id; $b = $members[$j]->id;
                        $key = strcmp($a, $b) < 0 ? "$a|$b" : "$b|$a";
                        if (isset($edgeMap[$key]) && $edgeMap[$key] < $minSim) {
                            $minSim = $edgeMap[$key];
                        }
                    }
                }
            }
            $merged[] = new Cluster(
                id: 'C' . substr(md5((string)$root), 0, 8),
                members: $members,
                similarity: $allIdenticalHash ? 1.0 : $minSim,
                exact: $allIdenticalHash,
            );
            foreach ($members as $m) {
                $emittedBlocks[$m->id] = true;
            }
        }

        $finalClusters = $merged;
        foreach ($clusters as $c) {
            $allEmitted = true;
            foreach ($c->members as $m) {
                if (!isset($emittedBlocks[$m->id])) {
                    $allEmitted = false;
                    break;
                }
            }
            if (!$allEmitted) {
                $finalClusters[] = $c;
            }
        }
        return $finalClusters;
    }

    /**
     * @return list<array{0:string,1:string,2:float}>
     */
    private function scorePairsSerially(BlockIndex $index): array
    {
        $pairs = $this->generateCandidatePairs($index);
        $edges = [];
        foreach ($pairs as [$aId, $bId]) {
            $a = $index->get($aId);
            $b = $index->get($bId);
            if ($a === null || $b === null) continue;
            if ($a->structuralHash === $b->structuralHash) {
                $edges[] = [$aId, $bId, 1.0];
                continue;
            }
            $bagA = $a->ngramBag ?? [];
            $bagB = $b->ngramBag ?? [];
            $jac = $this->similarity->similarity($bagA, $bagB);
            if ($jac >= $this->similarityThreshold) {
                $tedSim = $this->tree->similarity($a->canonical, $b->canonical, $this->treeThreshold);
                if ($tedSim < $this->treeThreshold) continue;
                $edges[] = [$aId, $bId, min($jac, $tedSim)];
                continue;
            }
            // Jaccard rejected: try the type-3 / "near-subset" path. If the smaller
            // bag is contained in the larger above $containmentThreshold AND the
            // bags are at least $optionalBlocksMinOverlap comparable in size, mark
            // it a near-duplicate-with-optional-segments and let AntiUnifier handle
            // the LCS alignment. Edge similarity is the containment score so the
            // Ranker can still distinguish strong subsets from marginal ones.
            $cont = null;
            if ($this->optionalBlocksEnabled) {
                $cont  = $this->containment->similarity($bagA, $bagB);
                $ratio = $this->containment->sizeRatio($bagA, $bagB);
                if ($cont >= $this->containmentThreshold && $ratio >= $this->optionalBlocksMinOverlap) {
                    $edges[] = [$aId, $bId, $cont];
                    continue;
                }
            }
            // IR-tier fallback (option 5). Compares pre-computed IR
            // token bags by multiset Jaccard. Skipped when either bag
            // is null (i.e. the lift failed) so an unliftable shape
            // never silently boosts a pair.
            if ($this->irScoring && $a->irBag !== null && $b->irBag !== null) {
                $irSim = $this->similarity->similarity($a->irBag, $b->irBag);
                if ($irSim >= $this->irThreshold) {
                    $edges[] = [$aId, $bId, $irSim];
                    continue;
                }
            }
            // ML pair-tier (option 6). Last-chance scoring against
            // an external model. Returns null on transport failure
            // so unavailability never breaks the run.
            if ($this->mlPairClient !== null) {
                $mlScore = $this->mlPairClient->score($a, $b);
                if ($mlScore !== null && $mlScore['similarity'] >= $this->mlPairThreshold) {
                    $edges[] = [$aId, $bId, $mlScore['similarity']];
                }
            }
        }
        return $edges;
    }
}

/** @internal */
final class UnionFind
{
    /** @var array<string,string> */
    private array $parent = [];
    /** @var array<string,int> */
    private array $rank = [];

    public function add(string $x): void
    {
        if (!isset($this->parent[$x])) {
            $this->parent[$x] = $x;
            $this->rank[$x] = 0;
        }
    }

    public function find(string $x): string
    {
        while ($this->parent[$x] !== $x) {
            $this->parent[$x] = $this->parent[$this->parent[$x]];
            $x = $this->parent[$x];
        }
        return $x;
    }

    public function union(string $x, string $y): void
    {
        $rx = $this->find($x);
        $ry = $this->find($y);
        if ($rx === $ry) return;
        if ($this->rank[$rx] < $this->rank[$ry]) {
            $this->parent[$rx] = $ry;
        } elseif ($this->rank[$rx] > $this->rank[$ry]) {
            $this->parent[$ry] = $rx;
        } else {
            $this->parent[$ry] = $rx;
            $this->rank[$rx]++;
        }
    }
}
