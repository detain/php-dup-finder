<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Git;

use Phpdup\Architecture\Finding;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Git\ClusterHistoryAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 r1 pins for the annotate leg.
 *
 * m2 — the doc's "feed 4.1 where possible" sentence: when a cluster
 * already carries a DriftAnalyzer finding and the history verdict is a
 * lone fix, the history-lone-fix message must explicitly corroborate the
 * structural drift analysis; a twin cluster without drift findings must
 * NOT grow that sentence (no unfounded cross-references).
 *
 * r1-C1 — the misuse guard survives the zero-candidate cure: annotate()
 * before correlate() stays loud, after correlate() (even over zero
 * candidates) it never throws.
 *
 * clusterFacts/correlated are written through reflection on purpose:
 * the alternative is driving the whole git sidecar to manufacture two
 * fixed verdict shapes, and the verdict law is already pinned on the
 * pure classify() surface.
 */
final class ClusterHistoryAnnotateTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function analyzer(array $overrides = []): ClusterHistoryAnalyzer
    {
        return new ClusterHistoryAnalyzer(new Config(
            paths:   ['/x'],
            exclude: [],
            history: true,
        ));
    }

    /**
     * @param array<int,array{file:string,lone:bool}> $memberSpecs
     */
    private function seedFacts(ClusterHistoryAnalyzer $analyzer, string $id, string $verdict, array $memberSpecs): void
    {
        $members = [];
        foreach ($memberSpecs as $spec) {
            $members[] = [
                'file' => $spec['file'],
                'last' => ['sha' => str_repeat('a', 40), 'time' => 1_700_000_000, 'msg' => 'seed'],
                'lone' => $spec['lone']
                    ? ['sha' => str_repeat('b', 40), 'time' => 1_760_000_000, 'subject' => 'Fix off-by-one in total threshold', 'fix' => true]
                    : null,
            ];
        }
        $ref  = new \ReflectionProperty(ClusterHistoryAnalyzer::class, 'clusterFacts');
        $all  = $ref->getValue($analyzer);
        $all  = is_array($all) ? $all : [];
        $all[$id] = [
            'verdict'  => $verdict,
            'reason'   => null,
            'members'  => $members,
            'coChange' => [],
            'activity' => true,
        ];
        $ref->setValue($analyzer, $all);

        $latch = new \ReflectionProperty(ClusterHistoryAnalyzer::class, 'correlated');
        $latch->setValue($analyzer, true);
    }

    public function testLoneFixOnDriftClusterCorroboratesStructuralAnalysis(): void
    {
        $analyzer = $this->analyzer();
        $this->seedFacts($analyzer, 'c-drift', 'recent_lone_fix', [
            ['file' => 'old.php', 'lone' => false],
            ['file' => 'new.php', 'lone' => true],
        ]);

        $cluster                 = new Cluster('c-drift', [], 1.0, false);
        $cluster->architecturalFindings[] = new Finding(
            analyzer: 'DriftAnalyzer',
            code:     'drift-missing-guard',
            message:  'twin guards the call, this one does not',
            severity: Finding::SEVERITY_WARNING,
        );

        $analyzer->annotateClusters([$cluster]);

        $codes = array_map(static fn (Finding $f): string => $f->code, $cluster->architecturalFindings);
        self::assertSame(['drift-missing-guard', 'history-lone-fix'], $codes, 'history annotates beside the drift finding, never replaces it');

        $hist = $cluster->architecturalFindings[1];
        self::assertSame(Finding::SEVERITY_WARNING, $hist->severity);
        self::assertStringContainsString('; corroborated by structural drift analysis (item 4.1)', $hist->message);
        self::assertStringContainsString('new.php', $hist->message);
    }

    public function testLoneFixOnCleanClusterCarriesNoCorroborationSentence(): void
    {
        $analyzer = $this->analyzer();
        $this->seedFacts($analyzer, 'c-clean', 'recent_lone_fix', [
            ['file' => 'old.php', 'lone' => false],
            ['file' => 'new.php', 'lone' => true],
        ]);

        $cluster = new Cluster('c-clean', [], 1.0, false);
        $analyzer->annotateClusters([$cluster]);

        self::assertCount(1, $cluster->architecturalFindings);
        self::assertSame('history-lone-fix', $cluster->architecturalFindings[0]->code);
        self::assertStringNotContainsString('corroborated', $cluster->architecturalFindings[0]->message);
    }

    public function testNonDriftArchitecturalFindingsDoNotTriggerCorroboration(): void
    {
        $analyzer = $this->analyzer();
        $this->seedFacts($analyzer, 'c-other', 'recent_lone_fix', [
            ['file' => 'old.php', 'lone' => false],
            ['file' => 'new.php', 'lone' => true],
        ]);

        $cluster                 = new Cluster('c-other', [], 1.0, false);
        $cluster->architecturalFindings[] = new Finding(
            analyzer: 'SolidAnalyzer',
            code:     'solid-srp',
            message:  'unrelated architectural note',
            severity: Finding::SEVERITY_NOTE,
        );

        $analyzer->annotateClusters([$cluster]);

        self::assertStringNotContainsString('corroborated', $cluster->architecturalFindings[1]->message);
    }

    public function testMisuseGuardSurvivesTheZeroCandidateCure(): void
    {
        // r1-C1: correlate over zero candidates…
        $analyzer = $this->analyzer();
        $history  = $analyzer->correlate([]);
        self::assertSame([], $history->rows, 'armed-but-nothing: an empty sidecar, not an exception');
        self::assertSame(0, $history->omitted);

        // …then annotate is a no-throw no-op (the latch proves the cure
        // path ran, not that the empty input hides an unfixed throw).
        $latch = new \ReflectionProperty(ClusterHistoryAnalyzer::class, 'correlated');
        self::assertTrue($latch->getValue($analyzer));
        $analyzer->annotateClusters([]);
        self::assertSame([], $history->notes, 'nothing to note when nothing was correlated');
    }

    public function testAnnotateBeforeCorrelateStillThrowsLoud(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('annotateClusters() called before correlate()');
        $this->analyzer()->annotateClusters([]);
    }
}
