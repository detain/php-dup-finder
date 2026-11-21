<?php
declare(strict_types=1);

namespace Phpdup\Testing;

/**
 * Computes precision / recall for a duplicate-detection tool against
 * a known ground-truth manifest of FAMILIES (one ground-truth cluster
 * per planted clone family).
 *
 * A "cluster" here is the canonical set of (file, start_line, end_line)
 * triples a tool reported as duplicates of one another. Extractors emit
 * nested blocks (a duplicated method AND its duplicated inner if /
 * foreach / try), so scoring is containment-aware in BOTH directions:
 *
 *  - Precision side: a reported cluster is a true positive when at
 *    least {@see self::OVERLAP_FLOOR} of its members are explainable by
 *    ONE family — each member either matches a family occurrence within
 *    the fuzz rule, is nested inside one, or covers one. A nested
 *    genuine detection (family's inner if-block reported as its own
 *    cluster) is therefore NOT an unconditional false positive; a
 *    cluster spanning two families, or touching unplanted code, is.
 *  - Recall side: a family is detected when ONE reported cluster
 *    tolerance-matches at least {@see self::OVERLAP_FLOOR} of its
 *    members one-to-one (the planted occurrences themselves).
 *
 * Fuzz rule (the advertised ±2-line rule): two members match when the
 * file is equal and BOTH endpoints lie within ±LINE_TOLERANCE of their
 * counterpart. Matching is one-to-one — a maximum bipartite pairing over
 * the unique members — so the intersection can neither silently vanish
 * (bucket-straddle loss) nor inflate (one member counted for several).
 * Quantized floor(line/tolerance) bucketing is NOT used: it dropped
 * pairs exactly tolerance apart and was parity-random at ±1.
 *
 * Pair-grained competitor output (phpcpd/jscpd emit clone PAIRS, not
 * families) is converted with {@see self::closureFromPairs()} — the
 * transitive closure over shared members — before it reaches score().
 *
 * Used by the bench harness (`bench/run.php` + `bench/score.php`) and by
 * `tests/Unit/Bench/BenchmarkScoreCalculatorTest.php`.
 */
final class BenchmarkScoreCalculator
{
    public const LINE_TOLERANCE = 2;
    public const OVERLAP_FLOOR  = 0.6;

    /**
     * Symmetric member-set Jaccard under the ±LINE_TOLERANCE one-to-one
     * rule. Retained as the public set-similarity helper (used by bench
     * tooling and unit pins); {@see self::score()} itself judges reported
     * clusters by containment-aware explainability and families by
     * one-to-one tolerance coverage, not by this ratio.
     *
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
     * Family-granular scoring against a ground truth of planted clone
     * families (one cluster per family).
     *
     * - `tp` counts reported clusters explained by a single family
     *   (containment-aware; nested genuine detections are true positives,
     *   cross-family lumps and noise are not).
     * - `recall` is the fraction of ground-truth families detected — a
     *   family counts once even if several clusters cover it, and one
     *   mega-cluster covering every family yields full recall with the
     *   merge penalty paid honestly on the precision side.
     *
     * @param list<list<array{file:string, start:int, end:int}>> $reported
     * @param list<list<array{file:string, start:int, end:int}>> $groundTruth
     * @return array{precision:float, recall:float, f1:float, tp:int, fp:int, fn:int}
     */
    public function score(array $reported, array $groundTruth): array
    {
        $reportedSets = [];
        foreach ($reported as $r) {
            $reportedSets[] = self::uniqueMembers($r);
        }
        $gtSets = [];
        foreach ($groundTruth as $g) {
            $gtSets[] = self::uniqueMembers($g);
        }

        $tp = 0;
        foreach ($reportedSets as $r) {
            foreach ($gtSets as $g) {
                if (self::isExplainedBy($r, $g)) {
                    $tp++;
                    break;
                }
            }
        }
        $detected = 0;
        foreach ($gtSets as $g) {
            foreach ($reportedSets as $r) {
                if (self::detectsFamily($r, $g)) {
                    $detected++;
                    break;
                }
            }
        }

        $fp = max(0, count($reported) - $tp);
        $fn = max(0, count($groundTruth) - $detected);
        $precision = count($reported) === 0 ? 0.0 : (float)$tp / (float)count($reported);
        $recall    = count($groundTruth) === 0 ? 0.0 : (float)$detected / (float)count($groundTruth);
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
     * Transitive closure of pair-grained tool output: the connected
     * components of the "shares a member occurrence" relation.
     *
     * Competitors that report clone PAIRS (phpcpd's PMD XML lists each
     * duplicated fragment pair; jscpd's report is flat pairwise) feed a
     * 5-member family as up to 10 two-member rows. Scored raw, no row
     * reaches OVERLAP_FLOOR of the family's members, so a fully correct
     * pairwise tool lands at recall 0 — the structural zeroing the
     * benchmark used to publish. Closing pairs into families before
     * scoring fixes that (a closed family scores like a cluster-grained
     * tool's). Sharing is exact (file, start, end) equality — fragments
     * are emitted with stable bounds per file by every current tool;
     * ±tolerance chaining happens inside score(), not here.
     *
     * Deterministic: components are ordered by their earliest-seen
     * member, members within a component likewise. Idempotent on input
     * that is already clustered. Groups without members are dropped.
     *
     * @param list<list<array{file:string, start:int, end:int}>> $groups
     * @return list<list<array{file:string, start:int, end:int}>>
     */
    public static function closureFromPairs(array $groups): array
    {
        /** @var array<string, string> $parent member key -> parent key */
        $parent = [];
        /** @var array<string, int> $weight root key -> component size */
        $weight = [];
        /** @var array<string, int> $order member key -> first-seen ordinal */
        $order = [];
        /** @var array<string, array{file:string, start:int, end:int}> $member */
        $member = [];

        $ordinal = 0;
        foreach ($groups as $group) {
            /** @var list<string> $keys */
            $keys = [];
            foreach ($group as $m) {
                $k = self::exactKey($m);
                if (!isset($parent[$k])) {
                    $parent[$k] = $k;
                    $weight[$k] = 1;
                    $order[$k]  = $ordinal++;
                    $member[$k] = $m;
                }
                $keys[] = $k;
            }
            $count = count($keys);
            for ($i = 1; $i < $count; $i++) {
                $rootA = self::ufFind($keys[0], $parent);
                $rootB = self::ufFind($keys[$i], $parent);
                if ($rootA === $rootB) {
                    continue;
                }
                if ($weight[$rootA] < $weight[$rootB]) {
                    [$rootA, $rootB] = [$rootB, $rootA];
                }
                $parent[$rootB] = $rootA;
                $weight[$rootA] += $weight[$rootB];
            }
        }

        /** @var array<string, list<array{int, array{file:string, start:int, end:int}}>> $components */
        $components = [];
        foreach ($member as $k => $m) {
            $components[self::ufFind($k, $parent)][] = [$order[$k], $m];
        }

        $closed = [];
        foreach ($components as $parts) {
            usort($parts, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $firstOrdinal = $parts[0][0];
            $closed[] = [$firstOrdinal, array_map(static fn(array $p): array => $p[1], $parts)];
        }
        usort($closed, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn(array $c): array => $c[1], $closed);
    }

    /**
     * @param array<string, string> $parent
     */
    private static function ufFind(string $key, array &$parent): string
    {
        $root = $key;
        while ($parent[$root] !== $root) {
            $root = $parent[$root];
        }
        while ($parent[$key] !== $root) {
            $next = $parent[$key];
            $parent[$key] = $root;
            $key = $next;
        }
        return $root;
    }

    /**
     * A reported cluster is explained by a family when at least
     * OVERLAP_FLOOR of its (unique) members are each explainable by some
     * member of that family — and at least one is, so an empty cluster
     * can never be a true positive.
     *
     * @param list<array{file:string, start:int, end:int}> $r unique reported members
     * @param list<array{file:string, start:int, end:int}> $g unique family members
     */
    private static function isExplainedBy(array $r, array $g): bool
    {
        if ($r === [] || $g === []) {
            return false;
        }
        $explained = 0;
        foreach ($r as $x) {
            foreach ($g as $y) {
                if (self::explainable($x, $y)) {
                    $explained++;
                    break;
                }
            }
        }
        return self::meetsFloor($explained, count($r));
    }

    /**
     * A family is detected by a reported cluster when the cluster
     * tolerance-matches at least OVERLAP_FLOOR of the family's planted
     * occurrences, one-to-one under the ±LINE_TOLERANCE rule.
     *
     * @param list<array{file:string, start:int, end:int}> $r
     * @param list<array{file:string, start:int, end:int}> $g
     */
    private static function detectsFamily(array $r, array $g): bool
    {
        if ($r === [] || $g === []) {
            return false;
        }
        return self::meetsFloor(self::maxToleranceMatching($r, $g), count($g));
    }

    /**
     * Can occurrence $x be attributed to planted occurrence $y? Same
     * file, and either nested within y's range (±tolerance) or covering
     * it (±tolerance). Exact endpoint agreement satisfies both.
     *
     * @param array{file:string, start:int, end:int} $x
     * @param array{file:string, start:int, end:int} $y
     */
    private static function explainable(array $x, array $y): bool
    {
        if ($x['file'] !== $y['file']) {
            return false;
        }
        $tol = self::LINE_TOLERANCE;
        $nestedIn = $x['start'] >= $y['start'] - $tol && $x['end'] <= $y['end'] + $tol;
        $covers   = $x['start'] <= $y['start'] + $tol && $x['end'] >= $y['end'] - $tol;
        return $nestedIn || $covers;
    }

    private static function meetsFloor(int $count, int $of): bool
    {
        return $count >= 1 && (float)$count >= (self::OVERLAP_FLOOR * $of) - 1e-9;
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
