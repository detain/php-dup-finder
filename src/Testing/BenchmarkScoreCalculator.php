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
 * Used both by the bench harness (`bench/score.php`) and by
 * `tests/Unit/Bench/ScoreCalculatorTest.php`.
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
        $aKeys = array_map(fn(array $m): string => $this->key($m), $a);
        $bKeys = array_map(fn(array $m): string => $this->key($m), $b);
        $aSet = array_fill_keys($aKeys, true);
        $bSet = array_fill_keys($bKeys, true);
        $intersection = 0;
        foreach (array_keys($aSet) as $k) {
            if (isset($bSet[$k])) {
                $intersection++;
            }
        }
        $union = count($aSet) + count($bSet) - $intersection;
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

    /** @param array{file:string, start:int, end:int} $m */
    private function key(array $m): string
    {
        // Quantize line numbers to a tolerance-sized bucket so two
        // tools that disagree by ~1-2 lines on a block boundary still
        // collapse onto the same key.
        $tol = self::LINE_TOLERANCE;
        $startBucket = (int)floor($m['start'] / max(1, $tol));
        $endBucket   = (int)floor($m['end']   / max(1, $tol));
        return $m['file'] . ':' . $startBucket . '-' . $endBucket;
    }
}
