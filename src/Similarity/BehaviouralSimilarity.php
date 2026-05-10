<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

use PhpParser\Node;
use Phpdup\Semantic\DataflowSummarizer;

/**
 * Type-4 (behavioural) similarity between two AST subtrees.
 *
 * Two blocks may be syntactically very different (e.g. switch vs
 * match, foreach vs array_reduce, recursion vs iteration) yet
 * compute the same observable I/O. BehaviouralSimilarity scores
 * them by overlap of their dataflow summaries:
 *
 *   - var-name intersection (with weight 1)
 *   - call-name multiset Jaccard (weight 2 — strongest signal)
 *   - return-shape Jaccard (weight 2)
 *   - matching side-effect flag (weight 1)
 *
 * The total is normalised to [0, 1]. Used as a Type-3 fallback
 * scorer in {@see \Phpdup\Clustering\Clusterer} when both Jaccard
 * and TED reject — gated behind a config flag because false-
 * positive risk is materially higher than for type-1/2/3.
 */
final class BehaviouralSimilarity
{
    public function __construct(
        private readonly DataflowSummarizer $summarizer = new DataflowSummarizer(),
    ) {
    }

    public function similarity(Node $a, Node $b): float
    {
        $sa = $this->summarizer->summarize($a);
        $sb = $this->summarizer->summarize($b);

        $w = 0.0;
        $w += $this->jaccardSet($sa['vars'], $sb['vars']);
        $w += 2.0 * $this->jaccardMultiset($sa['calls'], $sb['calls']);
        $w += 2.0 * $this->jaccardReturns($sa['returns'], $sb['returns']);
        $w += $sa['sideEffects'] === $sb['sideEffects'] ? 1.0 : 0.0;

        // Total possible weight is 1 + 2 + 2 + 1 = 6.
        return $w / 6.0;
    }

    /**
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    private function jaccardSet(array $a, array $b): float
    {
        if ($a === [] && $b === []) return 1.0;
        $i = 0;
        foreach ($a as $k => $_) if (isset($b[$k])) $i++;
        $u = count($a) + count($b) - $i;
        return $u > 0 ? $i / $u : 0.0;
    }

    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     */
    private function jaccardMultiset(array $a, array $b): float
    {
        if ($a === [] && $b === []) return 1.0;
        $i = 0;
        $u = 0;
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $k) {
            $ca = $a[$k] ?? 0;
            $cb = $b[$k] ?? 0;
            $i += min($ca, $cb);
            $u += max($ca, $cb);
        }
        return $u > 0 ? $i / $u : 0.0;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccardReturns(array $a, array $b): float
    {
        $sa = array_count_values($a);
        $sb = array_count_values($b);
        return $this->jaccardMultiset($sa, $sb);
    }
}
