<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\CsvReporter;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

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

    /**
     * php-dup-finder-output-036 (output-S56a migration): this arm used to run
     * the four pipeline stages over tests/Fixtures/sql. Per the AGENTS.md
     * reporter-test convention it now consumes a hand-built two-cluster
     * report — same vectors: header + one row per member, every body row
     * exactly 14 fields. The expected row count is hard-coded instead of
     * being re-derived from the very report handed to the emitter (the old
     * form tautologized against a broken members count), and the identity
     * columns are pinned per row (disclosed strengthenings).
     */
    public function testFixtureReportProducesOneRowPerMember(): void
    {
        $report = $this->syntheticTwoClusterReport();
        $csv    = (new CsvReporter())->build($report);

        $rows = array_values(array_filter(explode("\n", trim($csv))));
        // header + five members across the two clusters
        $this->assertCount(6, $rows);

        $cells = [];
        for ($i = 1; $i <= 5; $i++) {
            // CI-85: PHP 8.4/8.5 deprecate omitting $escape; '\\' is
            // verbatim the old default, so parsing behaviour is unchanged.
            $cells[$i] = str_getcsv($rows[$i], ',', '"', '\\');
            $this->assertCount(14, $cells[$i], "row $i should have 14 cells");
        }

        $this->assertSame(
            ['F0000000000000021', 'F0000000000000021', 'F0000000000000022', 'F0000000000000022', 'F0000000000000022'],
            array_values(array_column($cells, 0)),
            'every member gets a row and the cluster order survives emission',
        );
        $this->assertSame(
            ['2', '2', '3', '3', '3'],
            array_values(array_column($cells, 1)),
            'the members column carries the count of the row\'s own cluster',
        );
    }

    /**
     * php-dup-finder-output-036 (output-S56a migration): carried from the
     * retired pipeline arm (RFC 4180 round-trip of comma-bearing rows). The
     * synthetic payload makes the trigger explicit — the signature leg the
     * doc-comment always named ('the signature field can contain commas')
     * plus comma-bearing file/name cells — and the cells must return
     * byte-equal through the quoting venue, so removing the quoting branch
     * splits the row (14-cell assert goes RED).
     */
    public function testFieldsContainingCommasAreEscaped(): void
    {
        $signature = 'function process($userId, string $name): array';
        $file      = '/scan/we,ird-name.php';
        $name      = 'handle, compress';

        $member = new Block(
            file: $file,
            range: new LineRange(7, 19),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $cluster            = new Cluster('F0000000000000023', [$member], 0.95, false);
        $cluster->impact    = 13;
        $cluster->signature = $signature;

        $csv  = (new CsvReporter())->build(new Report(1, 1, 0, [$cluster], Config::defaults([])));
        $rows = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $rows, 'header + the one member row');

        // CI-85: PHP 8.4/8.5 deprecate omitting $escape; '\\' is
        // verbatim the old default, so parsing behaviour is unchanged.
        $cells = str_getcsv($rows[1], ',', '"', '\\');
        $this->assertCount(14, $cells, 'comma-carrying row must round-trip cleanly');
        $this->assertSame($file, $cells[5], 'file cell returns byte-equal through the quoting venue');
        $this->assertSame($name, $cells[11], 'name cell returns byte-equal through the quoting venue');
        $this->assertSame($signature, $cells[12], 'signature cell returns byte-equal through the quoting venue');
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

    /**
     * Hand-built two-cluster report (2 + 3 members) replacing the retired
     * sql-fixture pipeline run as the row-shape carrier — AGENTS.md
     * reporter-test convention: synthetic Cluster + Block instances, no
     * stages.
     */
    private function syntheticTwoClusterReport(): Report
    {
        $member = static fn(string $file, int $start): Block => new Block(
            file: $file,
            range: new LineRange($start, $start + 4),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'm',
            ast: new \PhpParser\Node\Stmt\Nop(),
        );

        $a            = new Cluster('F0000000000000021', [$member('csv-a1.php', 1), $member('csv-a2.php', 20)], 1.0, true);
        $a->impact    = 10;
        $a->signature = 'function alpha($x): int';

        $b            = new Cluster('F0000000000000022', [$member('csv-b1.php', 5), $member('csv-b2.php', 10), $member('csv-b3.php', 15)], 0.9, false);
        $b->impact    = 15;
        $b->signature = 'function beta($y): string';
        $b->patternTags = ['db-op'];

        return new Report(5, 5, 0, [$a, $b], Config::defaults([]));
    }
}
