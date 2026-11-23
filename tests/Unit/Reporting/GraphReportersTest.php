<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Reporting\GraphvizReporter;
use Phpdup\Reporting\PlantumlReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\ControlText;
use Phpdup\Util\LineRange;

final class GraphReportersTest extends TestCase
{
    public function testGraphvizEmitsValidDigraph(): void
    {
        // output-S56b: synthetic 2-member carrier for the retired sql
        // fixture — the edge regex needs at least one file→cluster arc,
        // which a two-member cluster guarantees.
        $report = $this->syntheticReport('G1', ['/scan/alpha.php', '/scan/beta.php']);
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
        // output-S56b: synthetic carrier; the cluster exists by
        // construction, so the body pins are unconditional now
        // (the fixture-era conditional could silently skip them).
        $report = $this->syntheticReport('P1', ['/scan/alpha.php', '/scan/beta.php']);
        $puml = (new PlantumlReporter())->build($report);
        $this->assertStringStartsWith('@startuml phpdup', $puml);
        $this->assertStringEndsWith("@enduml\n", $puml);
        $this->assertStringContainsString('package "', $puml);
        $this->assertStringContainsString('class ', $puml);
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

    /**
     * once-S60 / php-dup-finder-once-068 re-check leg: the output-S18
     * closure refused a NEWLINE example and softened a quote example;
     * this pins the whole boundary — every byte of the venue's FORGE set
     * (\0 \n \r \x1b), in either the directory or filename segment, is
     * refused at the label boundary with the byte named in the diagnostic
     * (rendered hex-escaped, never raw), because each one could split the
     * quoted label onto a new physical line and hand the renderer a
     * top-level statement.
     */
    public function testPlantumlRefusesEveryForgeByteInAPath(): void
    {
        $refused = [];
        foreach (str_split(ControlText::FORGE_BYTES) as $byte) {
            $tag    = sprintf('0x%02X', ord($byte));
            $report = $this->syntheticReport('FB' . $tag, ['/s/dir' . $byte . 'more/evil.php', '/s/ok.php']);
            $thrown = null;
            try {
                (new PlantumlReporter())->build($report);
            } catch (\RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            $this->assertNotNull($thrown, "forge byte {$tag} reached the PlantUML stream unrefused");
            $this->assertStringContainsString('PlantumlReporter', $thrown);
            $this->assertStringContainsString($tag, $thrown, 'the diagnostic must name the offending byte');
            $this->assertStringNotContainsString($byte, $thrown, 'the diagnostic must not re-embed the forge byte raw');
            $refused[] = $tag;
        }
        $this->assertCount(4, $refused, 'FORGE_BYTES set changed shape — re-derive the leg');
    }

    /**
     * once-S60 closure of the same finding: a name that is legal for the
     * boundary (no forge bytes) yet hostile for the grammar — a raw double
     * quote, directive-looking payload text, AND an invalid UTF-8 byte
     * (TPL-03's crafted-name class carried over to legacy encodings) —
     * still injects nothing top-level: every emitted class line is one
     * physical line carrying exactly its two label quotes, no line starts
     * with `!`, and the quote is softened while the opaque byte rides
     * visibly (the .puml artifact is plain renderer input: the injection
     * bar is the grammar characters, and those are closed).
     */
    public function testPlantumlLabelCarriesNonUtf8AndQuoteMixWithoutTopLevelStatement(): void
    {
        $hostile = '/s/caf' . "\xE9" . '"X!include.php';
        $report  = $this->syntheticReport('PUMLX', [$hostile, '/s/ok.php']);
        $puml    = (new PlantumlReporter())->build($report);

        $this->assertDoesNotMatchRegularExpression('/^!/m', $puml, 'a scanned name forged a top-level directive');
        $classLines = array_values(array_filter(
            explode("\n", rtrim($puml, "\n")),
            static fn(string $l): bool => str_starts_with($l, 'class '),
        ));
        $this->assertNotEmpty($classLines);
        foreach ($classLines as $line) {
            $this->assertSame(2, substr_count($line, '"'), $line);
            $this->assertMatchesRegularExpression('/^class F[0-9a-f]{8} as "[^"\n]*"$/', $line,
                'label escaped its quoted single-line slot');
        }
        $this->assertStringContainsString("caf\xE9'X!include.php", $puml,
            'quote softened to an apostrophe, name (incl. the opaque byte) kept honest');
        $this->assertStringNotContainsString('"!include', $puml);
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
}
