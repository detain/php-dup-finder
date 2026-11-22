<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Item 5.4 CLI surface: structural gaps render as (gap) with a muted
 * partial-evidence note, and the hole-coverage stance prints its
 * re-expressed annotation only when the operator lowered the bar —
 * the default 1.0 posture must never add a line.
 */
final class CliReporterHonestyTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-cli-honesty-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testDegradedHoleRendersGapMarkerAndPartialEvidenceNote(): void
    {
        $cluster = $this->pairCluster();
        $cluster->holes[] = new Hole('__P9', 'subtree', ['', "'kept'"], true);

        $rendered = $this->render($cluster, 1.0);

        $this->assertStringContainsString('(gap)', $rendered);
        $this->assertStringNotContainsString('<missing>', $rendered);
        $this->assertStringContainsString('no node at the hole path', $rendered);
        $this->assertStringContainsString('partial evidence', $rendered);
        // The stance line must NOT piggyback on a degraded-only cluster at
        // the default threshold.
        $this->assertStringNotContainsString('re-expressed:', $rendered);
    }

    public function testCoverageStancePrintsWhenBarLowered(): void
    {
        $cluster = $this->pairCluster();
        $coverage = HoleCoverage::coverage($cluster);
        $this->assertNotNull($coverage);
        $this->assertGreaterThan(0.0, $coverage, 'fixture must leave measurable hole coverage');

        $rendered = $this->render($cluster, $coverage / 2);

        $this->assertStringContainsString('re-expressed:', $rendered);
        $this->assertStringContainsString('--max-hole-coverage', $rendered);
    }

    public function testDefaultThresholdAddsNoStanceLine(): void
    {
        $cluster = $this->pairCluster();

        $rendered = $this->render($cluster, 1.0);

        $this->assertStringNotContainsString('re-expressed:', $rendered);
    }

    public function testSummaryOnlyStaysSilentOnHonestyNotes(): void
    {
        $cluster = $this->pairCluster();
        $cluster->holes[] = new Hole('__P9', 'subtree', ['', 'x'], true);
        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: 0.01);
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))
            ->render($this->report($cluster, $config), $out);

        $rendered = $out->fetch();
        $this->assertStringNotContainsString('no node at the hole path', $rendered);
        $this->assertStringNotContainsString('placement:', $rendered);
    }

    public function testPlacementAdviceRendersInFullDetailOnly(): void
    {
        $cluster = $this->pairCluster();

        // FULL path (default constructor): the advisor line names the shape.
        $rendered = $this->render($cluster, 1.0);
        $this->assertStringContainsString('placement: parameterized_function', $rendered);
        // Single scan root, no cross-repo evidence → no fabricated bracket.
        $this->assertStringNotContainsString('[shared host:', $rendered);

        // CLUSTERS one-liner mode must stay terse — no advice prose.
        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: 1.0);
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter(CliReporter::VERBOSITY_CLUSTERS))
            ->render($this->report($cluster, $config), $out);
        $this->assertStringNotContainsString('placement:', $out->fetch());
    }

    /**
     * output-054: the banner and the trailer print the same totals — they
     * must arrive from ONE computation (a class-source guard pins the
     * shape), and both surfaces must agree with an independent recount of
     * the report's clusters.
     */
    public function testTotalsAreComputedOnceAndAgreeAcrossBannerAndTrailer(): void
    {
        $cluster = $this->pairCluster();
        $report  = $this->report($cluster, new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: 1.0));

        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($report, $out);
        $rendered = $out->fetch();

        // Truth, recomputed in the test.
        $trueDuplicated = $report->totalDuplicatedLines();
        $trueImpact     = array_sum(array_map(static fn(Cluster $c) => $c->impact, $report->clusters));

        $this->assertSame(
            1,
            preg_match('/(\d+) files · (\d+) blocks · (\d+) clusters · (\d+) duplicated lines · (\d+) total impact/', $rendered, $banner),
            'banner totals line must render',
        );
        $this->assertSame(
            1,
            preg_match('/summary  (\d+) clusters · (\d+) duplicated lines · (\d+) total impact/', $rendered, $trailer),
            'trailer totals line must render',
        );
        $this->assertSame((string)count($report->clusters), $banner[3]);
        $this->assertSame((string)$trueDuplicated, $banner[4]);
        $this->assertSame((string)$trueImpact, $banner[5]);
        $this->assertSame($banner[3], $trailer[1], 'banner and trailer must agree on cluster count');
        $this->assertSame($banner[4], $trailer[2], 'banner and trailer must agree on duplicated lines');
        $this->assertSame($banner[5], $trailer[3], 'banner and trailer must agree on total impact');

        // Shape guard: each totals walk happens exactly once per render —
        // the trailer receives the banner's locals instead of re-walking.
        $src = (string)file_get_contents((new \ReflectionClass(CliReporter::class))->getFileName());
        $this->assertSame(
            1,
            substr_count($src, 'totalDuplicatedLines('),
            'output-054: duplicated-lines total must be computed once per render, not once per surface',
        );
        $this->assertSame(
            1,
            substr_count($src, 'array_sum('),
            'output-054: impact total must be computed once per render, not once per surface',
        );
    }

    /**
     * output-046 (php-dup-finder-output-046): Theme::plain() neutralizes the
     * reporter's OWN Style objects — nothing there stops an ESC byte that
     * rode in inside a scanned filename, class name, signature fragment,
     * hole value or tag. The boundary scrub (shared ControlText substitute
     * arm) must leave ZERO escape bytes in the plain render, with the forger
     * replaced by the one visible marker instead of silently vanishing.
     */
    public function testScannedControlBytesLeavePlainRenderEscapeFree(): void
    {
        $rendered = $this->renderHostile(decorated: false);

        $this->assertStringNotContainsString("\x1b", $rendered, 'no ESC may reach a --no-ansi stream from scanned text');
        $this->assertStringNotContainsString("\x00", $rendered);
        $this->assertStringContainsString(
            "\u{FFFD}]0;pwn",
            $rendered,
            'the OSC window-title payload survives only as inert text behind one visible replacement char',
        );
        $this->assertStringContainsString("\u{FFFD}[31mArm", $rendered, 'injected SGR body cannot survive the class-name scrub intact');
        $this->assertStringContainsString("'va\u{FFFD}lue'", $rendered, 'observed hole values are scrubbed too');
    }

    /**
     * output-046, decorated leg: the hostile OSC frame and injected-SGR arms
     * are gone while legitimate styling remains — proving the scrub targets
     * scanned bytes, not the reporter's own ANSI.
     */
    public function testScannedControlBytesScrubbedUnderDecoratedOutput(): void
    {
        $rendered = $this->renderHostile(decorated: true);

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered, 'decorated render must still carry the reporter\u2019s own SGR styling');
        $this->assertStringNotContainsString("\x1b]0;pwn", $rendered);
        $this->assertStringNotContainsString("\x1b[31mArm", $rendered);
        $this->assertStringContainsString("\u{FFFD}]0;pwn", $rendered);
    }

    /**
     * output-046 cross-check on the two-root placement line: the advisor
     * already drops non-charset composer keys, so the plain render names the
     * shared host and neither the newline-forged key nor the ESC key appears
     * in ANY form.
     */
    public function testComposerHostLineDropsNonCharsetKeysBeforeRender(): void
    {
        $body = json_encode(['require' => [
            'acme/shared' => '^1',
            "vendor/na\nme" => '^1',
            "vendor/esc\x1bkey" => '^1',
        ]]);
        $rootA = $this->tmp . '/ra';
        $rootB = $this->tmp . '/rb';
        mkdir($rootA . '/src', 0777, true);
        mkdir($rootB . '/src', 0777, true);
        file_put_contents($rootA . '/composer.json', $body);
        file_put_contents($rootB . '/composer.json', $body);

        $cluster = new Cluster('PAIR', [
            new Block($rootA . '/src/A.php', new LineRange(1, 3), 'method', 'Acme', 'A', 'run', new Nop()),
            new Block($rootB . '/src/B.php', new LineRange(1, 3), 'method', 'Acme', 'B', 'run', new Nop()),
        ], 0.9, false);
        $config  = new Config(paths: [$rootA, $rootB], exclude: [], maxHoleCoverage: 1.0);

        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter())->render(
            new Report(files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: $config),
            $out,
        );
        $rendered = $out->fetch();

        $this->assertStringContainsString('[shared host: acme/shared]', $rendered);
        $this->assertStringNotContainsString('vendor/na', $rendered);
        $this->assertStringNotContainsString('vendor/esc', $rendered);
        $this->assertStringNotContainsString("\x1b", $rendered);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * A hand-built cluster whose EVERY scanned-derived slot carries a
     * forger: filename (OSC title), class name (SGR injection), signature
     * (ESC inside a two-line parameter block — the legitimate LF structure
     * must survive the line-scoped signature scrub), one hole observed
     * value, and one pattern tag.
     */
    private function hostileCluster(): Cluster
    {
        $a = new Block(
            $this->tmp . "/\x1b]0;pwn\x07evil.php",
            new LineRange(1, 3),
            'method',
            'Acme',
            "Al\x1b[31mArm",
            'run',
            new Nop(),
        );
        $b = new Block(
            $this->tmp . '/clean.php',
            new LineRange(1, 3),
            'method',
            'Acme',
            'Clean',
            'run',
            new Nop(),
        );
        $cluster = new Cluster('PAIR', [$a, $b], 0.9, false);
        $cluster->signature = "function extracted(\n    \$a\x1bb\n): mixed";
        $h = new Hole('__P0', 'literal', ["'va\x1blue'", "'other'"]);
        $h->suggestedName = "\x1bval";
        $h->inferredType  = "St\x1cr\x00ange";
        $cluster->holes[] = $h;
        $cluster->patternTags = ['db-op' . "\x1b[H"];

        return $cluster;
    }

    private function renderHostile(bool $decorated): string
    {
        $cluster = $this->hostileCluster();
        $config  = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: 1.0);
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: $decorated);
        (new CliReporter())->render(
            new Report(files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: $config),
            $out,
        );
        return $out->fetch();
    }

    private function render(Cluster $cluster, float $maxHoleCoverage): string
    {
        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: $maxHoleCoverage);
        $out = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
        (new CliReporter())->render($this->report($cluster, $config), $out);
        return $out->fetch();
    }

    private function report(Cluster $cluster, Config $config): Report
    {
        $cluster->signature = 'function extracted($v): mixed';
        $cluster->impact    = 2;

        return new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: $config,
        );
    }

    private function pairCluster(): Cluster
    {
        $blocks = [];
        foreach (['if ($s > 1) { send($u); }', 'if ($s > 2) { send($v); }'] as $i => $code) {
            $file  = $this->tmp . "/m{$i}.php";
            file_put_contents($file, "<?php\n" . $code . "\n");
            $stmts     = (new AstParser())->parseCode((string)file_get_contents($file));
            $extracted = (new BlockExtractor(minSize: 1))->extract($file, $stmts);
            $this->assertNotEmpty($extracted);
            $block       = $extracted[0];
            $block->id   = "BLK{$i}";
            $block->name = 'm' . $i;
            $blocks[]    = $block;
        }
        $cluster        = new Cluster('PAIR', $blocks, 0.9, false);
        $cluster->pairs = [[
            'blockA' => 'BLK0', 'blockB' => 'BLK1', 'matchTier' => 'jaccard', 'matchScore' => 0.9,
        ]];
        (new AntiUnifier())->unify($cluster);
        $this->assertNotEmpty($cluster->holes);
        return $cluster;
    }
}
