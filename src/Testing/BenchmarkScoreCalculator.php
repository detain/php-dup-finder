<?php
declare(strict_types=1);

namespace Phpdup\Testing;

/**
 * Computes precision / recall for a duplicate-detection tool against
 * a known ground-truth manifest.
 *
 * A "cluster" here is the canonical set of (file, start_line, end_line)
 * triples a tool reported as duplicates of one another. Two clusters
 * match (one is a true positive of the other) when their normalized
 * member sets overlap by at least {@see self::OVERLAP_FLOOR} on
 * Jaccard, after collapsing line-range fuzz of {@see self::LINE_TOLERANCE}.
 *
 * Fuzz rule: two members match when the file is equal and BOTH endpoints
 * lie within ±LINE_TOLERANCE of their counterpart (the advertised ±2-line
 * rule). Matching is one-to-one — a maximum bipartite pairing over the
 * unique members — so the intersection can neither silently vanish
 * (bucket-straddle loss) nor inflate (one member counted for several).
 *
 * Used both by the bench harness (`bench/score.php`) and by
 * `tests/Unit/Bench/BenchmarkScoreCalculatorTest.php`.
 */
final class BenchmarkScoreCalculator
{
    public const LINE_TOLERANCE = 2;
    public const OVERLAP_FLOOR  = 0.6;

    /**
     * @param list<array{file:string, start:int, end:int}> $a
     * @param list<array{file:string, start:int, end:int}> $b
     */
    public function jaccardOnMembers(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        $aMembers = self::uniqueMembers($a);
        $bMembers = self::uniqueMembers($b);
        $intersection = self::maxToleranceMatching($aMembers, $bMembers);
        $union = count($aMembers) + count($bMembers) - $intersection;
        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * @param list<list<array{file:string, start:int, end:int}>> $reported
     * @param list<list<array{file:string, start:int, end:int}>> $groundTruth
     * @return array{precision:float, recall:float, f1:float, tp:int, fp:int, fn:int}
     */
    public function score(array $reported, array $groundTruth): array
    {
        $matched = [];
        $tp = 0;
        foreach ($reported as $r) {
            foreach ($groundTruth as $idx => $g) {
                if (isset($matched[$idx])) {
                    continue;
                }
                if ($this->jaccardOnMembers($r, $g) >= self::OVERLAP_FLOOR) {
                    $matched[$idx] = true;
                    $tp++;
                    break;
                }
            }
        }
        $fp = max(0, count($reported) - $tp);
        $fn = max(0, count($groundTruth) - $tp);
        $precision = count($reported) === 0 ? 0.0 : (float)$tp / (float)count($reported);
        $recall    = count($groundTruth) === 0 ? 0.0 : (float)$tp / (float)count($groundTruth);
        $sum = $precision + $recall;
        $f1 = $sum <= 0.0 ? 0.0 : (2.0 * $precision * $recall) / $sum;
        return [
            'precision' => $precision,
            'recall'    => $recall,
            'f1'        => $f1,
            'tp'        => $tp,
            'fp'        => $fp,
            'fn'        => $fn,
        ];
    }

    /**
     * Exact member key — a member is one (file, start, end) triple; the
     * ±tolerance rule lives in {@see self::withinTolerance()}, never in
     * the key. Quantizing the key re-introduces straddle loss: floor()
     * buckets drop pairs exactly LINE_TOLERANCE apart (99 vs 101 at tol 2)
     * while bucket-set intersection would inflate (98 vs 102 share a
     * bucket yet are 4 apart).
     *
     * @param array{file:string, start:int, end:int} $m
     */
    private static function exactKey(array $m): string
    {
        return $m['file'] . ':' . $m['start'] . '-' . $m['end'];
    }

    /**
     * @param list<array{file:string, start:int, end:int}> $members
     * @return list<array{file:string, start:int, end:int}>
     */
    private static function uniqueMembers(array $members): array
    {
        $seen = [];
        $unique = [];
        foreach ($members as $m) {
            $k = self::exactKey($m);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $unique[] = $m;
        }
        return $unique;
    }

    /**
     * The advertised ±LINE_TOLERANCE rule: same file, both endpoints
     * within tolerance of their counterpart.
     *
     * @param array{file:string, start:int, end:int} $x
     * @param array{file:string, start:int, end:int} $y
     */
    private static function withinTolerance(array $x, array $y): bool
    {
        $tol = self::LINE_TOLERANCE;
        return $x['file'] === $y['file']
            && abs($x['start'] - $y['start']) <= $tol
            && abs($x['end'] - $y['end']) <= $tol;
    }

    /**
     * Maximum bipartite matching (Kuhn's augmenting-path algorithm) under
     * the tolerance predicate. Clusters carry a handful of members, so
     * O(L·R) scans per augmentation is well inside budget; the pairing is
     * deterministic (fixed left/right order, first-found augmenting path).
     *
     * @param list<array{file:string, start:int, end:int}> $left
     * @param list<array{file:string, start:int, end:int}> $right
     */
    private static function maxToleranceMatching(array $left, array $right): int
    {
        /** @var array<int, int|null> $owner right index -> matched left index */
        $owner = array_fill(0, count($right), null);
        $matches = 0;
        foreach (array_keys($left) as $li) {
            /** @var array<int, bool> $seen right indices probed by this search */
            $seen = [];
            if (self::augment((int) $li, $left, $right, $owner, $seen)) {
                $matches++;
            }
        }
        return $matches;
    }

    /**
     * @param list<array{file:string, start:int, end:int}> $left
     * @param list<array{file:string, start:int, end:int}> $right
     * @param array<int, int|null> $owner
     * @param array<int, bool> $seen
     */
    private static function augment(int $li, array $left, array $right, array &$owner, array &$seen): bool
    {
        foreach (array_keys($right) as $rj) {
            if (isset($seen[$rj]) || !self::withinTolerance($left[$li], $right[$rj])) {
                continue;
            }
            $seen[$rj] = true;
            $current = $owner[$rj];
            if ($current === null || self::augment($current, $left, $right, $owner, $seen)) {
                $owner[$rj] = $li;
                return true;
            }
        }
        return false;
    }
}
