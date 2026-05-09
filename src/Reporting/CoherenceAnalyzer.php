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
 * Cost is O(k²) per cluster where k = member count. Negligible for
 * typical clusters (k ≤ 20); skipped entirely for k ≤ 2 (no room for
 * an outlier).
 */
final class CoherenceAnalyzer
{
    private readonly JaccardSimilarity $jaccard;

    public function __construct(
        private readonly float $outlierThreshold = 0.6,
    ) {
        $this->jaccard = new JaccardSimilarity();
        if ($outlierThreshold < 0.0 || $outlierThreshold > 1.0) {
            throw new \InvalidArgumentException("outlierThreshold out of range");
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
        $outliers = [];
        for ($i = 0; $i < $k; $i++) {
            $sum = 0.0;
            $cnt = 0;
            for ($j = 0; $j < $k; $j++) {
                if ($i === $j) continue;
                if (!$bags[$i] || !$bags[$j]) continue;
                $sum += $this->jaccard->similarity($bags[$i], $bags[$j]);
                $cnt++;
            }
            $mean = $cnt > 0 ? $sum / $cnt : 1.0;
            if ($mean < $this->outlierThreshold) {
                $outliers[] = $i;
            }
        }
        return $outliers;
    }
}
