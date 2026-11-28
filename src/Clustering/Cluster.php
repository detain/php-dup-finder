<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

use PhpParser\Node;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;

/**
 * A group of structurally-similar blocks plus the refactor hypothesis
 * that ties them together.
 *
 * Populated incrementally:
 *   - Clusterer fills $id (content-derived, §5e), $members, $similarity
 *     (weakest member-vs-medoid edge after the item-1.4 medoid-ball
 *     split; legacy weakest-edge when split is off), and $medoidId.
 *   - AntiUnifier fills $generalizedAst, $holes.
 *   - ParameterSynthesizer + SignatureBuilder fill $signature.
 *   - PatternRecognizer fills $patternTags.
 *   - Ranker fills $impact (duplicated lines), $confidence, $safety
 *     and the composite $score, and sorts.
 */
final class Cluster
{
    public string $id;
    /** @var list<Block> */
    public array $members;
    public float $similarity = 1.0;
    public bool $exact = false;

    /**
     * Block id of this cluster's medoid — the hub member every other
     * member was edge-adjacent to when the cluster was formed by
     * {@see Clusterer}'s post-union-find medoid-ball split (item 1.4,
     * ruling §5b). Null for residue clusters (kept under pre-split
     * semantics) and for clusters formed while the split is off.
     */
    public ?string $medoidId = null;

    public ?Node $generalizedAst = null;

    /** @var list<Hole> */
    public array $holes = [];

    /**
     * holeId => path list, populated by AntiUnifier after unify().
     * Used by ApplyExtractor to locate hole positions in generalizedAst.
     *
     * @var array<string, list<int|string>>
     */
    public array $holePaths = [];

    public ?string $signature = null;

    /** @var list<string> */
    public array $patternTags = [];

    public int $impact = 0;
    public float $confidence = 0.0;

    /**
     * Composite ranking score (item 6.2, improve_logic.md §6.2 letter
     * "Score = removable lines × spread × divergence risk × confidence").
     * Populated by {@see \Phpdup\Reporting\Ranker} alongside impact;
     * readable via `--sort=score`. Distinct from $impact, which stays
     * the pure duplicated-LINES quantity (removable share) the
     * min_cluster_impact floor gates on — per the owner exec ruling
     * 2026-11-28 the lines quantity lives on $impact itself and no
     * dual quantity is shipped. 0.0 until ranked.
     */
    public float $score = 0.0;

    /**
     * Intra-cluster pairs with their match tier and score.
     *
     * Populated by {@see Clusterer} during cluster formation.
     * Exact-hash clusters have empty arrays since those pairs were
     * handled in Phase 1 hash buckets and did not go through scoring.
     *
     * @var list<array{blockA: string, blockB: string, matchTier: string, matchScore: float}>
     */
    public array $pairs = [];
    /**
     * Refactoring-safety score in [0, 1]. Populated by
     * {@see \Phpdup\Reporting\SafetyScorer} after Ranker scoring.
     * Reporters use this for filtering (--min-safety) and display.
     */
    public float $safety = 0.0;

    /**
     * Member indices flagged by {@see \Phpdup\Reporting\CoherenceAnalyzer}
     * as outliers — ones whose mean pairwise similarity to the rest of
     * the cluster is below the analyzer's threshold. Reporters surface
     * an "outlier" badge alongside these members so reviewers can
     * triage them out.
     *
     * @var list<int>
     */
    public array $outlierMemberIds = [];

    /**
     * Architectural findings emitted by registered
     * {@see \Phpdup\Architecture\ArchitecturalAnalyzer}s.
     *
     * @var list<\Phpdup\Architecture\Finding>
     */
    public array $architecturalFindings = [];

    /**
     * Item 6.3 subsumption fold (improve_logic.md §6.3: "when every
     * member of cluster B lies inside a member of cluster A, in the
     * same pairing, fold B under A. Report maximal clones only."):
     * ids of the clone families folded directly under THIS cluster —
     * populated only by {@see \Phpdup\Reporting\SubsumptionFolder} on
     * idiom_mining-armed runs (demand posture: default runs never grow
     * this, so the frozen goldens keep their key sets). Deterministically
     * sorted by id. The subsumed families are annotated, not counted:
     * they leave the rank/report payload and ride here instead, so
     * inner-clone families cannot multiply the cluster count or the
     * duplicated-line totals their outer witness already covers.
     *
     * @var list<string>
     */
    public array $subsumes = [];

    /**
     * Item 6.3: the maximal ancestor this cluster was folded under —
     * set on SUBSUMED clusters only (null on every reported maximal
     * cluster and on all default runs). Chains fold to the maximal
     * ancestor through this field; the survivor's {@see self::$subsumes}
     * names the folded ids.
     */
    public ?string $subsumedBy = null;

    /**
     * @param list<Block> $members
     */
    public function __construct(string $id, array $members, float $similarity, bool $exact)
    {
        $this->id = $id;
        $this->members = $members;
        $this->similarity = $similarity;
        $this->exact = $exact;
    }

    public function size(): int
    {
        return count($this->members);
    }

    /**
     * Distinct source lines covered by the members — the per-file
     * UNION of member ranges, never the sum (item 6.2). Overlapping
     * or nested members (two blocks of the same class, a block and a
     * window packed over it) must not double-count the shared lines;
     * touching-but-not-overlapping ranges merge only when their
     * coverage is contiguous, which leaves totals unchanged, so the
     * merge test uses start <= end + 1.
     */
    public function totalLines(): int
    {
        return self::unionLineSpan($this->members);
    }

    /**
     * Interval-union line count over an arbitrary member list.
     *
     * @param list<Block> $members
     */
    public static function unionLineSpan(array $members): int
    {
        $byFile = [];
        foreach ($members as $m) {
            $byFile[$m->file][] = [$m->range->start, $m->range->end];
        }
        $total = 0;
        foreach ($byFile as $ranges) {
            usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $curStart = $ranges[0][0];
            $curEnd   = $ranges[0][1];
            $n = \count($ranges);
            for ($i = 1; $i < $n; $i++) {
                [$start, $end] = $ranges[$i];
                if ($curEnd >= $start - 1) {
                    $curEnd = max($curEnd, $end);
                    continue;
                }
                $total += $curEnd - $curStart + 1;
                $curStart = $start;
                $curEnd   = $end;
            }
            $total += $curEnd - $curStart + 1;
        }
        return $total;
    }

    public function avgBlockSize(): float
    {
        if (!$this->members) return 0.0;
        $sum = 0;
        foreach ($this->members as $m) {
            $sum += $m->size;
        }
        return $sum / count($this->members);
    }
}
