<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\GraphvizReporter;
use Phpdup\Reporting\PlantumlReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\ControlText;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

final class GraphReportersTest extends TestCase
{
    public function testGraphvizEmitsValidDigraph(): void
    {
        $report = $this->buildReport();
        $dot = (new GraphvizReporter())->build($report);

        $this->assertStringStartsWith('digraph phpdup {', $dot);
        $this->assertStringEndsWith("}\n", $dot);
        $this->assertMatchesRegularExpression('/^\s*n_[a-f0-9]{12} -> n_[a-f0-9]{12};/m', $dot);
    }

    public function testGraphvizEmptyReportProducesValidEmptyGraph(): void
    {
        $dot = (new GraphvizReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringStartsWith('digraph phpdup {', $dot);
        $this->assertStringEndsWith("}\n", $dot);
    }

    public function testPlantumlEmitsValidUmlDocument(): void
    {
        $report = $this->buildReport();
        $puml = (new PlantumlReporter())->build($report);
        $this->assertStringStartsWith('@startuml phpdup', $puml);
        $this->assertStringEndsWith("@enduml\n", $puml);
        if (count($report->clusters) > 0) {
            $this->assertStringContainsString('package "', $puml);
            $this->assertStringContainsString('class ', $puml);
        }
    }

    public function testPlantumlEmptyReport(): void
    {
        $puml = (new PlantumlReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringContainsString('@startuml', $puml);
        $this->assertStringContainsString('@enduml', $puml);
    }

    // ── output-047/052: escaping arms for quoted-label formats ──────────────

    public function testDotLabelRoundTripsAdversarialBasename(): void
    {
        $evilFile  = '/scan/x/a"b' . "\n" . 'c.php';
        $report    = $this->syntheticReport('DOT', [$evilFile, '/scan/y/ok.php']);
        $dot       = (new GraphvizReporter())->build($report);

        // The basename reaches the wire ONLY as a DOT-escaped sequence —
        // never a raw LF that could split the quoted label.
        $this->assertStringContainsString('label="a\\"b\\nc.php"', $dot);

        // Every emitted label is one physical line with no raw control byte.
        preg_match_all('/label="((?:\\\\.|[^"\\\\])*?)"/', $dot, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $label) {
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0b-\x1f\x7f]/', $label);
        }

        // Round-trip: DOT's own unescaping recovers the original basename.
        $adversarial = (static function (array $labels): string {
            foreach ($labels as $l) {
                if (str_contains($l, '\\nc.php')) {
                    return $l;
                }
            }
            return '';
        })($m[1]);
        $this->assertNotSame('', $adversarial, 'the adversarial basename must appear as a label');
        $unescaped = preg_replace_callback(
            '/\\\\(.)/',
            static fn(array $c): string => match ($c[1]) { 'n' => "\n", 'r' => "\r", 't' => "\t", default => $c[1] },
            $adversarial,
        );
        $this->assertSame(basename($evilFile), $unescaped);
    }

    public function testDotSubstitutesUnescapableBytes(): void
    {
        $report = $this->syntheticReport('DOTNUL', ["/s/a\x00b\x1bc.php", '/s/ok.php']);
        $dot    = (new GraphvizReporter())->build($report);
        $this->assertStringNotContainsString("\x00", $dot);
        $this->assertStringNotContainsString("\x1b", $dot);
        $this->assertStringContainsString(ControlText::SUBSTITUTE, $dot);
    }

    public function testPlantumlRefusesControlBytePaths(): void
    {
        $report = $this->syntheticReport('PUML', ["/s/evil\n!include /etc/passwd\nx.php", '/s/ok.php']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PlantumlReporter.*newline \(0x0A\)/');
        (new PlantumlReporter())->build($report);
    }

    public function testPlantumlInjectsNoTopLevelDirectiveFromAName(): void
    {
        // No control bytes, only the quote-closure vector in the FILENAME
        // segment (that is what lands in the quoted label): PlantUML grammar
        // has no in-label quote escape, so the quote must be softened and
        // the label can never close early into a `!` directive.
        $report = $this->syntheticReport('PUMLQ', ['/s/evil"!include.php', '/s/ok.php']);
        $puml   = (new PlantumlReporter())->build($report);

        $this->assertDoesNotMatchRegularExpression('/^!/m', $puml);
        $this->assertStringNotContainsString('"!include', $puml);
        $this->assertStringContainsString('evil\'!include.php', $puml, 'softened label still names the file honestly');

        // Each class line carries exactly its two label quotes.
        foreach (explode("\n", $puml) as $line) {
            if (str_starts_with($line, 'class ')) {
                $this->assertSame(2, substr_count($line, '"'), $line);
            }
        }
    }

    /** @param list<string> $files */
    private function syntheticReport(string $id, array $files): Report
    {
        $members = [];
        foreach ($files as $f) {
            $stmts    = (new AstParser())->parseCode('<?php $x;');
            $members[] = new Block(
                file: $f,
                range: new LineRange(1, 1),
                kind: 'method',
                namespace: null, class: null, name: null,
                ast: $stmts[0],
            );
        }
        $cluster = new Cluster($id, $members, 1.0, false);
        return new Report(
            files: count($files), blocks: count($files), parseErrors: 0,
            clusters: [$cluster], config: Config::defaults([]),
        );
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
