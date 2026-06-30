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
use Phpdup\Util\MemoryDebug;
use Symfony\Component\Console\Output\OutputInterface;

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

    private const SEEN_CAP = 200_000;

    /**
     * Generate the candidate-pair list (a_id, b_id) for parallel scoring.
     *
     * Used when the caller wants to dispatch pair scoring to a worker
     * pool: it builds the inverted index, walks each block's candidates,
     * and yields each pair exactly once (canonicalized so a_id < b_id).
     *
     * Returns a Generator to avoid materializing the full list in memory.
     * Deduplication uses a bounded $seen map capped at SEEN_CAP entries.
     * When the cap is exceeded the map is cleared — duplicates in the
     * overflow period may not be deduped, but this is extremely rare in
     * practice and bounds memory to O(SEEN_CAP) instead of O(corpus).
     *
     * @return \Generator<array{0:string,1:string}>
     */
    public function generateCandidatePairs(BlockIndex $index, ?OutputInterface $output = null): \Generator
    {
        $totalBlocks = $index->size();
        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: building inverted index for %d blocks [%s]', $totalBlocks, MemoryDebug::getMemoryUsage()));
        }

        $inverted = new NgramInvertedIndex();
        $progressCallback = static function (int $indexed, int $total) use ($output): void {
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG && $indexed % 5000 === 0) {
                $output->writeln(sprintf('ngram-index: indexed %d / %d blocks [%s]', $indexed, $total, MemoryDebug::getMemoryUsage()));
            }
        };
        $inverted->build($index, $progressCallback);

        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: inverted index built [%s]', MemoryDebug::getMemoryUsage()));
            $output->writeln(sprintf('ngram-index: enumerating candidate pairs for %d blocks', $totalBlocks));
        }

        // Bounded $seen map for dedup — O(SEEN_CAP) memory instead of O(corpus).
        // When the cap is exceeded the map is cleared; this means some very
        // rare pairs that appear after the cap is hit and were duplicates of
        // pairs seen before the cap was hit will not be deduped, which is
        // acceptable because (a) it is extremely rare in practice and (b)
        // the alternative is unbounded memory growth.
        $seen = [];
        $yieldCount = 0;
        $blockNum = 0;
        foreach ($index->all() as $a) {
            $blockNum++;
            if (count($seen) > self::SEEN_CAP) {
                $seen = [];
            }
            foreach ($inverted->candidatesFor($a, $this->maxDocumentFrequency) as $bid) {
                $aId = $a->id;
                $key = strcmp($aId, $bid) < 0 ? $aId . '|' . $bid : $bid . '|' . $aId;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $yieldCount++;
                yield [strcmp($aId, $bid) < 0 ? $aId : $bid, strcmp($aId, $bid) < 0 ? $bid : $aId];
            }
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG && $blockNum % 5000 === 0) {
                $output->writeln(sprintf('ngram-index: processed %d / %d blocks, %d candidates found [%s]', $blockNum, $totalBlocks, $yieldCount, MemoryDebug::getMemoryUsage()));
            }
        }

        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: enumeration complete, %d candidate pairs found [%s]', $yieldCount, MemoryDebug::getMemoryUsage()));
        }
    }

    /**
     * Cluster blocks via hash-buckets (exact) then similarity edges (near-duplicate).
     *
     * Phase 2 cluster similarity is computed in O(edges) time via a running
     * per-component minimum maintained during union-find traversal — NOT by
     * re-scanning all member pairs (which would be O(Σk²) for component size k).
     *
     * The running minimum is stored in the $componentMinSim array keyed by
     * union-find root. For a new inter-component edge (aId, bId, sim):
     *   - roots $rx and $ry are captured BEFORE union so the pre-union
     *     condition is meaningful.
     *   - newRoot = union($aId, $bId).
     *   - componentMinSim[newRoot] = min(componentMinSim[$rx] ?? 1.0,
     *                                   componentMinSim[$ry] ?? 1.0, sim).
     *
     * For intra-component edges (already same root), the running min is
     * updated in-place: componentMinSim[root] = min(componentMinSim[root], sim).
     *
     * At cluster emission time, similarity = componentMinSim[root] (O(1) lookup)
     * instead of the O(k²) pair-scan that was here before.
     *
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
        // Accumulate per-component running minimum similarity during union — O(edges).
        // This replaces the O(Σk²) brute-force pair scan that was here before.
        //
        // $componentMinSim[$root] holds the minimum edge similarity seen so far
        // for every edge whose endpoints belong to the component whose current
        // union-find root is $root.  The array is updated in two patterns:
        //
        //   (a) Inter-component edge: roots $rx/$ry are captured BEFORE union()
        //       so we can read the sub-components' existing running minima.
        //       After union() we initialise the new root's entry from the three
        //       candidates: min(componentMinSim[$rx], componentMinSim[$ry], sim).
        //
        //   (b) Intra-component edge: the two nodes are already in the same
        //       component.  union() is still called (its rank logic may change the
        //       stored root) and we update the running min in-place with the
        //       possibly-lower similarity of this new edge.
        //
        // At cluster-emission time similarity is a simple O(1) lookup instead
        // of scanning all O(k²) member pairs in a component of size k.
        $componentMinSim = [];
        foreach ($edges as [$aId, $bId, $sim]) {
            $key = $aId . '|' . $bId;
            $edgeMap[$key] = $sim;
            // Capture roots BEFORE union so the pre-union condition is meaningful.
            $rx = $uf->find($aId);
            $ry = $uf->find($bId);
            if ($rx !== $ry) {
                // Different components: merge and initialise the running min from
                // both sub-components plus the new edge.
                $minRx = $componentMinSim[$rx] ?? 1.0;
                $minRy = $componentMinSim[$ry] ?? 1.0;
                $uf->union($aId, $bId);
                $newRoot = $uf->find($aId);
                $componentMinSim[$newRoot] = min($minRx, $minRy, $sim);
            } else {
                // Same component already: the earlier union recorded the correct
                // running min, but this edge may have a lower similarity — update it.
                $uf->union($aId, $bId);
                $root = $uf->find($aId);
                $componentMinSim[$root] = min($componentMinSim[$root] ?? 1.0, $sim);
            }
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
            // Use the pre-computed running min accumulated during union instead of
            // re-scanning all member pairs — O(1) instead of O(k²).
            $minSim = $componentMinSim[$root] ?? 1.0;
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
