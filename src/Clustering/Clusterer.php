<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

use Phpdup\Clustering\MatchTier;
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
        // Item 1.4 (ruling §5b): single-linkage union-find chains loosely
        // related blocks (the 167-guard storm: one cluster, reported 0.80,
        // first-vs-last members scoring 0.00). When ON (the decided
        // default) each component is partitioned post-union-find by a
        // greedy medoid-ball pass that consumes ONLY the already
        // materialized edges — zero new pair scoring.
        private readonly bool $splitClusters = self::DEFAULT_SPLIT_CLUSTERS,
        private readonly int $minSplitSize = self::DEFAULT_MIN_SPLIT_SIZE,
    ) {
        if ($this->minSplitSize < 2) {
            throw new \InvalidArgumentException(sprintf(
                'minSplitSize must be >= 2 (a medoid ball needs a medoid plus at least one member); got %d.',
                $this->minSplitSize,
            ));
        }
        $this->containment = new ContainmentSimilarity();
    }

    private const SEEN_CAP = 200_000;

    /**
     * Item 1.4 (ruling §5b): greedy medoid-ball split of union-find
     * components. Default ON — the single-linkage chain noise IS the bug
     * being fixed; the off-switch exists for bisecting split complaints.
     */
    public const DEFAULT_SPLIT_CLUSTERS = true;

    /**
     * Smallest medoid ball allowed to stand as its own cluster. Balls
     * below the floor dissolve into the residue pool, which re-forms as
     * one cluster under pre-split (single-linkage) semantics — the
     * "tighten the knobs" seam of §5b. At the default 2 the pool only
     * ever collects blocks that had no edge left among the remaining
     * members.
     */
    public const DEFAULT_MIN_SPLIT_SIZE = 2;

    /**
     * Safety budget on total candidate-pair output of
     * {@see generateCandidatePairs()} (item 0.5, F3 gen-loop guard).
     *
     * Rare-gram posting unions make pair count quadratic inside a clone
     * family: measured corpora stay at 787-882 pairs/block (18.8M pairs on
     * 23,895 blocks for phlix) while the observed storm hit 2,400-3,400
     * pairs/block and died at 80 GB / ~320M materialized pairs. 60M bounds
     * the materialized list at ~14 GB — ~3× the largest corpus ever
     * completed, ~5× below the death zone. Breaching the budget halts with
     * a loud {@see CandidateBudgetException} instead of an OOM; below it
     * the enumeration is pair-for-pair identical to the unguarded loop.
     */
    public const MAX_CANDIDATE_PAIRS = 60_000_000;

    /** Sentinel budget for {@see generateCandidatePairs()} meaning "no cap". */
    public const UNLIMITED_CANDIDATE_PAIRS = PHP_INT_MAX;

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
     * Output is hard-capped by the candidate budget: yielding pair number
     * $maxCandidatePairs + 1 throws instead. The cap never reorders, skips
     * or duplicates pairs below it, so any run that completes under the
     * guard produces exactly what the uncapped loop would have.
     *
     * @param int|null $maxCandidatePairs total pairs this enumeration may
     *        yield; null = self::MAX_CANDIDATE_PAIRS (use
     *        self::UNLIMITED_CANDIDATE_PAIRS to disable the guard).
     * @return \Generator<array{0:string,1:string}>
     */
    public function generateCandidatePairs(
        BlockIndex $index,
        ?OutputInterface $output = null,
        ?int $maxCandidatePairs = null,
    ): \Generator {
        $totalBlocks = $index->size();
        $candidateBudget = $maxCandidatePairs ?? self::MAX_CANDIDATE_PAIRS;
        if ($candidateBudget < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Candidate budget must be >= 1; got %d. Use Clusterer::UNLIMITED_CANDIDATE_PAIRS to disable the guard.',
                $candidateBudget,
            ));
        }
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
                if ($yieldCount > $candidateBudget) {
                    // Fail loud, not OOM: the (budget+1)-th pair is exactly
                    // where a storm run would otherwise keep growing without
                    // bound. Nothing before this line was reordered or lost.
                    throw CandidateBudgetException::exceeded($candidateBudget, $blockNum, $totalBlocks);
                }
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
     * @param list<array{0:string,1:string,2:float,3:string}>|null $edges  pre-computed
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
            $cluster = new Cluster(
                id: 'X' . substr($hash, 0, 8),
                members: $blocks,
                similarity: 1.0,
                exact: true,
            );
            $cluster->pairs = $this->buildExactPairs($blocks);
            $clusters[] = $cluster;
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
        foreach ($edges as [$aId, $bId, $sim, $tier]) {
            // Orientation-agnostic key, mirroring the buildPairsFromEdges
            // lookup (strcmp-canonical). An inverted arrival must not
            // silently vanish from $edgeMap: since item 1.4, Cluster::$pairs
            // is the medoid-ball splitter's SOLE input, so a dropped edge
            // would be a dropped adjacency. A pair seen twice (any mix of
            // orientations) keeps the last-arrival edge.
            $key = strcmp($aId, $bId) < 0 ? $aId . '|' . $bId : $bId . '|' . $aId;
            $edgeMap[$key] = ['sim' => $sim, 'tier' => $tier];
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
            $component = new Cluster(
                // Item 1.4 (ruling §5e, in-lane stopgap for 4.4): ids derive
                // from the SORTED member content tokens (file|structuralHash,
                // the element of §5e's sha256(file|structuralHash) direction),
                // never from the union-find root — the root is edge-arrival-
                // order dependent, so md5(root) gave identical clusters
                // different ids between serial and parallel runs.
                id: self::contentClusterId($members),
                members: $members,
                similarity: $allIdenticalHash ? 1.0 : $minSim,
                exact: $allIdenticalHash,
            );
            $component->pairs = $this->buildPairsFromEdges($members, $edgeMap);

            $groups = $this->splitClusters
                ? $this->splitComponent($component, $minSim)
                : [$component];

            foreach ($groups as $group) {
                $merged[] = $group;
                foreach ($group->members as $m) {
                    $emittedBlocks[$m->id] = true;
                }
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
     * Content-derived cluster id (item 1.4 in-lane stopgap for 4.4, per
     * the §5e ruling): 'C' + a digest of the SORTED member content tokens
     * (file|structuralHash per §5e's element derivation). Two runs that
     * formed the same cluster from different edge-arrival orders now get
     * the same id — unlike the previous md5(union-find-root), whose root
     * depended on arrival order and swapped between serial and parallel.
     *
     * Known limitation inherited from §5e's own formula: two clusters of
     * equal (file|structuralHash) multisets collide — possible only when
     * identical-hash blocks are kept apart by df-pruning. 4.4's FindingId
     * pass replaces this derivation wholesale; the sorted-content-element
     * shape is what stays.
     *
     * @param list<Block> $members
     */
    private static function contentClusterId(array $members): string
    {
        $tokens = [];
        foreach ($members as $m) {
            $tokens[] = $m->file . '|' . $m->structuralHash;
        }
        sort($tokens);
        return 'C' . substr(md5(implode("\x1f", $tokens)), 0, 8);
    }

    /**
     * Greedy medoid-ball split of one union-find component (item 1.4,
     * ruling §5b) — post-union-find, consuming ONLY the materialized
     * {@see Cluster::$pairs}. Edge existence already encodes the
     * tier-composite gate actually used to accept the pair (Jaccard arm,
     * containment arm, IR, ML), so membership runs on that composite —
     * never CoherenceAnalyzer's raw-Jaccard basis, which would false-split
     * containment-bridged pairs (filing 06 Demo C).
     *
     * Loop until the component is consumed:
     *   1. medoid = the member with the most still-remaining neighbours
     *      (degree desc, then structuralHash asc, then scan order — fully
     *      deterministic, so the partition never depends on edge arrival
     *      order);
     *   2. ball = medoid + those neighbours. Every ball member is, by
     *      construction, edge-adjacent to its medoid at or above the tier
     *      floor — "no member below its medoid threshold" holds by shape,
     *      and every group is connected through above-floor edges (each
     *      member joins the group via its medoid edge, or — residue
     *      stragglers — via its best accepted intra-group edge);
     *   3. a ball below minSplitSize dissolves into the residue pool.
     *
     * Residue handling (§5b "old behavior preserved" escape hatch):
     *   - >= 2 members  -> one residue cluster with pre-split semantics,
     *     including the legacy weakest-edge-on-connecting-path similarity;
     *   - exactly 1     -> attached to the group holding its strongest
     *     edge (ties -> earliest group), contributing that edge's score as
     *     its group-link. (A residue member's neighbours can only live in
     *     surviving groups: an edge to another residue would have kept
     *     both in the remaining graph.)
     *
     * Reported per-group similarity = the weakest member-vs-medoid
     * (or member-group-link for an attached straggler) edge — §5b's
     * "similarity corrected to the per-member-vs-medoid score".
     * `pairs` are filtered to each group's members; cross-ball edges —
     * the single-linkage bridges — drop out of the sub-cluster evidence.
     *
     * Cost is set algebra over already-materialized edges: zero new pair
     * scoring. Measured ~11 ms for filing 06's 167-member storm component.
     *
     * @param Cluster $component   component cluster with members + pairs populated
     * @param float   $legacyMinSim the component's pre-split running-min similarity
     * @return list<Cluster>
     */
    private function splitComponent(Cluster $component, float $legacyMinSim): array
    {
        $byId = [];
        foreach ($component->members as $m) {
            $byId[$m->id] = $m;
        }

        /** @var array<string, array<string, float>> $adjacency */
        $adjacency = [];
        foreach ($byId as $id => $_) {
            $adjacency[$id] = [];
        }
        foreach ($component->pairs as $pair) {
            $a = $pair['blockA'];
            $b = $pair['blockB'];
            if (!isset($byId[$a]) || !isset($byId[$b])) {
                continue;
            }
            $adjacency[$a][$b] = $pair['matchScore'];
            $adjacency[$b][$a] = $pair['matchScore'];
        }

        $remaining = $byId;
        /** @var list<array{medoid: string|null, members: list<Block>, anchors: array<string, float>}> $groups */
        $groups = [];
        /** @var list<Block> $residue */
        $residue = [];

        while ($remaining !== []) {
            $medoidId = $this->pickMedoid($remaining, $adjacency);
            $ball     = [$medoidId => $remaining[$medoidId]];
            $anchors  = [];
            unset($remaining[$medoidId]);
            foreach (array_keys($adjacency[$medoidId]) as $neighbourId) {
                if (!isset($remaining[$neighbourId])) {
                    continue;
                }
                $ball[$neighbourId]     = $remaining[$neighbourId];
                $anchors[$neighbourId]  = $adjacency[$medoidId][$neighbourId];
                unset($remaining[$neighbourId]);
            }
            if (count($ball) >= $this->minSplitSize) {
                $groups[] = ['medoid' => $medoidId, 'members' => array_values($ball), 'anchors' => $anchors];
                continue;
            }
            foreach ($ball as $m) {
                $residue[] = $m;
            }
        }

        if (count($residue) >= 2) {
            $groups[] = ['medoid' => null, 'members' => $residue, 'anchors' => []];
        } elseif ($residue !== []) {
            $straggler  = $residue[0];
            $bestGroup  = null;
            $bestTether = -1.0;
            foreach ($groups as $gi => $group) {
                if ($group['medoid'] === null) {
                    continue;
                }
                foreach ($group['members'] as $peer) {
                    $score = $adjacency[$straggler->id][$peer->id] ?? null;
                    if ($score !== null && $score > $bestTether) {
                        $bestTether = $score;
                        $bestGroup  = $gi;
                    }
                }
            }
            // Unreachable by the residue invariant above (a residue
            // member's neighbours all live in surviving groups — an edge
            // to another residue would have kept both in the remaining
            // graph). If it ever fires the partition assumption broke, so
            // fail loud instead of silently dropping a block out of the
            // coverage (emittedBlocks cannot notice it: it only gates
            // X-bucket re-adds).
            if ($bestGroup === null) {
                throw new \LogicException(sprintf(
                    'medoid-ball split: lone residue block %s has no accepted edge into any surviving group; partition invariant broken.',
                    $straggler->id,
                ));
            }
            $groups[$bestGroup]['members'][]                  = $straggler;
            $groups[$bestGroup]['anchors'][$straggler->id]    = $bestTether;
        }

        $memberToGroup = [];
        foreach ($groups as $gi => $group) {
            foreach ($group['members'] as $m) {
                $memberToGroup[$m->id] = $gi;
            }
        }
        $groupPairs = array_fill(0, count($groups), []);
        foreach ($component->pairs as $pair) {
            $ga = $memberToGroup[$pair['blockA']] ?? null;
            $gb = $memberToGroup[$pair['blockB']] ?? null;
            if ($ga !== null && $ga === $gb) {
                $groupPairs[$ga][] = $pair;
            }
        }

        $clusters = [];
        foreach ($groups as $gi => $group) {
            $members = $group['members'];
            usort($members, static fn (Block $a, Block $b): int => strcmp($a->id, $b->id));

            if ($group['medoid'] === null) {
                $similarity = $legacyMinSim; // residue cluster: pre-split semantics
            } else {
                $similarity = 1.0;
                foreach ($group['anchors'] as $anchorScore) {
                    $similarity = min($similarity, $anchorScore);
                }
            }

            $h                = $members[0]->structuralHash;
            $allIdenticalHash = true;
            foreach ($members as $m) {
                if ($m->structuralHash !== $h) {
                    $allIdenticalHash = false;
                    break;
                }
            }
            if ($allIdenticalHash) {
                $similarity = 1.0;
            }

            $cluster          = new Cluster(
                id:         self::contentClusterId($members),
                members:    $members,
                similarity: $similarity,
                exact:      $allIdenticalHash,
            );
            $cluster->pairs    = $groupPairs[$gi];
            $cluster->medoidId = $group['medoid'];
            $clusters[]        = $cluster;
        }
        return $clusters;
    }

    /**
     * Deterministic medoid pick: highest count of still-remaining
     * neighbours; ties broken by structuralHash ascending (then scan
     * order for equal hashes). Id-arrival order never leaks in.
     *
     * @param array<string, Block>                $remaining
     * @param array<string, array<string, float>> $adjacency
     */
    private function pickMedoid(array $remaining, array $adjacency): string
    {
        $bestId   = '';
        $bestDeg  = -1;
        $bestHash = null;
        foreach ($remaining as $id => $block) {
            $deg = 0;
            foreach (array_keys($adjacency[$id]) as $neighbourId) {
                if (isset($remaining[$neighbourId])) {
                    $deg++;
                }
            }
            if ($deg > $bestDeg
                || ($deg === $bestDeg && ($bestHash === null || strcmp($block->structuralHash, $bestHash) < 0))
            ) {
                $bestId   = $id;
                $bestDeg  = $deg;
                $bestHash = $block->structuralHash;
            }
        }
        return $bestId;
    }

    /**
     * @return list<array{0:string,1:string,2:float,3:string}>
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
                // Exact hash pairs — structurally identical; tier is ExactHash.
                // These don't go through similarity scoring.
                $edges[] = [$aId, $bId, 1.0, MatchTier::ExactHash->value];
                continue;
            }
            $bagA = $a->ngramBag ?? [];
            $bagB = $b->ngramBag ?? [];
            $jac = $this->similarity->similarity($bagA, $bagB);
            if ($jac >= $this->similarityThreshold) {
                $tedSim = $this->tree->similarity($a->canonical, $b->canonical, $this->treeThreshold);
                if ($tedSim < $this->treeThreshold) continue;
                $edges[] = [$aId, $bId, min($jac, $tedSim), MatchTier::Jaccard->value];
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
                    $edges[] = [$aId, $bId, $cont, MatchTier::Containment->value];
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
                    $edges[] = [$aId, $bId, $irSim, MatchTier::Ir->value];
                    continue;
                }
            }
            // ML pair-tier (option 6). Last-chance scoring against
            // an external model. Returns null on transport failure
            // so unavailability never breaks the run.
            if ($this->mlPairClient !== null) {
                $mlScore = $this->mlPairClient->score($a, $b);
                if ($mlScore !== null && $mlScore['similarity'] >= $this->mlPairThreshold) {
                    $edges[] = [$aId, $bId, $mlScore['similarity'], MatchTier::Ml->value];
                }
            }
        }
        return $edges;
    }

    /**
     * Build pairs list for exact-hash clusters (Phase 1 hash buckets).
     *
     * Exact-hash clusters don't go through similarity scoring, so pairs
     * are tracked as empty. The "first wins" tier for exact clones is
     * ExactHash but since no scoring phase runs for these, we simply
     * return an empty array.
     *
     * @param list<Block> $blocks
     * @return list<array{blockA: string, blockB: string, matchTier: string, matchScore: float}>
     */
    private function buildExactPairs(array $blocks): array
    {
        return [];
    }

    /**
     * Build pairs list from edge data for a cluster's members.
     *
     * Iterates over all unordered member pairs and looks up the edge
     * (if any) that connected them during scoring. Only pairs with a
     * recorded edge are included — intra-component edges that failed
     * scoring thresholds are not present in $edgeMap.
     *
     * @param list<Block> $members
     * @param array<string, array{sim: float, tier: string}> $edgeMap
     * @return list<array{blockA: string, blockB: string, matchTier: string, matchScore: float}>
     */
    private function buildPairsFromEdges(array $members, array $edgeMap): array
    {
        $pairs = [];
        $count = count($members);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $aId = $members[$i]->id;
                $bId = $members[$j]->id;
                $key = strcmp($aId, $bId) < 0 ? $aId . '|' . $bId : $bId . '|' . $aId;
                if (!isset($edgeMap[$key])) continue;
                $pairs[] = [
                    'blockA'     => $aId,
                    'blockB'     => $bId,
                    'matchTier'  => $edgeMap[$key]['tier'],
                    'matchScore' => $edgeMap[$key]['sim'],
                ];
            }
        }
        return $pairs;
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
