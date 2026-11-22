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
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\CsvReporter;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
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
            // CI-85: PHP 8.4/8.5 deprecate omitting $escape; '\\' is
            // verbatim the old default, so parsing behaviour is unchanged.
            $cells = str_getcsv($rows[$i], ',', '"', '\\');
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
            // CI-85: PHP 8.4/8.5 deprecate omitting $escape; '\\' is
            // verbatim the old default, so parsing behaviour is unchanged.
            $cells = str_getcsv($rows[$i], ',', '"', '\\');
            $this->assertCount(14, $cells, "row {$i} should round-trip cleanly");
        }
    }

    public function testEscapeNeutralizesFormulaInjection(): void
    {
        $r = new \ReflectionMethod(CsvReporter::class, 'escape');
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

    public function testDefuseFormulaIsTheSharedGuardVenue(): void
    {
        // output-035: escape() delegates to the public static — the guard
        // shape the other three rule-named reporters (Diff, RefactorPatch,
        // RefactorTest) route their metadata text through. Byte behavior of
        // escape() stays pinned by the reflection test above.
        $this->assertSame("'=cmd|'/C calc'", CsvReporter::defuseFormula("=cmd|'/C calc'"));
        $this->assertSame("'+1", CsvReporter::defuseFormula('+1'));
        $this->assertSame("'-1", CsvReporter::defuseFormula('-1'));
        $this->assertSame("'@x", CsvReporter::defuseFormula('@x'));
        $this->assertSame("'\tx", CsvReporter::defuseFormula("\tx"));
        $this->assertSame("'\rx", CsvReporter::defuseFormula("\rx"));
        $this->assertSame('', CsvReporter::defuseFormula(''), 'empty string stays empty');
        $this->assertSame('F0a1b2c3', CsvReporter::defuseFormula('F0a1b2c3'), 'clean value byte-unchanged');
        $this->assertSame('a=b', CsvReporter::defuseFormula('a=b'), 'only the first char is a trigger');
    }

    /**
     * once-064/TPL-07: the venue trims BEFORE checking — leading whitespace
     * must not escort a formula trigger into the sheet, while values the
     * trim does not turn into triggers stay byte-identical.
     */
    public function testDefuseFormulaTrimsBeforeCheck(): void
    {
        $this->assertSame("' =cmd|'/C calc'", CsvReporter::defuseFormula(" =cmd|'/C calc'"), 'leading-space evasion');
        $this->assertSame("'  +1", CsvReporter::defuseFormula('  +1'));
        $this->assertSame("'\t =cmd", CsvReporter::defuseFormula("\t =cmd"), 'raw tab lead already triggers; compound lead too');
        $this->assertSame("'\n=cmd", CsvReporter::defuseFormula("\n=cmd"), 'newline-in-name hides the lead from a first-char-only check');

        $this->assertSame(' x', CsvReporter::defuseFormula(' x'), 'whitespace without a trigger behind it is not a formula');
        $this->assertSame('mid =cmd', CsvReporter::defuseFormula('mid =cmd'), 'only the LEAD matters, never mid-value');
        $this->assertSame('   ', CsvReporter::defuseFormula('   '), 'all-whitespace value stays verbatim — nothing to defuse');
    }

    /**
     * once-S58 acceptance: the plan's fixtures (=cmd|'/m', tab-leading,
     * newline-in-name, plus the leading-space evasion) defuse IDENTICALLY on
     * every tabular report surface — each output's decoded CELL carries the
     * same marker decision from the one shared venue (format-level encoding:
     * RFC 4180 quoting, XML escaping, JSON strings — layers on top exactly
     * as its format requires; XML additionally folds literal attribute
     * whitespace bytes to spaces by spec, not by reporter choice).
     */
    public function testFormulaFixturesDefuseIdenticallyAcrossThreeSurfaces(): void
    {
        foreach (["=cmd|'/m'", "\t=cmd", "\n=cmd|'/m'", " =cmd|'/m'"] as $fixture) {
            $expected = CsvReporter::defuseFormula($fixture);
            $this->assertNotSame($fixture, $expected, 'the fixture must reach the marker in the venue');
            $report = $this->reportWithPayload($fixture);

            // --- CSV: fgetcsv because a quoted cell may carry a newline ---
            $stream = fopen('php://memory', 'r+');
            $this->assertNotFalse($stream);
            fwrite($stream, (new CsvReporter())->build($report));
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '\\');
            $row    = fgetcsv($stream, 0, ',', '"', '\\');
            fclose($stream);
            $this->assertIsArray($header);
            $this->assertIsArray($row);
            $this->assertSame($expected, $row[5], "CSV file cell — {$fixture}");
            $this->assertSame($expected, $row[11], "CSV name cell — {$fixture}");

            // --- Checkstyle XML: parsed attribute values. XML attribute-
            // value normalization folds literal TAB/newline/CR bytes of the
            // CDATA type to spaces (spec, not reporter choice); the leading
            // marker itself is position-invariant and survives. ---
            $doc = new \DOMDocument();
            $this->assertTrue($doc->loadXML((new CheckstyleReporter())->build($report)));
            $xmlExpected = strtr($expected, ["\r\n" => ' ', "\n" => ' ', "\r" => ' ', "\t" => ' ']);
            $fileNode = $doc->getElementsByTagName('file')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $fileNode);
            $this->assertSame($xmlExpected, $fileNode->getAttribute('name'), "Checkstyle name cell — {$fixture}");
            $errorNode = $doc->getElementsByTagName('error')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $errorNode);
            $this->assertStringStartsWith($xmlExpected, $errorNode->getAttribute('message'),
                "Checkstyle message leads with the same defused path — {$fixture}");

            // --- GitLab SAST: decoded payload cells ---
            $payload = (new GitLabSastReporter())->build($report);
            $vuln    = $payload['vulnerabilities'][0];
            $this->assertSame($expected, $vuln['location']['file'], "GitLab location.file — {$fixture}");
            $this->assertStringContainsString($expected, (string)$vuln['description'],
                "GitLab signature leg routes through the venue — {$fixture}");
            $this->assertStringStartsWith('F0000000000000007::', (string)$vuln['id'],
                'the schema-contracted dedup key stays byte-faithful (carve-out)');
        }
    }

    /**
     * Hand-built Report per AGENTS.md reporter-test convention: one member
     * whose scanned file/name text (and the cluster signature leg) is $payload.
     */
    private function reportWithPayload(string $payload): Report
    {
        $member = new Block(
            file: $payload,
            range: new LineRange(1, 5),
            kind: 'function',
            namespace: null,
            class: null,
            name: $payload,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $cluster            = new Cluster('F0000000000000007', [$member], 1.0, true);
        $cluster->signature = $payload;

        return new Report(1, 1, 0, [$cluster], Config::defaults([]));
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
