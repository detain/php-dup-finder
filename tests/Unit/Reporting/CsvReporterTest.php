<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\CsvReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class CsvReporterTest extends TestCase
{
    public function testEmptyReportEmitsHeaderOnly(): void
    {
        $csv = (new CsvReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $lines = explode("\n", trim($csv));
        $this->assertCount(1, $lines);
        $this->assertSame(
            'cluster_id,members,similarity,confidence,impact,file,start,end,kind,namespace,class,name,signature,pattern_tags',
            $lines[0],
        );
    }

    public function testFixtureReportProducesOneRowPerMember(): void
    {
        $report = $this->buildReport();
        $csv = (new CsvReporter())->build($report);

        $rows = array_filter(explode("\n", trim($csv)));
        // header + one row per member
        $expectedMembers = 0;
        foreach ($report->clusters as $c) {
            $expectedMembers += count($c->members);
        }
        $this->assertCount($expectedMembers + 1, $rows);

        // RFC 4180: every body row should have exactly 14 fields
        for ($i = 1; $i < count($rows); $i++) {
            $cells = str_getcsv($rows[$i]);
            $this->assertCount(14, $cells, "row $i should have 14 cells");
        }
    }

    public function testFieldsContainingCommasAreEscaped(): void
    {
        // The signature field can contain commas (parameter list). Verify
        // the resulting CSV is still parseable round-trip.
        $report = $this->buildReport();
        $csv = (new CsvReporter())->build($report);
        $rows = explode("\n", trim($csv));
        for ($i = 1; $i < count($rows); $i++) {
            $cells = str_getcsv($rows[$i]);
            $this->assertCount(14, $cells, "row {$i} should round-trip cleanly");
        }
    }

    public function testEscapeNeutralizesFormulaInjection(): void
    {
        $r = new \ReflectionMethod(CsvReporter::class, 'escape');
        $r->setAccessible(true);
        $reporter = new CsvReporter();

        // Cells starting with =, +, -, @, \t get text prefix (no RFC 4180 quoting needed)
        $this->assertSame("'=hello", $r->invoke($reporter, '=hello'));
        $this->assertSame("'+hello", $r->invoke($reporter, '+hello'));
        $this->assertSame("'-hello", $r->invoke($reporter, '-hello'));
        $this->assertSame("'@hello", $r->invoke($reporter, '@hello'));
        $this->assertSame("'\t hello", $r->invoke($reporter, "\t hello"));

        // \r triggers RFC 4180 quoting because it is a special char
        $this->assertSame("\"'\r hello\"", $r->invoke($reporter, "\r hello"));

        // Normal cells unchanged
        $this->assertSame('hello', $r->invoke($reporter, 'hello'));

        // Cells with special chars get RFC 4180 quoting
        $this->assertSame('"hello, world"', $r->invoke($reporter, 'hello, world'));

        // Guard prefix + quoting combined: = triggers prefix, comma triggers quoting
        $this->assertSame('"\'=hello, world"', $r->invoke($reporter, '=hello, world'));
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
