<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Similarity\JaccardSimilarity;

/**
 * Per-cluster outlier detection.
 *
 * For each cluster, computes the pairwise Jaccard similarity matrix
 * over members' n-gram bags (already populated upstream) and flags any
 * member whose **mean pairwise similarity to the rest** falls below
 * a configurable threshold (default 0.6). Such a member is structurally
 * out of step with the cluster and is a strong candidate for human
 * review before applying the suggested abstraction.
 *
 * Cost is O(k²) per cluster where k = member count, in **unordered**
 * pairs: each symmetric pair is scored once and its value is credited
 * to both members' means, so k members with non-empty bags cost k·(k−1)/2
 * similarity calls, not k·(k−1). Skipped entirely for k ≤ 2 (no room for
 * an outlier).
 */
final class CoherenceAnalyzer
{
    private readonly JaccardSimilarity $jaccard;

    /**
     * Monotonic count of {@see JaccardSimilarity::similarity()} calls
     * made by {@see self::outliersFor()} across the process. Test seam
     * pinning the unordered-pair economy (finding output-027): the
     * delta for a cluster with k non-empty-bag members must be
     * k·(k−1)/2. Production code never reads it.
     */
    public static int $similarityCallCount = 0;

    public function __construct(
        private readonly float $outlierThreshold = 0.6,
    ) {
        $this->jaccard = new JaccardSimilarity();
        if (!is_finite($outlierThreshold) || $outlierThreshold < 0.0 || $outlierThreshold > 1.0) {
            throw new \InvalidArgumentException("outlierThreshold out of range (must be a finite number in [0, 1], got $outlierThreshold)");
        }
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<Cluster> the same clusters, mutated with outlierMemberIds
     */
    public function analyze(array $clusters): array
    {
        foreach ($clusters as $cluster) {
            $cluster->outlierMemberIds = $this->outliersFor($cluster);
        }
        return $clusters;
    }

    /** @return list<int> */
    private function outliersFor(Cluster $cluster): array
    {
        $k = $cluster->size();
        if ($k < 3) {
            return [];
        }
        $bags = [];
        foreach ($cluster->members as $idx => $m) {
            $bags[$idx] = $m->ngramBag ?? [];
        }
        /** @var list<float> $sums */
        $sums = array_fill(0, $k, 0.0);
        /** @var list<int> $counts */
        $counts = array_fill(0, $k, 0);
        for ($i = 0; $i < $k; $i++) {
            if (!$bags[$i]) {
                continue; // empty bag: this member scores nothing, as before
            }
            for ($j = $i + 1; $j < $k; $j++) {
                if (!$bags[$j]) {
                    continue;
                }
                self::$similarityCallCount++;
                $sim = $this->jaccard->similarity($bags[$i], $bags[$j]);
                // One score, both sides: Jaccard is exactly symmetric, so
                // crediting the single value to each member's mean is
                // bit-identical to the old per-direction recomputation.
                $sums[$i] += $sim;
                $sums[$j] += $sim;
                $counts[$i]++;
                $counts[$j]++;
            }
        }
        $outliers = [];
        for ($i = 0; $i < $k; $i++) {
            $mean = $counts[$i] > 0 ? $sums[$i] / $counts[$i] : 1.0;
            if ($mean < $this->outlierThreshold) {
                $outliers[] = $i;
            }
        }
        return $outliers;
    }
}
