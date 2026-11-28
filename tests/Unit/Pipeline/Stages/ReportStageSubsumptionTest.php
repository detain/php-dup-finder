<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 6.3 — ReportStage wiring of the subsumption fold. Placement is
 * the corrected home (improve_logic_info.md §6.3): pre-Ranker, after
 * the 1.4 split which already ran upstream. Demand posture: armed ONLY
 * on the idiom_mining knob (no new key); the default run must pass the
 * fold-input set straight to the Ranker untouched, which is what keeps
 * the frozen goldens byte-identical.
 *
 * Hermetic: hand-built clusters (the reporter/stage-test law — no
 * pipeline run), two files, inner families pairing outer families.
 */
final class ReportStageSubsumptionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-63-stage-' . uniqid();
        mkdir($this->tmp . '/cache', 0o775, true);
        mkdir($this->tmp . '/repo/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testArmedRunFoldsSubsumedFamilyOutOfRankAndReport(): void
    {
        [$a, $b] = $this->pair();

        $report = $this->runStage([$a, $b], armed: true);

        $this->assertSame(['A'], array_map(static fn(Cluster $c) => $c->id, $report->clusters),
            'report maximal clones only: B never reaches the payload');
        $this->assertSame(['B'], $a->subsumes);
        $this->assertSame('A', $b->subsumedBy);

        $payload = (new JsonReporter())->build($report);
        $this->assertSame(1, $payload['summary']['clusters'], 'counts do not multiply');
        // A's removable lines cover B's span already: 91+91 union x 1/2.
        // The OFF run below measures the SAME number for A — the fold
        // changes A's rank position not at all (rank unchanged vs truth);
        // it only removes B's double-count (impact 11) from the totals.
        $this->assertSame(91, $payload['clusters'][0]['impact']);
        $this->assertSame(91, $payload['summary']['total_impact'],
            'total_impact stops inflating by the subsumed family');
        $this->assertSame(['B'], $payload['clusters'][0]['subsumes']);
    }

    public function testDefaultRunNeverArmsTheFold(): void
    {
        [$a, $b] = $this->pair();

        $report = $this->runStage([$a, $b], armed: false);

        $this->assertSameCanonical(['A', 'B'], array_map(static fn(Cluster $c) => $c->id, $report->clusters));
        $this->assertSame([], $a->subsumes, 'unarmed runs never touch the annotation fields');
        $this->assertNull($b->subsumedBy);

        $payload = (new JsonReporter())->build($report);
        $this->assertSame(2, $payload['summary']['clusters']);
        // The rank-unchanged-vs-truth half of the proof: the SAME outer
        // family scores the SAME 91 removable lines with the fold off —
        // the fold removes B's double-count (total 91+11), never A's
        // position or quantity.
        $this->assertSame('A', $payload['clusters'][0]['id']);
        $this->assertSame(91, $payload['clusters'][0]['impact']);
        $this->assertSame(102, $payload['summary']['total_impact'],
            'unarmed totals inflate by the subsumed family (the number the fold removes)');
        foreach ($payload['clusters'] as $cluster) {
            $this->assertArrayNotHasKey('subsumes', $cluster,
                'the conditional key cannot appear on a default payload — frozen-golden guarantee');
        }
    }

    public function testArmedFoldIsIndependentOfTheCensusCarrier(): void
    {
        // The fold arms on the knob alone; with no SequenceIndex on
        // state (hand-driven stage) the census carrier stays null and
        // the summary keeps its unarmed key set — proving the fold did
        // not smuggle idiom_census into the payload.
        [$a, $b] = $this->pair();

        $report  = $this->runStage([$a, $b], armed: true);
        $payload = (new JsonReporter())->build($report);

        $this->assertNull($report->idiomCensus);
        $this->assertArrayNotHasKey('idiom_census', $payload['summary']);
    }

    /** @return array{Cluster, Cluster} */
    private function pair(): array
    {
        $a = new Cluster('A', [
            $this->member($this->tmp . '/repo/src/F1.php', 10, 100),
            $this->member($this->tmp . '/repo/src/F2.php', 10, 100),
        ], 1.0, false);
        $b = new Cluster('B', [
            $this->member($this->tmp . '/repo/src/F1.php', 20, 30),
            $this->member($this->tmp . '/repo/src/F2.php', 20, 30),
        ], 1.0, false);
        return [$a, $b];
    }

    /**
     * @param list<Cluster> $clusters
     */
    private function runStage(array $clusters, bool $armed): \Phpdup\Reporting\Report
    {
        // Fresh objects per run — the fold and the Ranker mutate in place.
        $kinds = $armed ? [BlockExtractor::FRAGMENT_KIND, 'method'] : [];
        $config = new Config(
            paths:            [$this->tmp . '/repo'],
            exclude:          [],
            cacheDir:         $this->tmp . '/cache',
            minClusterImpact: 0,
            allowedKinds:     $kinds,
            idiomMining:      $armed,
        );
        $state = new PipelineState($config);
        $state->clusters = $clusters;
        $state->files    = ['F1.php', 'F2.php'];
        $state->blocks   = $clusters[0]->members;

        (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

        $this->assertNotNull($state->report, 'the reporting stage must have built the report');
        return $state->report;
    }

    private function member(string $file, int $start, int $end): Block
    {
        return new Block(
            file:      $file,
            range:     new LineRange($start, $end),
            kind:      'method',
            namespace: 'Ns',
            class:     null,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
    }

    /** @param list<string> $expected */
    private function assertSameCanonical(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $message);
    }
}
