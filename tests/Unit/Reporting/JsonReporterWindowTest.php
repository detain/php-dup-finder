<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Report;
use Phpdup\Util\Num;

/**
 * output-S41 (php-dup-finder-output-044): the two cost gates on the JSON
 * evidence keys — cap-BEFORE-Differ (windowed slices, disclosed via the
 * `truncated` flag plus a window note, never silently narrowed) and the
 * lazy coverage tally (hole-less clusters answer 0.0 without walking the
 * template AST). The counters on JsonReporter (coverageTallies,
 * evidenceDiffComputations, evidenceWindowSlices,
 * evidenceDiffMaxInputLines) are the spy surface, mirroring
 * SourceTextStore::readCount() and
 * RefactorPatchReporter::detectUnsafeComputations().
 *
 * Byte-preservation is carried by the untouched-belts rule: every prior-
 * era pin in JsonReporterTest / JsonReporterEvidenceTest /
 * JsonReporterScopeTest and the frozen goldens must pass UNEDITED — small
 * corpora never reach the window, so the legacy path is unchanged.
 */
final class JsonReporterWindowTest extends TestCase
{
    /** Mirror of JsonReporter::EVIDENCE_WINDOW_LINES (private const). */
    private const WINDOW = 240;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-s41-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    // ── leg 1: cap before Differ ────────────────────────────────────────────

    public function testSmallMembersTakeTheLegacyPathWindowNeverFires(): void
    {
        [$report] = $this->pairFixture(
            $this->functionFile('s0.php', 40, changeAt: 10),
            $this->functionFile('s1.php', 40, changeAt: 10, marker: 'CHANGED'),
        );

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);
        $ev       = $payload['clusters'][0]['evidence_diff'];

        $this->assertIsArray($ev, 'small differing members still earn real evidence');
        $this->assertStringContainsString('@@', (string)$ev['diff']);
        $this->assertStringNotContainsString('evidence window', (string)$ev['diff']);
        $this->assertFalse($ev['truncated'], 'legacy truncation posture is cap()-only');
        $this->assertSame(1, $reporter->evidenceDiffComputations());
        $this->assertSame(0, $reporter->evidenceWindowSlices());
        $this->assertLessThanOrEqual(self::WINDOW, $reporter->evidenceDiffMaxInputLines());
    }

    public function testHugeDivergentMembersAreWindowedNotFullyDiffed(): void
    {
        // 1200-line members with real divergence at body line ~100 —
        // inside the window, so evidence emits, but the Differ only ever
        // saw the first 240 lines of each side.
        [$report] = $this->pairFixture(
            $this->functionFile('w0.php', 1200, changeAt: 100),
            $this->functionFile('w1.php', 1200, changeAt: 100, marker: 'CHANGED'),
        );

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);
        $ev       = $payload['clusters'][0]['evidence_diff'];

        $this->assertIsArray($ev);
        $this->assertTrue($ev['truncated'], 'a window slice always discloses itself');
        $this->assertStringContainsString('evidence window', (string)$ev['diff']);
        $this->assertStringContainsString('first ' . self::WINDOW . ' of ', (string)$ev['diff']);
        $this->assertStringContainsString('@@', (string)$ev['diff'], 'the in-window hunk still shows');
        $this->assertSame(1, $reporter->evidenceWindowSlices());
        $this->assertSame(1, $reporter->evidenceDiffComputations());
        $this->assertLessThanOrEqual(
            self::WINDOW,
            $reporter->evidenceDiffMaxInputLines(),
            'the Differ must never receive a full 1202-line member',
        );
    }

    public function testWindowWithNoHunkDisclosesWithoutPayingTheDiffer(): void
    {
        // Identical first 240 lines; the only body difference sits at line
        // ~900. The window is equal, so no hunk exists in it — and no
        // full-file LCS is paid for a result we already know.
        [$report] = $this->pairFixture(
            $this->functionFile('q0.php', 1000, changeAt: 900),
            $this->functionFile('q1.php', 1000, changeAt: 900, marker: 'CHANGED'),
        );

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);
        $ev       = $payload['clusters'][0]['evidence_diff'];

        $this->assertIsArray($ev, 'evidence is annotated, never dropped (no silent null)');
        $this->assertTrue($ev['truncated']);
        // once-S101 fix1: git-style join for these ABSOLUTE fixture paths —
        // git prints `--- a` + '/' + leading-slash-stripped operand (proven
        // against `git diff --no-index /tmp/x …` → `--- a/tmp/x`). Built
        // here with plain string ops so the pin stays independent.
        $this->assertStringStartsWith('--- a/' . substr($this->tmp, 1) . '/q0.php' . "\n", (string)$ev['diff']);
        $this->assertStringContainsString('are identical; no hunk in the window', (string)$ev['diff']);
        $this->assertStringNotContainsString('@@', (string)$ev['diff']);
        $this->assertSame(1, $reporter->evidenceWindowSlices());
        $this->assertSame(0, $reporter->evidenceDiffComputations(), 'no Differ run is owed for an equal window');
    }

    public function testWindowedPayloadStaysWithinCapBudgetAndSchemaShapeUntouched(): void
    {
        [$report] = $this->pairFixture(
            $this->functionFile('b0.php', 3000, changeAt: 30),
            $this->functionFile('b1.php', 3000, changeAt: 30, marker: 'CHANGED'),
        );

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);
        $ev       = $payload['clusters'][0]['evidence_diff'];

        $this->assertTrue($ev['truncated'], 'the forced disclosure survives even a short window diff');
        // cap() keeps ≤120 lines / 6000 bytes; the window note adds exactly
        // one marker line — bounded blob, bounded cost.
        $this->assertLessThanOrEqual(7200, strlen((string)$ev['diff']));
        $this->assertLessThanOrEqual(self::WINDOW * 2 + 16, substr_count((string)$ev['diff'], "\n"));
        $this->assertSame(
            ['member', 'file', 'start', 'end', 'diff', 'truncated'],
            array_keys($ev),
            'cluster-level evidence key set/order untouched — no shape event (1.8 bump is summary/config-side)',
        );
        $this->assertSame('1.9', $payload['schema_version']);
    }

    // ── leg 2: coverage tallies only when needed ────────────────────────────

    public function testHolelessClustersSkipTheTallyButStillEmitCoverage(): void
    {
        // Byte-identical member pair → AntiUnifier produces zero holes →
        // the lazy gate must answer 0.0 with NO tally walk, and still emit
        // the key for every cluster (1.7 posture: no flag, no drop).
        [$report, $clusters] = $this->pairFixture(
            $this->functionFile('h0.php', 300, changeAt: -1),
            $this->functionFile('h1.php', 300, changeAt: -1),
        );

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);
        $c        = $payload['clusters'][0];

        $this->assertSame([], $clusters[0]->holePaths, 'fixture must be hole-less for this arm');
        $this->assertInstanceOf(\PhpParser\Node::class, $clusters[0]->generalizedAst);
        $this->assertSame(0, $reporter->coverageTallies());
        $this->assertSame(0.0, (float)$c['hole_coverage'], 'the value is 0.0 exactly as the walk would compute');
        $this->assertFalse($c['re_expressed']);
        $this->assertNull($c['hole_fragment']);
        // Same answer the callee's own contract gives — no gate crossed.
        $this->assertSame(0.0, HoleCoverage::coverage($clusters[0]));
    }

    public function testHoleCarryingClustersStillPayExactlyOneTally(): void
    {
        [$report, $clusters] = $this->pairFixture(
            $this->functionFile('m0.php', 300, changeAt: 50),
            $this->functionFile('m1.php', 300, changeAt: 50, marker: 'CHANGED'),
        );

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);

        $this->assertNotSame([], $clusters[0]->holePaths, 'this arm needs holes present');
        $this->assertSame(1, $reporter->coverageTallies(), 'measured once, like every era before');
        $this->assertSame(
            (float)Num::json((float)HoleCoverage::coverage($clusters[0])),
            (float)$payload['clusters'][0]['hole_coverage'],
            'emitted fraction is the tally answer shaped by Num::json, unchanged',
        );
        $this->assertSame('1.9', $payload['schema_version'], 'lazy leg is shape-neutral');
    }

    public function testNoGeneralizedAstStillAnswersNullThroughTheDelegate(): void
    {
        [$report, $clusters] = $this->pairFixture(
            $this->functionFile('n0.php', 10, changeAt: 5),
            $this->functionFile('n1.php', 10, changeAt: 5, marker: 'CHANGED'),
        );
        $clusters[0]->generalizedAst = null;
        $clusters[0]->holePaths      = ['__P0' => [0]]; // holes WITHOUT an AST: delegation is mandatory

        $reporter = new JsonReporter();
        $payload  = $reporter->build($report);

        $this->assertSame(1, $reporter->coverageTallies(), 'null-AST is the callee answer, not the lazy one');
        $this->assertNull($payload['clusters'][0]['hole_coverage']);
        $this->assertFalse($payload['clusters'][0]['re_expressed']);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * @return array{0: Report, 1: array<int, Cluster>}
     */
    private function pairFixture(string $file0, string $file1): array
    {
        $blocks = [];
        foreach ([$file0, $file1] as $i => $file) {
            $block     = $this->extract($file)[0];
            $block->id = "BLK{$i}";
            $blocks[]  = $block;
        }
        $cluster        = new Cluster('PAIR', $blocks, 0.9, false);
        $cluster->pairs = [[
            'blockA' => 'BLK0', 'blockB' => 'BLK1', 'matchTier' => 'jaccard', 'matchScore' => 0.9,
        ]];
        (new AntiUnifier())->unify($cluster);

        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: 1.0);
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: $config,
        );
        return [$report, [$cluster]];
    }

    /**
     * A real PHP function with exactly $lines body lines; every line is
     * unique per index so the LCS cannot alias rows. One body line flips to
     * $marker at $changeAt (changeAt < 0 or ≥ $lines: byte-identical body,
     * same function name — the hole-less fixture).
     */
    private function functionFile(
        string $name,
        int $lines,
        int $changeAt,
        string $marker = 'BASE',
    ): string {
        $body  = "<?php\nfunction big(\$s) {\n";
        for ($i = 0; $i < $lines; $i++) {
            $tag  = $i === $changeAt ? $marker : 'BASE';
            $body .= "    if (\$s === {$i}) { act('{$tag}_{$i}'); }\n";
        }
        $body .= "}\n";
        $file = $this->tmp . '/' . $name;
        file_put_contents($file, $body);
        return $file;
    }

    /** @return list<Block> */
    private function extract(string $file): array
    {
        $stmts     = (new AstParser())->parseCode((string)file_get_contents($file));
        // Synthetic-corpus extraction: the production 800-node maxSize
        // would fold these multi-hundred-line functions away entirely
        // (their inner single-line ifs are what [0] would return). The
        // windowing/lazy legs under test are extraction-budget-neutral;
        // production members that straddle the 240-line window do exist
        // within default budgets (simple-statement bodies).
        $extracted = (new BlockExtractor(minSize: 1, maxSize: 1000000))->extract($file, $stmts);
        $this->assertNotEmpty($extracted, "no block extracted from {$file}");
        $this->assertSame(
            \PhpParser\Node\Stmt\Function_::class,
            $extracted[0]->ast::class,
            'fixture must extract the WHOLE function as [0] or the window arms are vacuous',
        );
        return $extracted;
    }
}
