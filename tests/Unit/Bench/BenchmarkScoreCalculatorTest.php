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
}
