<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\NgramInvertedIndex;
use Phpdup\Ml\PairScorer;
use Phpdup\Parallel\WorkerPool;
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
        // Cache directory for NgramInvertedIndex disk cache. Enables persistent
        // caching across runs so the inverted index is only rebuilt when block
        // content changes. Empty string = no disk cache (use APCu if available).
        private readonly string $ngramCacheDir = '',
    ) {
        $this->containment = new ContainmentSimilarity();
    }

    /**
     * Generate the candidate-pair stream (a_id, b_id) for parallel scoring.
     *
     * Used when the caller wants to dispatch pair scoring to a worker
     * pool: it builds the inverted index, walks each block's candidates,
     * and yields each pair exactly once (canonicalized so a_id < b_id).
     *
     * @param callable(int, int, int): mixed|null $onEnumerationProgress Called periodically
     *        during enumeration with (blockNum, totalBlocks, pairCount). The callback
     *        may invoke `yield null` to allow TUI refresh during long enumeration.
     * @param array<string,bool>|null $exactDuplicateIds Set of block IDs that are in
     *        exact-duplicate hash buckets (structuralHash appears 2+ times). These are
     *        skipped during enumeration since they are already clustered in phase 1.
     * @return \Generator<array{0:string,1:string}>
     */
    public function generateCandidatePairs(BlockIndex $index, ?OutputInterface $output = null, ?callable $onEnumerationProgress = null, ?array $exactDuplicateIds = null): \Generator
    {
        $totalBlocks = $index->size();
        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: building inverted index for %d blocks [%s]', $totalBlocks, MemoryDebug::getMemoryUsage()));
        }

        $inverted = new NgramInvertedIndex($this->ngramCacheDir);
        $invertedProgressCallback = static function (int $indexed, int $total) use ($output): void {
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG && $indexed % 5000 === 0) {
                $output->writeln(sprintf('ngram-index: indexed %d / %d blocks [%s]', $indexed, $total, MemoryDebug::getMemoryUsage()));
            }
        };
        $inverted->build($index, $invertedProgressCallback);

        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: inverted index built [%s]', MemoryDebug::getMemoryUsage()));
            $output->writeln(sprintf('ngram-index: enumerating candidate pairs for %d blocks', $totalBlocks));
        }

        // Get integer ID mapping from the inverted index for efficient pair key generation
        $stringToInt = $inverted->getStringToIntMap();
        $intToString = $inverted->getIntToStringMap();

        // Convert exact duplicate IDs to integer IDs for the skip set
        $exactDuplicateIntIds = null;
        if ($exactDuplicateIds !== null) {
            $exactDuplicateIntIds = [];
            foreach ($exactDuplicateIds as $id => $_) {
                if (isset($stringToInt[$id])) {
                    $exactDuplicateIntIds[$stringToInt[$id]] = true;
                }
            }
        }

        $seen = [];
        $blockNum = 0;
        $pairCount = 0;
        $lastProgressReport = 0;
        $progressInterval = max(1, (int)($totalBlocks / 50)); // Report ~50 times during enumeration
        $lastDebugOutput = microtime(true);
        foreach ($index->all() as $a) {
            $blockNum++;
            $intA = $stringToInt[$a->id] ?? null;
            if ($intA === null) {
                continue;
            }
            // candidatesFor() returns integer candidate IDs when asIntIds=true
            $candidates = $inverted->candidatesFor($a, $this->maxDocumentFrequency, null, $exactDuplicateIntIds, true);
            foreach ($candidates as $intB) {
                // Use integer pair key: ($minInt << 32) | $maxInt — fits in 64-bit integer
                $intKey = $intA < $intB ? ($intA << 32) | $intB : ($intB << 32) | $intA;
                if (isset($seen[$intKey])) {
                    continue;
                }
                $seen[$intKey] = true;
                $pairCount++;
                // Convert integer IDs back to strings when yielding
                yield [$a->id, $intToString[$intB]];
            }

            // Progress callback every progressInterval blocks
            if ($onEnumerationProgress !== null && $blockNum - $lastProgressReport >= $progressInterval) {
                $lastProgressReport = $blockNum;
                $onEnumerationProgress($blockNum, $totalBlocks, $pairCount);
            }

            // Debug output every 5 seconds regardless of callback
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $now = microtime(true);
                if ($now - $lastDebugOutput >= 5.0) {
                    $lastDebugOutput = $now;
                    $output->writeln(sprintf('ngram-index: processed %d / %d blocks, %d candidates found [%s]', $blockNum, $totalBlocks, $pairCount, MemoryDebug::getMemoryUsage()));
                }
            }
        }

        // Final progress callback if we haven't reported at the end
        if ($onEnumerationProgress !== null && $lastProgressReport !== $blockNum) {
            $onEnumerationProgress($blockNum, $totalBlocks, $pairCount);
        }

        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: enumeration complete, %d candidate pairs found [%s]', $pairCount, MemoryDebug::getMemoryUsage()));
        }
    }

    /**
     * Parallel variant of generateCandidatePairs() that distributes block
     * enumeration across multiple workers using pcntl_fork.
     *
     * The NgramInvertedIndex is built in the parent process before forking.
     * After fork, each child inherits the index via copy-on-write (COW)
     * memory sharing, avoiding the need to serialize/deserialize the index.
     *
     * @param callable(int, int, int): mixed|null $onEnumerationProgress
     * @param array<string,bool>|null $exactDuplicateIds
     * @return \Generator<array{0:string,1:string}>
     */
    public function generateCandidatePairsParallel(
        BlockIndex $index,
        int $workers,
        ?OutputInterface $output = null,
        ?callable $onEnumerationProgress = null,
        ?array $exactDuplicateIds = null,
    ): \Generator {
        $totalBlocks = $index->size();
        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: building inverted index for %d blocks [%s]', $totalBlocks, MemoryDebug::getMemoryUsage()));
        }

        // Build the inverted index once in the parent. After fork, children
        // inherit this via COW - the postings arrays are shared read-only
        // across processes without duplication.
        $inverted = new NgramInvertedIndex($this->ngramCacheDir);
        $invertedProgressCallback = static function (int $indexed, int $total) use ($output): void {
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG && $indexed % 5000 === 0) {
                $output->writeln(sprintf('ngram-index: indexed %d / %d blocks [%s]', $indexed, $total, MemoryDebug::getMemoryUsage()));
            }
        };
        $inverted->build($index, $invertedProgressCallback);

        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: parallel enumeration using %d workers [%s]', $workers, MemoryDebug::getMemoryUsage()));
        }

        // Get ID mappings and exact duplicate skip set
        $stringToInt = $inverted->getStringToIntMap();
        $intToString = $inverted->getIntToStringMap();
        $exactDuplicateIntIds = null;
        if ($exactDuplicateIds !== null) {
            $exactDuplicateIntIds = [];
            foreach ($exactDuplicateIds as $id => $_) {
                if (isset($stringToInt[$id])) {
                    $exactDuplicateIntIds[$stringToInt[$id]] = true;
                }
            }
        }

        // Partition blocks by integer ID for even distribution across workers
        $allBlocks = $index->all();
        $blockChunks = array_chunk($allBlocks, max(1, (int)ceil(count($allBlocks) / $workers)));

        $pool = new WorkerPool($workers);
        $pairCount = 0;
        $blockNum = 0;
        $lastProgressReport = 0;
        $progressInterval = max(1, (int)($totalBlocks / 50));
        $lastDebugOutput = microtime(true);

        // Task closure: each worker processes its assigned blocks and yields pairs
        $task = static function (array $blocks) use ($inverted, $stringToInt, $intToString, $exactDuplicateIntIds): \Generator {
            $localSeen = [];
            foreach ($blocks as $a) {
                $intA = $stringToInt[$a->id] ?? null;
                if ($intA === null) {
                    continue;
                }
                $candidates = $inverted->candidatesFor($a, 0.01, null, $exactDuplicateIntIds, true);
                foreach ($candidates as $intB) {
                    $intKey = $intA < $intB ? ($intA << 32) | $intB : ($intB << 32) | $intA;
                    if (isset($localSeen[$intKey])) {
                        continue;
                    }
                    $localSeen[$intKey] = true;
                    yield [$a->id, $intToString[$intB]];
                }
            }
        };

        foreach ($pool->runStreaming($blockChunks, $task) as $pair) {
            $pairCount++;
            $blockNum++;

            // Progress callback
            if ($onEnumerationProgress !== null && $blockNum - $lastProgressReport >= $progressInterval) {
                $lastProgressReport = $blockNum;
                $onEnumerationProgress($blockNum, $totalBlocks, $pairCount);
            }

            // Debug output every 5 seconds
            if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $now = microtime(true);
                if ($now - $lastDebugOutput >= 5.0) {
                    $lastDebugOutput = $now;
                    $output->writeln(sprintf('ngram-index: processed %d / %d blocks, %d candidates found [%s]', $blockNum, $totalBlocks, $pairCount, MemoryDebug::getMemoryUsage()));
                }
            }

            yield $pair;
        }

        // Final progress callback
        if ($onEnumerationProgress !== null && $lastProgressReport !== $blockNum) {
            $onEnumerationProgress($blockNum, $totalBlocks, $pairCount);
        }

        if ($output !== null && $output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('ngram-index: parallel enumeration complete, %d candidate pairs found [%s]', $pairCount, MemoryDebug::getMemoryUsage()));
        }
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
            // Use 64-bit integer key instead of string concatenation for O(1) lookup
            $key = $uf->canonicalPairKey($aId, $bId);
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
                        // Use integer pair key for O(1) lookup — avoids strcmp() overhead
                        $key = $uf->canonicalPairKey($a, $b);
                        if ($key !== 0 && isset($edgeMap[$key]) && $edgeMap[$key] < $minSim) {
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
        // Build set of block IDs that are in exact-duplicate hash buckets.
        // These will be skipped during ngram enumeration since they are already
        // clustered in phase 1 and don't need to go through the expensive ngram pipeline.
        $exactDuplicateIds = [];
        foreach ($index->hashBuckets() as $hash => $blocks) {
            if (count($blocks) >= 2) {
                foreach ($blocks as $b) {
                    $exactDuplicateIds[$b->id] = true;
                }
            }
        }

        $pairs = $this->generateCandidatePairs($index, null, null, $exactDuplicateIds);
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
    /** @var array<int,int> */
    private array $parent = [];
    /** @var array<int,int> */
    private array $rank = [];
    /** @var array<string,int> */
    private array $idToInt = [];
    /** @var array<int,string> */
    private array $intToId = [];
    private int $nextIntId = 0;

    public function add(string $x): void
    {
        if (!isset($this->idToInt[$x])) {
            $intId = $this->nextIntId++;
            $this->idToInt[$x] = $intId;
            $this->intToId[$intId] = $x;
            $this->parent[$intId] = $intId;
            $this->rank[$intId] = 0;
        }
    }

    public function find(string $x): string
    {
        $intId = $this->idToInt[$x] ?? null;
        if ($intId === null) {
            return $x;
        }
        while ($this->parent[$intId] !== $intId) {
            $this->parent[$intId] = $this->parent[$this->parent[$intId]];
            $intId = $this->parent[$intId];
        }
        return $this->intToId[$intId];
    }

    public function union(string $x, string $y): void
    {
        $intX = $this->idToInt[$x] ?? null;
        $intY = $this->idToInt[$y] ?? null;
        if ($intX === null || $intY === null) {
            return;
        }
        $rx = $this->findInt($intX);
        $ry = $this->findInt($intY);
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

    private function findInt(int $x): int
    {
        while ($this->parent[$x] !== $x) {
            $this->parent[$x] = $this->parent[$this->parent[$x]];
            $x = $this->parent[$x];
        }
        return $x;
    }

    /**
     * Generate a canonical 64-bit integer key for a pair of string IDs.
     * Uses the same ordering convention as generateCandidatePairs:
     * the smaller integer ID goes in the low 32 bits.
     * This avoids expensive strcmp() calls when looking up edge pairs.
     *
     * @return int 64-bit canonical pair key, or 0 if either ID is unknown
     */
    public function canonicalPairKey(string $idA, string $idB): int
    {
        $intA = $this->idToInt[$idA] ?? null;
        $intB = $this->idToInt[$idB] ?? null;
        if ($intA === null || $intB === null) {
            return 0;
        }
        return $intA < $intB ? ($intA << 32) | $intB : ($intB << 32) | $intA;
    }
}
