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
 *   - Clusterer fills $id, $members, $similarity (min pairwise).
 *   - AntiUnifier fills $generalizedAst, $holes.
 *   - ParameterSynthesizer + SignatureBuilder fill $signature.
 *   - PatternRecognizer fills $patternTags.
 *   - Ranker fills $impact, $confidence and sorts.
 */
final class Cluster
{
    public string $id;
    /** @var list<Block> */
    public array $members;
    public float $similarity = 1.0;
    public bool $exact = false;

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

    public function totalLines(): int
    {
        $sum = 0;
        foreach ($this->members as $m) {
            $sum += $m->range->lines();
        }
        return $sum;
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
