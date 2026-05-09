<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\NgramInvertedIndex;
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
 * `exactOnly` skips phase 2 entirely.
 */
final class Clusterer
{
    public function __construct(
        private readonly JaccardSimilarity $similarity,
        private readonly TreeEditDistance $tree,
        private readonly float $similarityThreshold = 0.80,
        private readonly float $treeThreshold = 0.85,
        private readonly float $maxDocumentFrequency = 0.01,
        private readonly bool $exactOnly = false,
    ) {
    }

    /**
     * @return list<Cluster>
     */
    public function cluster(BlockIndex $index): array
    {
        $clusters = [];
        $exactlyClustered = []; // block id → bool

        // Phase 1: hash buckets
        foreach ($index->hashBuckets() as $hash => $blocks) {
            if (count($blocks) < 2) continue;
            $cluster = new Cluster(
                id: 'X' . substr($hash, 0, 8),
                members: $blocks,
                similarity: 1.0,
                exact: true,
            );
            $clusters[] = $cluster;
            foreach ($blocks as $b) {
                $exactlyClustered[$b->id] = true;
            }
        }

        if ($this->exactOnly) {
            return $clusters;
        }

        // Phase 2: near-duplicate edges via union-find
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $uf = new UnionFind();
        foreach ($index->all() as $b) {
            $uf->add($b->id);
        }

        $edges = []; // canonical "a|b" => sim
        foreach ($index->all() as $a) {
            if (isset($exactlyClustered[$a->id])) {
                // already in an exact cluster — but it may also bridge
                // to *other* near-duplicate blocks; allow it.
            }
            if ($a->ngramBag === null) continue;
            $candidates = $inverted->candidatesFor($a, $this->maxDocumentFrequency);
            foreach ($candidates as $bid) {
                $b = $index->get($bid);
                if ($b === null) continue;
                $key = strcmp($a->id, $b->id) < 0 ? $a->id . '|' . $b->id : $b->id . '|' . $a->id;
                if (isset($edges[$key])) continue;
                if ($a->structuralHash === $b->structuralHash) {
                    // identical canonical — already linked via exact bucket;
                    // still merge components for any near-dup bridging.
                    $uf->union($a->id, $b->id);
                    $edges[$key] = 1.0;
                    continue;
                }
                $jac = $this->similarity->similarity($a->ngramBag, $b->ngramBag ?? []);
                if ($jac < $this->similarityThreshold) continue;
                $ted = $this->tree->similarity($a->canonical, $b->canonical, $this->treeThreshold);
                if ($ted < $this->treeThreshold) continue;
                $sim = min($jac, $ted);
                $uf->union($a->id, $b->id);
                $edges[$key] = $sim;
            }
        }

        // Build near-duplicate clusters by component
        $byComponent = [];
        foreach ($index->all() as $b) {
            $root = $uf->find($b->id);
            $byComponent[$root][] = $b;
        }

        // Replace any phase-1 cluster whose component now spans a
        // larger near-duplicate group, by rebuilding clusters from
        // components and skipping exact-only singletons.
        $merged = [];
        $emittedBlocks = [];
        foreach ($byComponent as $root => $members) {
            if (count($members) < 2) continue;
            // similarity = min pairwise Jaccard (cheap proxy)
            $minSim = 1.0;
            $allIdenticalHash = true;
            $h = $members[0]->structuralHash;
            foreach ($members as $m) {
                if ($m->structuralHash !== $h) {
                    $allIdenticalHash = false;
                }
            }
            if (!$allIdenticalHash) {
                // sample minimum from edges within component
                for ($i = 0; $i < count($members); $i++) {
                    for ($j = $i + 1; $j < count($members); $j++) {
                        $key = strcmp($members[$i]->id, $members[$j]->id) < 0
                            ? $members[$i]->id . '|' . $members[$j]->id
                            : $members[$j]->id . '|' . $members[$i]->id;
                        if (isset($edges[$key])) {
                            if ($edges[$key] < $minSim) {
                                $minSim = $edges[$key];
                            }
                        }
                    }
                }
            }
            $cluster = new Cluster(
                id: 'C' . substr(md5($root), 0, 8),
                members: array_values($members),
                similarity: $allIdenticalHash ? 1.0 : $minSim,
                exact: $allIdenticalHash,
            );
            $merged[] = $cluster;
            foreach ($members as $m) {
                $emittedBlocks[$m->id] = true;
            }
        }

        // Drop phase-1 clusters whose blocks are all in $merged components
        // (they're already represented). Keep ones not subsumed.
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
