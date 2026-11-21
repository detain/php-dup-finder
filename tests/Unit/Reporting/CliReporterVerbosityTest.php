<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
use SugarCraft\Style\Border;
use SugarCraft\Style\Table\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CliReporterVerbosityTest extends TestCase
{
    public function testRejectsUnknownVerbosity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CliReporter('bogus');
    }

    public function testSummaryOnlySkipsClusterDetails(): void
    {
        $report = $this->buildReport();
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('phpdup', $rendered);
        $this->assertStringContainsString('summary  ', $rendered);
        // No member tables or "Suggested abstraction" sections in summary-only.
        $this->assertStringNotContainsString('Suggested abstraction', $rendered);
        $this->assertStringNotContainsString('LOCATION', $rendered);
    }

    public function testClustersModeShowsTableButNoSignaturePanel(): void
    {
        $report = $this->buildReport();
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_CLUSTERS))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('CLUSTER', $rendered);
        $this->assertStringContainsString('IMPACT', $rendered);
        $this->assertStringNotContainsString('Suggested abstraction', $rendered);
        $this->assertStringContainsString('summary  ', $rendered);
    }

    public function testFullModeIsTheDefault(): void
    {
        $report = $this->buildReport();
        $out    = $this->buffered();
        (new CliReporter())->render($report, $out);
        $rendered = $out->fetch();
        $this->assertStringContainsString('summary  ', $rendered);
        // Full-mode-only sections (only present when there are clusters)
        if (count($report->clusters) > 0) {
            $this->assertStringContainsString('Cluster #', $rendered);
        }
    }

    public function testEmptyReportRendersClean(): void
    {
        $cfg = Config::defaults([__DIR__]);
        $report = new Report(0, 0, 0, [], $cfg);
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $this->assertStringContainsString('clean', $out->fetch());
    }

    /** 0.6: a tracked report always surfaces the per-bucket accounting. */
    public function testTrackedReportAlwaysRendersFileAccounting(): void
    {
        $cfg = Config::defaults([__DIR__]);
        $report = new Report(4, 10, 1, [], $cfg, reusedFiles: 0, processedFiles: 2, skippedFiles: 1);
        $out = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('files  4 scanned · 0 reused · 2 processed · 1 no blocks · 1 parse errors', $rendered);
        $this->assertStringContainsString('1 file produced no comparable blocks', $rendered);
        $this->assertStringContainsString('1 file failed to parse', $rendered);
        $this->assertStringNotContainsString('1 files', $rendered, 'p0c-m2: singular counts must not render plural');
        $this->assertStringNotContainsString('— clean.', $rendered, 'uncomparable files must block the bare clean verdict');
    }

    /** p0c-m2 companion: plural counts keep the historical wording. */
    public function testPluralFileCountsStayPluralInNotes(): void
    {
        $cfg = Config::defaults([__DIR__]);
        $report = new Report(6, 10, 3, [], $cfg, reusedFiles: 0, processedFiles: 1, skippedFiles: 2);
        $out = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('2 files produced no comparable blocks', $rendered);
        $this->assertStringContainsString('3 files failed to parse', $rendered);
    }

    /** Pre-0.6 producers pass null counters: the historical surface is untouched. */
    public function testLegacyNullReportKeepsOriginalSurface(): void
    {
        // buildReport() constructs its Report with the 5-arg legacy shape
        // (counters null) and carries real clusters, so the trailer runs.
        $report = $this->buildReport();
        $out = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('summary  ', $rendered);
        $this->assertStringNotContainsString('scanned · ', $rendered, 'null counters must not render as fake zeros');
        $this->assertStringNotContainsString('no comparable blocks', $rendered);
    }

    /**
     * output-060: the batched `Table::rows()` append must render exactly the
     * bytes the old per-cluster `Table::row()` loop produced. The reference
     * table here keeps the OLD shape on purpose — if the batched path ever
     * drifts in cell derivation or ordering, this containment fails.
     */
    public function testClusterListTableBytesMatchRowByRowReference(): void
    {
        $report = $this->syntheticReport(6);
        $out    = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_CLUSTERS))->render($report, $out);
        $rendered = $out->fetch();

        $table = Table::new()
            ->border(Border::rounded())
            ->headers('CLUSTER', 'MEMBERS', 'SIM', 'IMPACT', 'KIND', 'LOCATION');
        foreach ($report->clusters as $c) {
            $loc = isset($c->members[0]) ? $c->members[0]->location() : '';
            $table = $table->row(
                $c->id,
                (string)$c->size(),
                sprintf('%.2f', $c->similarity),
                (string)$c->impact,
                isset($c->members[0]->kind) ? $c->members[0]->kind : '',
                $loc,
            );
        }
        $this->assertStringContainsString($table->render(), $rendered);
    }

    /**
     * output-060 structural pin: renderClusterList must append its rows in
     * ONE `Table::rows()` call. The vendor Table is immutable — every
     * `row()` call clones and spreads all prior rows, so a per-cluster
     * `row()` loop is O(R²) when a large `--limit` shows thousands of
     * clusters (harmless at the default 50). Reading the method source is
     * the only way to pin the SHAPE of the append (byte equality against
     * the row-by-row reference cannot tell the two apart).
     */
    public function testClusterListUsesOneBatchedRowsCallNotPerRowAppends(): void
    {
        $method = new \ReflectionMethod(CliReporter::class, 'renderClusterList');
        $lines  = file((string)$method->getFileName());
        $this->assertIsArray($lines);
        $src = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString('->rows(', $src, 'cluster table must append via one batched rows() call');
        $this->assertStringNotContainsString('->row(', $src, 'per-cluster immutable row() appends are O(R^2) — regressions to the row-by-row shape are what output-060 forbids');
    }

    /**
     * @param positive-int $count
     * @return list<Cluster>
     */
    private function syntheticClusters(int $count): array
    {
        $clusters = [];
        for ($i = 0; $i < $count; $i++) {
            $block = new Block(
                file: '/synthetic/Member' . $i . '.php',
                range: new LineRange($i + 1, $i + 6),
                kind: 'method',
                namespace: 'App\\Mod' . $i,
                class: 'Svc' . $i,
                name: 'handle' . $i,
                ast: new \PhpParser\Node\Stmt\Nop(),
            );
            $block->id = 'BLK' . $i;

            $cluster        = new Cluster('C' . $i, [$block], 0.5 + $i / 100, $i % 2 === 0);
            $cluster->impact = 10 * $i;
            $clusters[]     = $cluster;
        }
        return $clusters;
    }

    private function syntheticReport(int $count): Report
    {
        $config = new Config(paths: [__DIR__], exclude: []);
        return new Report(
            files: $count, blocks: $count, parseErrors: 0,
            clusters: $this->syntheticClusters($count), config: $config,
        );
    }

    private function buffered(): BufferedOutput
    {
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        return $out;
    }

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: true))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }
}
