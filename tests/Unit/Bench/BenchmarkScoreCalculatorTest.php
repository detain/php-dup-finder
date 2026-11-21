<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Bench;

use PHPUnit\Framework\TestCase;
use Phpdup\Testing\BenchmarkScoreCalculator;

final class BenchmarkScoreCalculatorTest extends TestCase
{
    public function testPerfectMatchYieldsPrecisionAndRecallOne(): void
    {
        $gt = [
            [
                ['file' => 'a.php', 'start' => 10, 'end' => 20],
                ['file' => 'b.php', 'start' => 30, 'end' => 40],
            ],
        ];
        $calc = new BenchmarkScoreCalculator();
        $score = $calc->score($gt, $gt);
        self::assertSame(1.0, $score['precision']);
        self::assertSame(1.0, $score['recall']);
        self::assertSame(1.0, $score['f1']);
        self::assertSame(1, $score['tp']);
        self::assertSame(0, $score['fp']);
        self::assertSame(0, $score['fn']);
    }

    public function testNoOverlapIsAllFalsePositives(): void
    {
        $reported = [[
            ['file' => 'a.php', 'start' => 10, 'end' => 20],
        ]];
        $gt = [[
            ['file' => 'b.php', 'start' => 100, 'end' => 200],
        ]];
        $score = (new BenchmarkScoreCalculator())->score($reported, $gt);
        self::assertSame(0.0, $score['precision']);
        self::assertSame(0.0, $score['recall']);
        self::assertSame(1, $score['fp']);
        self::assertSame(1, $score['fn']);
    }

    public function testLineToleranceCollapsesNearMatches(): void
    {
        // Reported is off by 1 line vs ground truth — should still match
        // because LINE_TOLERANCE is 2.
        $reported = [[
            ['file' => 'a.php', 'start' => 11, 'end' => 21],
            ['file' => 'b.php', 'start' => 31, 'end' => 41],
        ]];
        $gt = [[
            ['file' => 'a.php', 'start' => 10, 'end' => 20],
            ['file' => 'b.php', 'start' => 30, 'end' => 40],
        ]];
        $score = (new BenchmarkScoreCalculator())->score($reported, $gt);
        self::assertGreaterThan(0.0, $score['recall']);
        self::assertSame(1, $score['tp']);
    }

    public function testPartialOverlapBelowFloorMisses(): void
    {
        // 1-out-of-3 overlap = 33% Jaccard, below the 0.6 floor.
        $reported = [[
            ['file' => 'a.php', 'start' => 10, 'end' => 20],
            ['file' => 'x.php', 'start' => 50, 'end' => 60],
            ['file' => 'y.php', 'start' => 70, 'end' => 80],
        ]];
        $gt = [[
            ['file' => 'a.php', 'start' => 10, 'end' => 20],
            ['file' => 'b.php', 'start' => 30, 'end' => 40],
            ['file' => 'c.php', 'start' => 90, 'end' => 99],
        ]];
        $score = (new BenchmarkScoreCalculator())->score($reported, $gt);
        self::assertSame(0, $score['tp']);
        self::assertSame(1, $score['fp']);
        self::assertSame(1, $score['fn']);
    }

    public function testF1IsHarmonicMean(): void
    {
        // Two ground-truth clusters; only one is reported correctly,
        // plus one false positive. precision=0.5, recall=0.5,
        // f1=0.5.
        $matchingMembers = [
            ['file' => 'a.php', 'start' => 10, 'end' => 20],
            ['file' => 'b.php', 'start' => 30, 'end' => 40],
        ];
        $reported = [
            $matchingMembers,
            [['file' => 'fake.php', 'start' => 1, 'end' => 5]],
        ];
        $gt = [
            $matchingMembers,
            [['file' => 'other.php', 'start' => 1, 'end' => 5]],
        ];
        $score = (new BenchmarkScoreCalculator())->score($reported, $gt);
        self::assertSame(0.5, $score['precision']);
        self::assertSame(0.5, $score['recall']);
        self::assertSame(0.5, $score['f1']);
    }

    public function testEmptyReportYieldsZeroPrecisionRecall(): void
    {
        $gt = [[
            ['file' => 'a.php', 'start' => 10, 'end' => 20],
        ]];
        $score = (new BenchmarkScoreCalculator())->score([], $gt);
        self::assertSame(0.0, $score['precision']);
        self::assertSame(0.0, $score['recall']);
        self::assertSame(1, $score['fn']);
    }

    public function testEmptyGroundTruthIsNoOp(): void
    {
        $score = (new BenchmarkScoreCalculator())->score([], []);
        self::assertSame(0.0, $score['precision']);
        self::assertSame(0.0, $score['recall']);
        self::assertSame(0, $score['tp']);
    }

    public function testJaccardOnEmptyMembersSets(): void
    {
        $calc = new BenchmarkScoreCalculator();
        self::assertSame(1.0, $calc->jaccardOnMembers([], []));
    }

    public function testStraddlingPairExactlyAtToleranceMatches(): void
    {
        // The regression: lines 99 vs 101 at LINE_TOLERANCE=2 are exactly
        // tolerance apart, but the old floor(line/tol) bucketing put them
        // in buckets 49 and 50 and the match silently vanished.
        $calc = new BenchmarkScoreCalculator();
        self::assertSame(1.0, $calc->jaccardOnMembers(
            [['file' => 'a.php', 'start' => 99, 'end' => 99]],
            [['file' => 'a.php', 'start' => 101, 'end' => 101]]
        ));
    }

    public function testPairBeyondToleranceDoesNotMatch(): void
    {
        // No bucket inflation: 98 vs 102 is 4 apart (> tol 2) yet shares a
        // candidate bucket under a bucket-set intersection scheme — it
        // must NOT match. Same for 99 vs 102 (3 apart).
        $calc = new BenchmarkScoreCalculator();
        self::assertSame(0.0, $calc->jaccardOnMembers(
            [['file' => 'a.php', 'start' => 98, 'end' => 98]],
            [['file' => 'a.php', 'start' => 102, 'end' => 102]]
        ));
        self::assertSame(0.0, $calc->jaccardOnMembers(
            [['file' => 'a.php', 'start' => 99, 'end' => 99]],
            [['file' => 'a.php', 'start' => 102, 'end' => 102]]
        ));
    }

    public function testToleranceMustHoldOnBothEndpoints(): void
    {
        // Start lines agree within tolerance but end lines drift by 3 —
        // the member pair must not match.
        $calc = new BenchmarkScoreCalculator();
        self::assertSame(0.0, $calc->jaccardOnMembers(
            [['file' => 'a.php', 'start' => 10, 'end' => 20]],
            [['file' => 'a.php', 'start' => 11, 'end' => 23]]
        ));
    }

    public function testMatchingIsOneToOneSoIntersectionCannotInflate(): void
    {
        // Two unique reported members both sit within tolerance of one
        // ground-truth member: the intersection counts the pairing once,
        // never twice. 2 left + 1 right - 1 matched = union of 2.
        $calc = new BenchmarkScoreCalculator();
        self::assertSame(0.5, $calc->jaccardOnMembers(
            [
                ['file' => 'a.php', 'start' => 10, 'end' => 10],
                ['file' => 'a.php', 'start' => 11, 'end' => 11],
            ],
            [['file' => 'a.php', 'start' => 10, 'end' => 10]]
        ));
    }

    public function testStraddlingClusterStillScoresTruePositive(): void
    {
        // Whole-cluster end-to-end proof of the fix: a two-member reported
        // cluster off by exactly 2 lines matches ground truth at Jaccard
        // 1.0. Under the old bucketing zero members shared a key (both
        // straddled a boundary), Jaccard fell to 0.0 and the true positive
        // was lost — the under-reporting described in the finding.
        $reported = [[
            ['file' => 'a.php', 'start' => 99, 'end' => 99],
            ['file' => 'b.php', 'start' => 99, 'end' => 99],
        ]];
        $gt = [[
            ['file' => 'a.php', 'start' => 101, 'end' => 101],
            ['file' => 'b.php', 'start' => 101, 'end' => 101],
        ]];
        $score = (new BenchmarkScoreCalculator())->score($reported, $gt);
        self::assertSame(1, $score['tp']);
        self::assertSame(1.0, $score['recall']);
        self::assertSame(1.0, $score['precision']);
    }

    public function testPerfectMultiFamilyDetectorScoresOne(): void
    {
        // Acceptance (a): one ground-truth cluster per family, a detector
        // that reports each family exactly — the perfect detector scores
        // 1.0, not the old scorer's 0.50 mega-cluster artifact.
        $gt = self::families(2, 3);
        $score = (new BenchmarkScoreCalculator())->score($gt, $gt);
        self::assertSame(1.0, $score['precision']);
        self::assertSame(1.0, $score['recall']);
        self::assertSame(1.0, $score['f1']);
        self::assertSame(2, $score['tp']);
        self::assertSame(0, $score['fp']);
        self::assertSame(0, $score['fn']);
    }

    public function testNestedGenuineDetectionIsNotAFalsePositive(): void
    {
        // Regression pin for finding 009: extractors report nested blocks,
        // so a correct detector surfaces the family's methods AND their
        // duplicated inner if-blocks as separate clusters. The inner-if
        // cluster's members are nested inside the planted method ranges —
        // they are genuine detections, not unconditional FPs.
        $family = [
            self::member('a.php', 8, 16),
            self::member('b.php', 8, 16),
        ];
        $nested = [
            self::member('a.php', 10, 12),
            self::member('b.php', 10, 12),
        ];
        $score = (new BenchmarkScoreCalculator())->score([$family, $nested], [$family]);
        self::assertSame(2, $score['tp']);
        self::assertSame(0, $score['fp']);
        self::assertSame(0, $score['fn']);
        self::assertSame(1.0, $score['precision']);
        self::assertSame(1.0, $score['recall']);
    }

    public function testCrossFamilyLumpPaysPrecisionAndKeepsRecall(): void
    {
        // A mega-cluster covering two planted families DETECTS both (full
        // recall — it is not zeroed) but explains only half of its own
        // members per family, so the merge penalty lands honestly on the
        // precision side instead of corrupting the recall figure.
        $gt = self::families(2, 3);
        $lump = array_merge($gt[0], $gt[1]);
        $score = (new BenchmarkScoreCalculator())->score([$lump], $gt);
        self::assertSame(1.0, $score['recall']);
        self::assertSame(0, $score['fn']);
        self::assertSame(0, $score['tp']);
        self::assertSame(0.0, $score['precision']);
    }

    public function testClosureTurnsPairwiseCompetitorIntoAFamily(): void
    {
        // Acceptance (c): phpcpd/jscpd-style output is one row per clone
        // PAIR. Scored raw, no 2-member row reaches the 0.6 floor of a
        // 5-member family — recall is structurally 0.0 for a tool with
        // every pair right. Transitive closure over shared members
        // rebuilds the family, and the same tool then scores recall 1.0.
        $files = ['g0.php', 'g1.php', 'g2.php', 'g3.php', 'g4.php'];
        $members = [];
        foreach ($files as $f) {
            $members[] = self::member($f, 8, 16);
        }
        $pairs = [];
        for ($i = 0, $n = count($members); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $pairs[] = [$members[$i], $members[$j]];
            }
        }
        self::assertCount(10, $pairs);

        $calc = new BenchmarkScoreCalculator();
        $raw = $calc->score($pairs, [$members]);
        self::assertSame(0.0, $raw['recall']);

        $closed = BenchmarkScoreCalculator::closureFromPairs($pairs);
        self::assertCount(1, $closed);
        self::assertCount(5, $closed[0]);
        $viaClosure = $calc->score($closed, [$members]);
        self::assertSame(1.0, $viaClosure['recall']);
        self::assertSame(1.0, $viaClosure['precision']);
    }

    public function testClosureIsIdempotentDeterministicAndKeepsFamiliesApart(): void
    {
        $pairs = [
            [self::member('g0.php', 8, 16), self::member('g1.php', 8, 16)],
            [self::member('g1.php', 8, 16), self::member('g2.php', 8, 16)],
            [self::member('h0.php', 5, 9), self::member('h1.php', 5, 9)],
            [],
            [self::member('g2.php', 8, 16), self::member('g0.php', 8, 16)],
        ];
        $closed = BenchmarkScoreCalculator::closureFromPairs($pairs);
        // Memberless groups dropped; two components; g-family has 3 members.
        self::assertCount(2, $closed);
        self::assertSame(['g0.php', 'g1.php', 'g2.php'], self::componentFiles($closed[0]));
        self::assertSame(['h0.php', 'h1.php'], self::componentFiles($closed[1]));

        // Idempotence: closing already-closed clusters changes nothing.
        self::assertSame($closed, BenchmarkScoreCalculator::closureFromPairs($closed));

        // Group-order permutation invariance (components are grouping-
        // invariant; only their emission order depends on first-seen input).
        $shuffled = [
            [self::member('h1.php', 5, 9), self::member('h0.php', 5, 9)],
            [self::member('g1.php', 8, 16), self::member('g2.php', 8, 16)],
            [self::member('g0.php', 8, 16), self::member('g2.php', 8, 16)],
            [],
            [self::member('g1.php', 8, 16), self::member('g0.php', 8, 16)],
        ];
        self::assertSame(
            self::canonicalize($closed),
            self::canonicalize(BenchmarkScoreCalculator::closureFromPairs($shuffled))
        );
    }

    public function testInjectedNegativeControlsPinPrecisionAtHalf(): void
    {
        // Acceptance (d): three perfectly-detected families plus three
        // noise clusters planted on files with no true clone class must
        // land precision at exactly 0.5 — a scorer that lets noise through
        // as explainable would silently report 1.0.
        $gt = self::families(3, 3);
        $noise = [
            [self::member('n0.php', 3, 9)],
            [self::member('n1.php', 4, 10)],
            [self::member('n2.php', 5, 11)],
        ];
        $score = (new BenchmarkScoreCalculator())->score([...$gt, ...$noise], $gt);
        self::assertSame(3, $score['tp']);
        self::assertSame(3, $score['fp']);
        self::assertSame(1.0, $score['recall']);
        self::assertSame(0.5, $score['precision']);
        self::assertLessThanOrEqual(0.5, $score['precision']);
    }

    public function testAdvertisedToleranceRuleIsPlusMinusTwoLines(): void
    {
        // Acceptance (e): the ±LINE_TOLERANCE rule as advertised — a pair
        // matches iff BOTH endpoints are within 2 lines. The old
        // floor(line/2) bucketing scored this table 1.0/0.0/0.0/0.0/1.0…
        // parity-randomly: shift +1 matched (same bucket), −1 start scored
        // 0.0, ±2 NEVER matched even though within tolerance (PDB-04).
        $calc = new BenchmarkScoreCalculator();
        $base = [self::member('x.php', 100, 110)];
        for ($delta = -5; $delta <= 5; $delta++) {
            $shifted = [self::member('x.php', 100 + $delta, 110 + $delta)];
            self::assertSame(
                abs($delta) <= 2 ? 1.0 : 0.0,
                $calc->jaccardOnMembers($shifted, $base),
                "both-endpoints shift {$delta} must " . (abs($delta) <= 2 ? 'match' : 'not match')
            );
        }
        // One-sided shifts: a single endpoint inside tolerance still matches.
        self::assertSame(1.0, $calc->jaccardOnMembers([self::member('x.php', 99, 110)], $base));
        self::assertSame(1.0, $calc->jaccardOnMembers([self::member('x.php', 102, 110)], $base));
        self::assertSame(1.0, $calc->jaccardOnMembers([self::member('x.php', 100, 108)], $base));
        self::assertSame(0.0, $calc->jaccardOnMembers([self::member('x.php', 100, 107)], $base));
        self::assertSame(0.0, $calc->jaccardOnMembers([self::member('x.php', 97, 110)], $base));
    }

    public function testCommittedSyntheticGroundTruthIsFamilyGranular(): void
    {
        // Pins the on-disk benchmark artifact shape (finding 053): one
        // cluster per planted family, corpus-relative paths (no machine
        // absolute /home/ paths — the portability the old GT lacked), and
        // the negative_files key declaring files with no true clone class.
        $file = dirname(__DIR__, 3) . '/bench/corpora/synthetic-fuzz/.ground-truth.json';
        if (!is_file($file)) {
            $this->markTestSkipped('synthetic corpus not generated in this tree');
        }
        $gt = json_decode((string)file_get_contents($file), true);
        self::assertIsArray($gt);
        self::assertArrayHasKey('clusters', $gt);
        self::assertGreaterThanOrEqual(4, count($gt['clusters']));
        $seen = [];
        foreach ($gt['clusters'] as $idx => $family) {
            self::assertGreaterThanOrEqual(2, count($family), "family {$idx} must have >=2 members");
            foreach ($family as $member) {
                self::assertSame(basename($member['file']), $member['file'], 'GT paths must be corpus-relative');
                $key = $member['file'] . ':' . $member['start'];
                self::assertArrayNotHasKey($key, $seen, 'families must be disjoint');
                $seen[$key] = true;
            }
        }
        self::assertArrayHasKey('negative_files', $gt);
        self::assertGreaterThanOrEqual(4, count($gt['negative_files']));
        foreach ($gt['negative_files'] as $neg) {
            self::assertSame(basename($neg), $neg, 'negative control paths must be corpus-relative');
            foreach ($seen as $planted => $_) {
                self::assertFalse(str_starts_with($planted, $neg . ':'), $neg . ' must not be planted');
            }
        }
    }

    /**
     * @return list<list<array{file:string, start:int, end:int}>>
     */
    private static function families(int $count, int $members): array
    {
        $gt = [];
        for ($f = 0; $f < $count; $f++) {
            $family = [];
            for ($i = 0; $i < $members; $i++) {
                $family[] = self::member(sprintf('f%d_%03d.php', $f, $i), 8, 16);
            }
            $gt[] = $family;
        }
        return $gt;
    }

    /**
     * @return array{file:string, start:int, end:int}
     */
    private static function member(string $file, int $start, int $end): array
    {
        return ['file' => $file, 'start' => $start, 'end' => $end];
    }

    /**
     * @param list<array{file:string, start:int, end:int}> $component
     * @return list<string>
     */
    private static function componentFiles(array $component): array
    {
        return array_map(static fn(array $m): string => $m['file'], $component);
    }

    /**
     * @param list<list<array{file:string, start:int, end:int}>> $components
     * @return list<list<string>>
     */
    private static function canonicalize(array $components): array
    {
        $out = [];
        foreach ($components as $c) {
            $rows = array_map(
                static fn(array $m): string => sprintf('%s:%d:%d', $m['file'], $m['start'], $m['end']),
                $c
            );
            sort($rows);
            $out[] = $rows;
        }
        sort($out);
        return $out;
    }
}
