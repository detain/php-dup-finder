<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 5.2 (W2b) — the ReportStage wiring of the vendored demotion:
 * armed only through the EXISTING --scope knob (no new door), members
 * restamped from this run's roots before the split (the 5.1 cache-replay
 * law extended), mixed clusters retained, and the unarmed path growing
 * nothing at all.
 */
final class ReportStageVendoredTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-52-stage-' . uniqid();
        mkdir($this->tmp . '/cache', 0o775, true);
        mkdir($this->tmp . '/repoA/src', 0o775, true);
        mkdir($this->tmp . '/repoA/vendor/acme/pkg', 0o775, true);
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

    public function testUnarmedScopeOpensNoSection(): void
    {
        $report = $this->runStage($this->corpus(), 'all');

        self::assertNull($report->vendoredDuplicates, 'scope=all is the unarmed tri-state leg');
        self::assertSameCanonical(['VEND', 'MIXED', 'CLEAN'], $this->ids($report));
    }

    public function testArmedScopeDemotesOnlyFullyVendoredClusters(): void
    {
        $report = $this->runStage($this->corpus(), 'repoA');

        self::assertSameCanonical(['MIXED', 'CLEAN'], $this->ids($report),
            'vendored<->vendored demotes; a fork-edited twin alongside first-party code stays');

        $section = $report->vendoredDuplicates;
        self::assertNotNull($section);
        self::assertSame(1, $section['clusters']);
        self::assertSame(0, $section['omitted']);
        self::assertCount(1, $section['rows']);
        self::assertSame('VEND', $section['rows'][0]['id']);
        self::assertSame(['vendor_dir'], $section['rows'][0]['signals']);
        self::assertSame(24, $section['lines'], 'union of the two 12-line members (identical ranges)');
    }

    public function testRestampRepairsStaleCachedMarks(): void
    {
        // The cluster-cache replay hazard (5.1 precedent): blobs
        // deserialize with the SAVING run's marks. Here the files are
        // plain first-party paths whose blocks wrongly carry TRUE —
        // the run-context restamp must unmark them before the split.
        $staleA = $this->member($this->tmp . '/repoA/src/Late1.php');
        $staleB = $this->member($this->tmp . '/repoA/src/Late2.php');
        $staleA->vendored = true;
        $staleB->vendored = true;

        $report = $this->runStage([new Cluster('STALE', [$staleA, $staleB], 0.9, false)], 'single');

        self::assertSameCanonical(['STALE'], $this->ids($report), 'a stale TRUE must not demote this run\'s first-party code');
        self::assertNotNull($report->vendoredDuplicates);
        self::assertSame(0, $report->vendoredDuplicates['clusters']);
        self::assertFalse($staleA->vendored, 'and the field is honestly rewritten, not just ignored');
    }

    public function testArmedRunOverCleanCorpusIsZeroShaped(): void
    {
        $report = $this->runStage([$this->cleanCluster()], 'repoA');

        self::assertSameCanonical(['CLEAN'], $this->ids($report));
        self::assertSame(
            ['clusters' => 0, 'lines' => 0, 'omitted' => 0, 'rows' => []],
            $report->vendoredDuplicates,
            'armed + vendored-free = honest zero shape (the key exists, says nothing happened)',
        );
    }

    public function testGateDenominatorSeesDemotedSlice(): void
    {
        // The doc's complaint this leg answers: scope denominators were
        // vendor-polluted. The Report's cluster list IS what gates and
        // reporters measure, so duplicated_lines must exclude the
        // vendored cluster's 24 lines under an armed scope.
        $armed   = $this->runStage($this->corpus(), 'repoA');
        $unarmed = $this->runStage($this->corpus(), 'all');

        $armedDup   = array_sum(array_map(static fn(Cluster $c): int => $c->totalLines(), $armed->clusters));
        $unarmedDup = array_sum(array_map(static fn(Cluster $c): int => $c->totalLines(), $unarmed->clusters));

        self::assertNotNull($armed->vendoredDuplicates);
        self::assertSame(
            $unarmedDup - $armed->vendoredDuplicates['lines'],
            $armedDup,
            'the demoted lines leave the measured slice exactly as far as they enter the section',
        );
    }

    // ------------------------------------------------------------------ fixtures

    /** @return list<Cluster> fresh objects per run (rank mutates in place) */
    private function corpus(): array
    {
        return [
            new Cluster('VEND', [
                $this->member($this->tmp . '/repoA/vendor/acme/pkg/A.php'),
                $this->member($this->tmp . '/repoA/vendor/acme/pkg/B.php'),
            ], 0.95, false),
            new Cluster('MIXED', [
                $this->member($this->tmp . '/repoA/src/Fork1.php'),
                $this->member($this->tmp . '/repoA/vendor/acme/pkg/Fork2.php'),
            ], 0.9, false),
            $this->cleanCluster(),
        ];
    }

    private function cleanCluster(): Cluster
    {
        return new Cluster('CLEAN', [
            $this->member($this->tmp . '/repoA/src/P1.php'),
            $this->member($this->tmp . '/repoA/src/P2.php'),
        ], 0.9, false);
    }

    private function member(string $file): Block
    {
        return new Block(
            file:      $file,
            range:     new LineRange(1, 12),
            kind:      'method',
            namespace: 'Ns',
            class:     null,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
    }

    private function runStage(array $clusters, string $scope): Report
    {
        $config = new Config(
            paths:            [$this->tmp . '/repoA'],
            exclude:          [],
            cacheDir:         $this->tmp . '/cache',
            minClusterImpact: 0,
            scope:            $scope,
        );
        $state = new PipelineState($config);
        $state->clusters = $clusters;
        $state->files    = ['x.php'];
        $state->blocks   = $clusters[0]->members;

        (new ReportStage(limit: 50, showStats: false))->run($state, new NullOutput());

        self::assertNotNull($state->report);
        return $state->report;
    }

    /** @return list<string> */
    private function ids(Report $report): array
    {
        return array_map(static fn(Cluster $c): string => $c->id, $report->clusters);
    }

    /** @param list<string> $expected @param list<string> $actual */
    private function assertSameCanonical(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, $message);
    }
}
