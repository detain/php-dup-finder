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
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\ReportWriteException;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

final class HtmlReporterTest extends TestCase
{
    public function testWritesIndexAndPerClusterPagesAndAssets(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);

            $this->assertFileExists("$dir/index.html");
            $this->assertFileExists("$dir/style.css");
            $this->assertFileExists("$dir/app.js");

            $clusterFiles = glob("$dir/cluster-*.html");
            $this->assertCount(count($report->clusters), $clusterFiles);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testIndexIncludesSearchInputAndSortableHeaders(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $html = (string)file_get_contents("$dir/index.html");
            $this->assertStringContainsString('<input type="search"', $html);
            $this->assertStringContainsString('data-sort="num"', $html);
            $this->assertStringContainsString('data-key="impact"', $html);
            $this->assertStringContainsString('class="copy"', $html);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testIndexIncludesMinimap(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $html = (string)file_get_contents("$dir/index.html");
            $this->assertStringContainsString('class="minimap"', $html);
            $this->assertStringContainsString('class="bar', $html);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testClusterPageHasSyntaxHighlightedCode(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $page = (string)file_get_contents("$dir/cluster-001.html");
            $this->assertStringContainsString('<span class="k">function</span>', $page, 'function keyword should be highlighted');
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testOptionalBlockHolesGetTaggedRowAndAbsentSpan(): void
    {
        $report = $this->buildOptionalReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $glob = glob("$dir/cluster-*.html");
            $this->assertNotEmpty($glob);

            $found = false;
            foreach ($glob as $page) {
                $body = (string)file_get_contents($page);
                if (str_contains($body, 'hole-optional_block')) {
                    $found = true;
                    $this->assertStringContainsString('class="badge optional"', $body, 'optional rows get a "type-3" badge');
                    $this->assertStringContainsString('class="absent"', $body, 'verified optional absence renders with .absent class (canonical row projected)');
                }
            }
            $this->assertTrue($found, 'expected at least one cluster page to render an optional_block row');
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testCssIncludesOptionalBlockStyling(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $css = (string)file_get_contents("$dir/style.css");
            $this->assertStringContainsString('.hole-optional_block', $css);
            $this->assertStringContainsString('.absent', $css);
            $this->assertStringContainsString('.badge.optional', $css);
        } finally {
            $this->rrmdir($dir);
        }
    }

    /**
     * @dataProvider provideHighlightPhpCases
     */
    public function testHighlightPhpKeywordIsolation(string $code, string $expectedFragment, string $forbiddenFragment): void
    {
        $refl = new \ReflectionMethod(HtmlReporter::class, 'highlightPhp');

        $output = $refl->invoke((new HtmlReporter()), $code);

        $this->assertStringContainsString($expectedFragment, $output);
        if ($forbiddenFragment !== '') {
            $this->assertStringNotContainsString($forbiddenFragment, $output);
        }
    }

    /** @return list<array{string, string, string}> */
    public static function provideHighlightPhpCases(): array
    {
        return [
            // The core bug: class="c" (HTML attribute) must not have the "class" word
            // wrapped as a keyword. The attribute value "c" should remain intact.
            'HTML attribute class value not corrupted' => [
                'class="c"',
                'class=<span class="s">&quot;c&quot;</span>',
                '<span class="k">class</span>',
            ],
            // Normal code with keyword gets highlighted.
            'normal keyword highlight' => [
                'function class() {}',
                '<span class="k">function</span>',
                '',
            ],
            // Number highlight is preserved.
            'number highlight' => [
                'return 42;',
                '<span class="n">42</span>',
                '',
            ],
            // String double-quoted gets wrapped.
            'double-quoted string' => [
                '"hello"',
                '<span class="s">&quot;hello&quot;</span>',
                '',
            ],
            // Keywords inside a single-line comment must NOT be wrapped.
            // The comment token is consumed atomically; subsequent keyword matches
            // continue from after the comment, so only text AFTER the comment line
            // could be highlighted (which is outside the comment visually).
            'keywords inside comment are not wrapped' => [
                '// new class returns string',
                '// new class returns string',
                '<span class="k">new</span>',
            ],
            // The word "class" inside a double-quoted string must NOT be wrapped.
            // The string token is consumed atomically; "class" inside it is never
            // presented to the keyword pattern.
            'keywords inside string are not wrapped' => [
                '$x = "class"',
                '<span class="s">&quot;class&quot;</span>',
                '<span class="k">class</span>',
            ],
        ];
    }

    public function testHighlightPhpOutputIsValidDom(): void
    {
        $refl = new \ReflectionMethod(HtmlReporter::class, 'highlightPhp');

        $cases = [
            'class="c"',
            'function class() {}',
            'return 42;',
            '"hello"',
        ];

        foreach ($cases as $code) {
            $output = $refl->invoke((new HtmlReporter()), $code);
            // Count opening and closing span tags — they must match.
            $openCount = substr_count($output, '<span ');
            $closeCount = substr_count($output, '</span>');
            $this->assertEquals(
                $openCount,
                $closeCount,
                "Mismatched span tags for input: $code\nOutput: $output",
            );
            // The class="c" attribute value should not have "class" wrapped as keyword.
            // Corruption would look like: class="c"><span class="k">class</span>
            $this->assertStringNotContainsString(
                'class="c"><span',
                $output,
                "Attribute corruption detected in: $code\nOutput: $output",
            );
        }
    }

    // ── output-038: unfetched posture on the HTML surface ────────────────────

    public function testUnreadableMemberRendersExplicitUnfetchedMarker(): void
    {
        $dir = sys_get_temp_dir() . '/phpdup-html-posture-' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $real = $dir . '/a.php';
        file_put_contents($real, "<?php\nfunction m() { return 1; }\n");
        $report = $this->postureReport($real, $dir . '/absent.php');
        $outDir = $dir . '/html';
        try {
            (new HtmlReporter(new SourceTextStore([$dir], warnOnSkip: false)))->writeTo($report, $outDir);
            $html = implode('', array_map(
                static fn(string $f): string => (string)file_get_contents($f),
                glob($outDir . '/*.html') ?: [],
            ));
            $this->assertNotSame('', $html);

            // output-016: silence-as-empty-cell and the placeholder string
            // are both replaced by one explicit, honestly-labelled marker.
            $this->assertStringContainsString('<span class="unfetched">[source not read: file not present]</span>', $html);
            $this->assertStringNotContainsString('<source unavailable', $html);
            $this->assertStringNotContainsString('<unreadable', $html);
            $this->assertStringContainsString('[diff skipped: source not read (member 2: file not present)]', $html);
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testPlantedSymlinkEscapeEmbedsNoOutRootContentInHtml(): void
    {
        $dir     = sys_get_temp_dir() . '/phpdup-html-escape-' . uniqid('', true);
        $outside = $dir . '-outside';
        mkdir($dir, 0o775, true);
        mkdir($outside, 0o775, true);
        $secret = $outside . '/secret.php';
        file_put_contents($secret, "<?php\nfunction m() { return 'TOP-SECRET-OUT-OF-ROOT'; }\n");
        $real = $dir . '/a.php';
        file_put_contents($real, "<?php\nfunction m() { return 1; }\n");
        $link = $dir . '/link.php';
        symlink($secret, $link);

        $report = $this->postureReport($real, $link);
        $outDir = $dir . '/html';
        try {
            $store = new SourceTextStore([$dir], warnOnSkip: false);
            (new HtmlReporter($store))->writeTo($report, $outDir);
            $html = implode('', array_map(
                static fn(string $f): string => (string)file_get_contents($f),
                glob($outDir . '/*.html') ?: [],
            ));

            // output-048 (SEC-06 read-side): the planted target's bytes
            // never reach the shareable artifact — grep finds nothing.
            $this->assertStringNotContainsString('TOP-SECRET-OUT-OF-ROOT', $html);
            $this->assertStringContainsString('[source not read: target escapes the scan root]', $html);
            $this->assertSame(["skipped symlinked path {$link} (target escapes the scan root)"], $store->skipNotes());
        } finally {
            $this->rrmdir($dir);
            $this->rrmdir($outside);
        }
    }

    /** Two-member cluster: first real, second the tested path. */
    private function postureReport(string $real, string $other): Report
    {
        $mk = static function (string $file): Block {
            $stmts = (new AstParser())->parseCode(is_file($file) ? (string)file_get_contents($file) : '<?php $x;');
            return new Block(
                file: $file, range: new LineRange(2, 2), kind: 'function',
                namespace: null, class: null, name: 'm', ast: $stmts[0],
            );
        };
        $cluster = new Cluster('S38', [$mk($real), $mk($other)], 1.0, false);
        $cluster->signature = 'function m(): int';
        return new Report(
            files: 2, blocks: 2, parseErrors: 0,
            clusters: [$cluster], config: Config::defaults([dirname($real)]),
        );
    }

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        return $this->runPipeline($config, exactOnly: true);
    }

    private function buildOptionalReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/optional'],
            exclude: Config::defaults([])->exclude,
            minBlockSize: 4,
            maxDocumentFrequency: 0.5,
            minClusterImpact: 1,
            lazyAst: false,
        );
        return $this->runPipeline($config, exactOnly: false);
    }

    private function runPipeline(Config $config, bool $exactOnly): Report
    {
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: $exactOnly))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------
    // output-041 (step output-S39): batching + shared-Differ seams, and
    // behavioral escape pins for the renderer (parsed-structure and
    // evasion properties — not snapshot coincidences).
    // ------------------------------------------------------------------

    /**
     * @param int $clusters how many 2-member clusters to build; member 2's
     *                      body differs from member 1's so every page's
     *                      renderDiff emits add/del lines
     * @return array{Report, string} report + its temp root (caller rrmdirs)
     */
    private function diffCapableReport(int $clusters): array
    {
        $root = sys_get_temp_dir() . '/phpdup-html-diff-' . uniqid();
        mkdir($root . '/a', 0o775, true);
        mkdir($root . '/b', 0o775, true);
        $mk = static function (string $file, int $i, int $delta): Block {
            $body = "<?php\nfunction m()\n{\n    return {$delta} + {$i};\n}\n";
            file_put_contents($file, $body);
            $stmts = (new AstParser())->parseCode($body);
            return new Block(
                file: $file, range: new LineRange(2, 4), kind: 'function',
                namespace: null, class: null, name: 'm', ast: $stmts[0],
            );
        };
        $list = [];
        for ($i = 0; $i < $clusters; $i++) {
            $cluster = new Cluster(
                'C' . $i,
                [$mk($root . '/a/f' . $i . '.php', $i, 1), $mk($root . '/b/f' . $i . '.php', $i, 2)],
                1.0,
                false,
            );
            $cluster->signature = 'function m(): int';
            $list[] = $cluster;
        }
        $report = new Report(
            files: 2 * $clusters, blocks: 2 * $clusters, parseErrors: 0,
            clusters: $list, config: Config::defaults([$root]),
        );
        return [$report, $root];
    }

    public function testBatchedAndStreamingWritersProduceIdenticalClusterDirs(): void
    {
        [$report, $root] = $this->diffCapableReport(5);
        $dirs = [];
        try {
            foreach ([HtmlReporter::DEFAULT_WRITE_BATCH, 1, 2] as $batch) {
                $dir = $root . '/out-' . $batch;
                (new HtmlReporter(null, $batch))->writeTo($report, $dir);
                $dirs[$batch] = $dir;
            }
            $ref = [];
            foreach (scandir($dirs[1]) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $ref[] = $f;
                }
            }
            sort($ref);
            $this->assertSame(
                ['app.js', 'cluster-001.html', 'cluster-002.html', 'cluster-003.html',
                    'cluster-004.html', 'cluster-005.html', 'index.html', 'style.css'],
                $ref,
            );
            foreach ([HtmlReporter::DEFAULT_WRITE_BATCH, 2] as $batch) {
                foreach ($ref as $f) {
                    $this->assertFileEquals(
                        $dirs[1] . '/' . $f,
                        $dirs[$batch] . '/' . $f,
                        "batch {$batch} diverged from streaming writer on {$f}",
                    );
                }
            }
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testWriteBatchSizeShapesTheFlushPattern(): void
    {
        [$report, $root] = $this->diffCapableReport(5);
        try {
            foreach ([['batch', 2, 3], ['streaming', 1, 5], ['single', 1000, 1]] as [$label, $batch, $want]) {
                $reporter = new HtmlReporter(null, $batch);
                $reporter->writeTo($report, $root . '/shape-' . $label);
                $this->assertSame($want, $reporter->pageWriteFlushes(), $label . ' flush count');
            }
            $empty = new HtmlReporter(null, 1);
            $empty->writeTo(
                new Report(files: 0, blocks: 0, parseErrors: 0, clusters: [], config: Config::defaults([$root])),
                $root . '/shape-empty',
            );
            $this->assertSame(0, $empty->pageWriteFlushes(), 'no pages, no flush');
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testZeroWriteBatchSizeFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HtmlReporter(null, 0);
    }

    public function testDifferIsBuiltOncePerRunAcrossDiffPages(): void
    {
        [$report, $root] = $this->diffCapableReport(4);
        try {
            $reporter = new HtmlReporter(null, 1);
            $reporter->writeTo($report, $root . '/run1');
            // Every page really rendered a diff (no vacuous pass): four
            // renderDiff calls, one Differ for the run.
            foreach (['cluster-001', 'cluster-002', 'cluster-003', 'cluster-004'] as $page) {
                $html = (string)file_get_contents($root . '/run1/' . $page . '.html');
                $this->assertStringContainsString('<span class="add">', $html, $page . ' rendered no diff');
                $this->assertStringContainsString('<span class="del">', $html, $page . ' rendered no diff');
            }
            $this->assertSame(1, $reporter->differBuilds());
            // A second run builds its own single Differ; the counter is
            // cumulative across runs by design.
            $reporter->writeTo($report, $root . '/run2');
            $this->assertSame(2, $reporter->differBuilds());
        } finally {
            $this->rrmdir($root);
        }
    }

    /**
     * Escape truth table: hostile strings in every user-controlled field of a
     * cluster (member paths/qualified names, signature, pattern tags, hole
     * placeholders/names/types/observed values) must survive the write + DOM
     * round-trip byte-exact, and must never forge an element or an href.
     * A broken escape truncates the parsed attribute or spawns a node — both
     * are asserted against the PARSED structure, not raw-page substrings.
     */
    public function testHostileFieldRoundTripThroughParsedDomWithoutForgingStructure(): void
    {
        $hostileFile  = '/evil"><a href=x>click</a>.php';
        $hostileName  = 'n"><textarea>';
        $hostileType  = '"string"';
        $hostileSig   = 'function evil($p): array /*"><img src=q onerror=1>*/';
        $hostileTag   = "sql-builder'><i>i</i>";
        $hostilePlace = '$__P0" onload=z';
        $hostileObs   = 'v"><object data=y>';

        $root = sys_get_temp_dir() . '/phpdup-html-hostile-' . uniqid();
        mkdir($root, 0o775, true);
        try {
            $mk = static function (string $file, string $name): Block {
                $stmts = (new AstParser())->parseCode("<?php\nfunction m()\n{\n    return 1;\n}\n");
                return new Block(
                    file: $file, range: new LineRange(2, 4), kind: 'function',
                    namespace: null, class: null, name: $name, ast: $stmts[0],
                );
            };
            $cluster = new Cluster('H1', [$mk($root . '/good.php', 'plain'), $mk($hostileFile, $hostileName)], 1.0, false);
            $cluster->signature    = $hostileSig;
            $cluster->patternTags  = [$hostileTag, 'controller-action'];
            $hole                  = new Hole($hostilePlace, 'literal', [$hostileObs, 'other'], true);
            $hole->suggestedName   = $hostileName;
            $hole->inferredType    = $hostileType;
            $cluster->holes        = [$hole];
            $report = new Report(
                files: 2, blocks: 2, parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([$root]),
            );

            $dir = $root . '/out';
            (new HtmlReporter())->writeTo($report, $dir);

            $index = $this->parseDom($dir . '/index.html');
            $page  = $this->parseDom($dir . '/cluster-001.html');

            // No scanned string forged an element on either surface.
            foreach (['index' => $index, 'cluster' => $page] as $label => $doc) {
                $x = new \DOMXPath($doc);
                $this->assertSame(0, $x->query('//script[not(@src)]')->length, $label . ': inline script forged');
                $this->assertSame(0, $x->query('//img')->length, $label . ': img forged');
                $this->assertSame(0, $x->query('//textarea')->length, $label . ': textarea forged');
                $this->assertSame(0, $x->query('//object')->length, $label . ': object forged');
                $this->assertSame(0, $x->query('//iframe')->length, $label . ': iframe forged');
                $this->assertSame(0, $x->query('//style')->length, $label . ': style element forged');
                // Hunted defect class: raw quote passthrough into an href.
                foreach ($x->query('//a/@href') as $href) {
                    $this->assertMatchesRegularExpression(
                        '#^(cluster-\d{3}\.html|index\.html)$#',
                        (string)$href->nodeValue,
                        $label . ': scanned bytes reached an href',
                    );
                }
            }

            // Parsed values round-trip the hostile strings byte-exact.
            $x = new \DOMXPath($index);
            $this->assertSame($hostileSig, $x->query('//button[@class="copy"]/@data-copy')->item(0)->nodeValue);
            $search = (string)$x->query('//tr/@data-search')->item(0)->nodeValue;
            $this->assertStringContainsString(strtolower($hostileFile), $search);
            $this->assertStringContainsString($hostileTag, $x->query('//table[@id="clusters"]//tbody//td[6]')->item(0)->nodeValue);
            $this->assertSame($hostileSig, trim((string)$x->query('//tbody//code')->item(0)->nodeValue));

            $x = new \DOMXPath($page);
            $this->assertSame($hostileSig, $x->query('//button[@class="copy"]/@data-copy')->item(0)->nodeValue);
            $this->assertSame($hostilePlace, $x->query('//tr[contains(@class,"hole-literal")]/@data-hole')->item(0)->nodeValue);
            $nameInput  = $x->query('//input[@class="hole-name"]')->item(0);
            $typeInput  = $x->query('//input[@class="hole-type"]')->item(0);
            $this->assertNotNull($nameInput, 'hole name input missing (structure forged?)');
            $this->assertSame($hostileName, $nameInput->getAttribute('value'));
            $this->assertSame($hostileName, $nameInput->getAttribute('data-default'));
            $this->assertSame($hostileType, $typeInput->getAttribute('value'));
            $this->assertStringContainsString($hostileObs, (string)$x->query('//tr[contains(@class,"hole-literal")]/td[5]')->item(0)->nodeValue);
            $this->assertStringContainsString($hostileFile, (string)$x->query('//div[@class="members"]//h3')->item(1)->nodeValue);
            $this->assertSame(
                $hostileName,
                trim(explode('·', (string)$x->query('//div[@class="members"]//p[@class="meta"]')->item(1)->nodeValue)[0]),
            );
        } finally {
            $this->rrmdir($root);
        }
    }

    /**
     * FRAME_DANGER-class bytes (NUL, GS, RS, US, ESC) ride the HTML surface
     * from observed values/signature/tags. Rendering posture: htmlspecialchars
     * neutralises the quote/angle bytes that could escape a context; the bare
     * control bytes themselves are inert inside text/attribute content — they
     * can never open or close a tag. (once-S60 landed the queued non-UTF-8
     * hygiene upgrade on top — ENT_SUBSTITUTE now marks invalid bytes with
     * U+FFFD; these valid-UTF-8 control bytes keep riding verbatim exactly
     * as pinned here. DOM parsing of a NUL-bearing
     * page is unreliable in libxml, so the pin asserts evasion counts on the
     * raw page instead of a parse.)
     */
    public function testFrameDangerControlBytesCannotForgeElementsOnHtmlSurface(): void
    {
        $frameDanger = "\x00\x1D\x1E\x1F\x1b";
        $payload     = $frameDanger . '<script>alert(1)</script>"\'><i>evil</i>';

        $root = sys_get_temp_dir() . '/phpdup-html-frame-' . uniqid();
        mkdir($root, 0o775, true);
        try {
            $stmts = (new AstParser())->parseCode("<?php\nfunction m()\n{\n    return 1;\n}\n");
            $a = new Block(file: $root . '/a.php', range: new LineRange(2, 4), kind: 'function', namespace: null, class: null, name: 'm', ast: $stmts[0]);
            $b = new Block(file: $root . '/b.php', range: new LineRange(2, 4), kind: 'function', namespace: null, class: null, name: 'm', ast: $stmts[0]);
            file_put_contents($root . '/a.php', "<?php\nfunction m()\n{\n    return 1;\n}\n");
            file_put_contents($root . '/b.php', "<?php\nfunction m()\n{\n    return 2;\n}\n");

            $cluster = new Cluster('F1', [$a, $b], 1.0, false);
            $cluster->signature   = 'function evil(): int // ' . $payload;
            $cluster->patternTags = [$payload];
            $hole                 = new Hole('$__P0', 'literal', [$payload, 'x'], true);
            $cluster->holes       = [$hole];
            $report = new Report(
                files: 2, blocks: 2, parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([$root]),
            );

            $dir = $root . '/out';
            (new HtmlReporter())->writeTo($report, $dir);

            foreach (['index', 'cluster-001'] as $page) {
                $html = strtolower((string)file_get_contents($dir . '/' . $page . '.html'));
                // The only script markup on a page is the static app.js loader.
                $this->assertSame(1, substr_count($html, '<script'), $page . ': forged a script tag');
                $this->assertSame(1, substr_count($html, '</script'), $page . ': forged a script close');
                $this->assertSame(0, substr_count($html, '<img'), $page . ': forged an img');
                $this->assertSame(0, substr_count($html, '<i>'), $page . ': forged emphasis via broken quote');
                // The breakout attempt survives only in entity form…
                $this->assertStringContainsString('&lt;script&gt;', $html, $page . ': < > not entity-encoded');
                // …and every span stays balanced (no half-written wrap).
                $this->assertSame(
                    substr_count($html, '<span'),
                    substr_count($html, '</span'),
                    $page . ': span balance broken',
                );
            }
            // Control bytes ride the file verbatim (documented inert posture:
            // they cannot interact with quote escaping, hence cannot escape
            // an attribute; browser surfaces render them as inert text).
            $clusterHtml = (string)file_get_contents($dir . '/cluster-001.html');
            $this->assertGreaterThan(0, substr_count($clusterHtml, "\x00"));
        } finally {
            $this->rrmdir($root);
        }
    }

    /**
     * The capture-group classification keeps the atomicity guarantees the
     * single-pass design promises, observed through the real page surface:
     * a float in code is wrapped, digits inside strings and comments are not.
     */
    public function testNumberArmsWrapCodeDigitsButNotStringOrCommentDigits(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-html-nums-' . uniqid();
        mkdir($root . '/a', 0o775, true);
        mkdir($root . '/b', 0o775, true);
        $body = static fn (int $extra): string => "<?php\nfunction m()\n{\n    \$x = 1.5;\n    \$y = \"\$42\";\n    // 42 tail use\n    \$z = 'return';\n    return {$extra};\n}\n";
        file_put_contents($root . '/a/f.php', $body(1));
        file_put_contents($root . '/b/f.php', $body(2));
        $mk = static function (string $file): Block {
            $stmts = (new AstParser())->parseCode((string)file_get_contents($file));
            return new Block(
                file: $file, range: new LineRange(2, 8), kind: 'function',
                namespace: null, class: null, name: 'm', ast: $stmts[0],
            );
        };
        $cluster = new Cluster('N1', [$mk($root . '/a/f.php'), $mk($root . '/b/f.php')], 1.0, false);
        try {
            $dir = $root . '/out';
            (new HtmlReporter())->writeTo(new Report(
                files: 2, blocks: 2, parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([$root]),
            ), $dir);

            $html = (string)file_get_contents($dir . '/cluster-001.html');
            $this->assertStringContainsString('<span class="n">1.5</span>', $html, 'float not wrapped by the num arm');
            $this->assertStringContainsString('<span class="n">2</span>', $html, 'trailing return int not wrapped');
            $this->assertStringContainsString('&quot;$42&quot;', $html);
            $this->assertStringNotContainsString('<span class="n">42</span>', $html, 'digits inside string/comment were re-classified');
            $this->assertStringNotContainsString('<span class="n">$42', $html);
        } finally {
            $this->rrmdir($root);
        }
    }

    // ------------------------------------------------------------------
    // output-053 (step output-S40): lexer-driven highlighting — atomic
    // strings with embedded newlines/&, one de-duplicated shared keyword
    // source, every comment style emitted through the .c class, and the
    // sortable-header column-index contract pinned on the generated
    // markup (parsed structure + attribute correctness, not substrings).
    // ------------------------------------------------------------------

    /**
     * One-member cluster over $body's lines 2..$endLine plus its temp root
     * (caller rrmdirs). The member source reaches highlightPhp verbatim.
     *
     * @return array{Report, string}
     */
    private function lexerReport(string $body, int $endLine): array
    {
        $root = sys_get_temp_dir() . '/phpdup-html-lexer-' . uniqid();
        mkdir($root, 0o775, true);
        $file = $root . '/f.php';
        file_put_contents($file, $body);
        $stmts = (new AstParser())->parseCode($body);
        $block = new Block(
            file: $file, range: new LineRange(2, $endLine), kind: 'function',
            namespace: null, class: null, name: 'm', ast: $stmts[0],
        );
        $report = new Report(
            files: 1, blocks: 1, parseErrors: 0,
            clusters: [new Cluster('L1', [$block], 1.0, false)],
            config: Config::defaults([$root]),
        );
        return [$report, $root];
    }

    public function testSingleQuotedStringWithNewlineAndAmpersandIsTokenizedAtomically(): void
    {
        // Legal PHP that the old escape-then-regex design could not match:
        // the single-quote arm was excluded across newlines and any `&`,
        // so this string's words leaked into the keyword/number arms and
        // the string never arrived as one unit. The lexer consumes it as
        // one T_CONSTANT_ENCAPSED_STRING.
        $body = "<?php\nfunction m()\n{\n    \$msg = 'use & dispatch\nstill \"one\" string';\n    return \$msg;\n}\n";
        [$report, $root] = $this->lexerReport($body, 7);
        $dir = $root . '/out';
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $doc = $this->parseDom($dir . '/cluster-001.html');
            $x = new \DOMXPath($doc);

            $spans = $x->query('//pre[@class="src"]//span[@class="s"]');
            $this->assertNotNull($spans);
            $this->assertSame(1, $spans->length, 'multi-line single-quoted string is not one atomic .s token');
            $this->assertSame(
                "'use & dispatch\nstill \"one\" string'",
                $spans->item(0)->textContent,
                'entity round-trip through the parsed DOM must restore the raw token bytes',
            );
            $this->assertSame(0, $x->query('//span[@class="s"]//span')->length, 'string interior was re-classified');
            $this->assertSame(0, $x->query('//span[@class="k"][.="use"]')->length, 'keyword leaked out of the string');
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testEveryCommentStyleRendersWrappedInCommentClass(): void
    {
        // output-053: style.css always defined .c but the highlighter never
        // emitted it. All PHP comment styles lex as single tokens; each must
        // arrive as one .c span, bytes round-tripped, interior words left
        // unclassified, code numbers still wrapping next to them.
        $body = "<?php\nfunction m()\n{\n    // use & 42 note\n    # hash dispatch 7\n    /* block\n       use 99 */\n    return 5;\n}\n";
        [$report, $root] = $this->lexerReport($body, 9);
        $dir = $root . '/out';
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $doc = $this->parseDom($dir . '/cluster-001.html');
            $x = new \DOMXPath($doc);

            $c = $x->query('//pre[@class="src"]//span[@class="c"]');
            $this->assertNotNull($c);
            $this->assertSame(3, $c->length, 'one .c span per comment style');
            $this->assertSame('// use & 42 note', $c->item(0)->textContent);
            $this->assertSame('# hash dispatch 7', $c->item(1)->textContent);
            $this->assertSame("/* block\n       use 99 */", $c->item(2)->textContent, 'block-comment newline preserved');
            $this->assertSame(0, $x->query('//span[@class="c"]//span')->length, 'comment interior was re-classified');

            $n = $x->query('//pre[@class="src"]//span[@class="n"]');
            $this->assertNotNull($n);
            $this->assertSame('5', $n->item($n->length - 1)->textContent, 'code numbers still wrap beside comments');

            $css = (string)file_get_contents($dir . '/style.css');
            $this->assertStringContainsString('.signature .c, .src .c', $css, 'the emitted class must have the shipped style');
        } finally {
            $this->rrmdir($root);
        }
    }

    /**
     * php-dup-finder-once-069 (once-S60): a legacy Latin-1 member's source
     * silently lost its primary evidence — every token carrying an invalid
     * UTF-8 byte blanked itself (htmlspecialchars without ENT_SUBSTITUTE
     * returns ''), so string and comment bytes just disappeared from
     * `<pre class="src">` with no trace while valid neighbours rendered.
     * Posture now: each offending byte arrives as one visible U+FFFD, its
     * token (atomicity from output-S40) and every other token stay put,
     * and the finished page is valid UTF-8.
     */
    public function testLatin1MemberSourceKeepsEveryTokenWithVisibleSubstitutes(): void
    {
        $body = "<?php\nfunction m()\n{\n    return \"caf\xe9\"; // fin r\xe9sum\xe9\n}\n";
        [$report, $root] = $this->lexerReport($body, 5);
        $dir = $root . '/out';
        try {
            (new HtmlReporter())->writeTo($report, $dir);

            $page = (string)file_get_contents($dir . '/cluster-001.html');
            $this->assertTrue(mb_check_encoding($page, 'UTF-8'), 'a rendered page must not carry raw invalid bytes');

            $doc = $this->parseDom($dir . '/cluster-001.html');
            $x   = new \DOMXPath($doc);

            $pre = $x->query('//pre[@class="src"]')->item(0);
            $this->assertNotNull($pre);
            $this->assertNotSame('', trim((string)$pre->textContent), 'empty <pre class="src"> — evidence loss is back');
            $this->assertStringContainsString('return', (string)$pre->textContent, 'the valid neighbours must still render');

            $sub = "\u{FFFD}";
            $s   = $x->query('//pre[@class="src"]//span[@class="s"]');
            $this->assertNotNull($s);
            $this->assertSame(1, $s->length);
            $this->assertSame('"caf' . $sub . '"', $s->item(0)->textContent,
                'the Latin-1 string literal must arrive as one token with a visible substitute, never blanked');

            $c = $x->query('//pre[@class="src"]//span[@class="c"]');
            $this->assertNotNull($c);
            $this->assertSame(1, $c->length);
            $this->assertSame('// fin r' . $sub . 'sum' . $sub, $c->item(0)->textContent,
                'one U+FFFD per maximal invalid subsequence (W3C substitution) inside the atomic comment token');
        } finally {
            $this->rrmdir($root);
        }
    }

    /**
     * once-S60 adversarial bar on the surface upgrade, two posture legs:
     * (1) PARSED leg — a payload mixing attribute breakout and invalid
     * UTF-8 (standalone lead + truncated multi-byte lead) rides the index
     * data-copy default-flag site, the signature-preview <code> cell (the
     * cell's firstLine() derivation was retired by once-S84 — preview==
     * payload is the affordance rule now, this pin's round-trip holds for
     * both shapes), and the
     * highlightPhp ENT_QUOTES|ENT_SUBSTITUTE path: parsed DOM stays
     * honest (no forged elements, round-trip identity with visible
     * substitutes, nothing blanked). (2) RAW leg — the same payload plus
     * the 0x1F FRAME-class byte: substitution never touches valid UTF-8
     * controls, so the byte rides verbatim as the S39-era doctrine
     * documents; libxml rejects such bytes on parse (the very reason the
     * FRAME_DANGER pin asserts on the raw page), so this leg carries raw
     * evasion/entity/balance counts, not a parse.
     */
    public function testInvalidUtf8AndBreakoutMixCannotBlankOrForgeOnAnyHtmlFlagPath(): void
    {
        $breakout = '" id="x"><img src=1 onerror=alert(1)>';
        // W3C maximal-subpart substitution: \xE9 → one U+FFFD; the lone
        // \xC3 lead (followed by '(') → one U+FFFD; '(' is ASCII and stays.
        $badUtf8     = 'caf' . "\xE9" . "\xC3" . '(end';
        $substituted = $breakout . 'caf' . "\u{FFFD}" . "\u{FFFD}" . '(end';
        $withControl = $breakout . "\x1F" . $badUtf8;

        $body = "<?php\nfunction m()\n{\n    return 1;\n}\n";
        $root = sys_get_temp_dir() . '/phpdup-html-s60mix-' . uniqid();
        mkdir($root, 0o775, true);
        try {
            // --- (1) parsed leg: breakout + invalid UTF-8, no raw control ---
            $dir = $root . '/parsed';
            (new HtmlReporter())->writeTo($this->s60SignatureReport($root, 'parsed', $body, $breakout . $badUtf8), $dir);

            $indexRaw = (string)file_get_contents($dir . '/index.html');
            $pageRaw  = (string)file_get_contents($dir . '/cluster-001.html');
            foreach (['index' => $indexRaw, 'cluster' => $pageRaw] as $label => $raw) {
                $this->assertSame(0, substr_count(strtolower($raw), '<img'), $label . ': forged an img');
                $this->assertSame(1, substr_count(strtolower($raw), '<script'), $label . ': forged a script tag');
                $this->assertTrue(mb_check_encoding($raw, 'UTF-8'), $label . ': invalid bytes reached the page raw');
            }
            $this->assertSame(
                substr_count($pageRaw, '<span'),
                substr_count($pageRaw, '</span'),
                'span balance broken on the highlight path',
            );

            $index = $this->parseDom($dir . '/index.html');
            $xi    = new \DOMXPath($index);
            $copy  = $xi->query('//button[@class="copy"]/@data-copy')->item(0);
            $this->assertNotNull($copy);
            $this->assertSame($substituted, (string)$copy->nodeValue,
                'data-copy identity must round-trip with visible substitutes, never blanked');
            $this->assertNotSame('', trim((string)$xi->query('//tbody//code')->item(0)->nodeValue));

            $page = $this->parseDom($dir . '/cluster-001.html');
            $xp   = new \DOMXPath($page);
            $sig  = $xp->query('//pre[@class="signature"]')->item(0);
            $this->assertNotNull($sig);
            $this->assertStringContainsString('caf' . "\u{FFFD}" . "\u{FFFD}" . '(end', (string)$sig->textContent,
                'the highlightPhp substitute arm must restore the identity bytes beside the escaped breakout');
            $this->assertNotSame('', trim((string)$sig->textContent), 'blanked signature block — once-069 class');

            // --- (2) raw leg: the FRAME-class byte keeps riding verbatim ---
            $dirRaw = $root . '/ctrl';
            (new HtmlReporter())->writeTo($this->s60SignatureReport($root, 'ctrl', $body, $withControl), $dirRaw);
            $ctrlPage = (string)file_get_contents($dirRaw . '/cluster-001.html');
            $this->assertGreaterThan(0, substr_count($ctrlPage, "\x1F"), 'documented inert control byte vanished');
            $this->assertSame(0, substr_count(strtolower($ctrlPage), '<img'), 'ctrl page: forged an img');
            $this->assertStringContainsString('&lt;img', $ctrlPage, 'breakout survives only in entity form');
            $this->assertTrue(mb_check_encoding($ctrlPage, 'UTF-8'), 'ctrl page lost UTF-8 validity');
        } finally {
            $this->rrmdir($root);
        }
    }

    /** Single-member report rooted at a fresh subdir of $root, member f.php, signature $signature. */
    private function s60SignatureReport(string $root, string $slug, string $body, string $signature): Report
    {
        $dir = $root . '/' . $slug;
        mkdir($dir, 0o775, true);
        $file  = $dir . '/f.php';
        file_put_contents($file, $body);
        $stmts = (new AstParser())->parseCode($body);
        $block = new Block(
            file: $file, range: new LineRange(2, 4), kind: 'function',
            namespace: null, class: null, name: 'm', ast: $stmts[0],
        );
        $cluster            = new Cluster('S60X-' . $slug, [$block], 1.0, false);
        $cluster->signature = $signature;
        return new Report(
            files: 1, blocks: 1, parseErrors: 0,
            clusters: [$cluster], config: Config::defaults([$dir]),
        );
    }

    public function testKeywordListIsASingleDeduplicatedSharedSource(): void
    {
        $class = new \ReflectionClass(HtmlReporter::class);
        $kw = (array) $class->getConstant('KEYWORDS');
        $this->assertNotEmpty($kw, 'KEYWORDS const missing — the highlighter lost its shared source');
        $this->assertSame(array_values($kw), array_values(array_unique($kw)), 'shared keyword list carries a duplicate');

        $counts = array_count_values(array_map('strtolower', $kw));
        $this->assertArrayHasKey('use', $counts, 'the import/trait keyword left the list');
        $this->assertSame(1, $counts['use'], 'output-053: the old two-arm regex carried use twice — one list, one entry');

        // The grep assert of the finding, made permanent: no second quoted
        // keyword entry survives anywhere in the renderer source.
        $src = (string) file_get_contents((string) $class->getFileName());
        $this->assertSame(1, substr_count($src, "'use'"), 'a second quoted entry means a second keyword source exists');
    }

    public function testKeywordArmColorsEveryOccurrenceFromTheSharedList(): void
    {
        $refl = new \ReflectionMethod(HtmlReporter::class, 'highlightPhp');

        $out = (string) $refl->invoke(new HtmlReporter(), "use Foo\\Bar;\nclass C\n{\n    use T;\n}");
        $this->assertSame(2, substr_count($out, '<span class="k">use</span>'), 'both keyword tokens from the shared list wrap once each');
        $this->assertStringContainsString('<span class="k">class</span>', $out);

        // PHP matches keywords case-insensitively; the single list is lower
        // case and the arm compares lower-cased token text.
        $upper = (string) $refl->invoke(new HtmlReporter(), 'IF ($x) { RETURN 1; }');
        $this->assertStringContainsString('<span class="k">IF</span>', $upper);
        $this->assertStringContainsString('<span class="k">RETURN</span>', $upper);
    }

    public function testSortableHeaderPositionsMatchBodyRowCells(): void
    {
        // Column-index contract (output-053): app.js sorts by the clicked
        // header's ABSOLUTE position among the thead cells — the old code
        // used its rank among *sortable* headers, correct only while the
        // non-sortable column stayed last. This pins (a) the generated
        // header markup: which positions are sortable and under which key;
        // (b) body/header cell parity with the data the sorted columns
        // promise at those positions; (c) the shipped JS deriving the
        // index from the header position itself.
        $report = $this->buildReport();
        $dir = sys_get_temp_dir() . '/phpdup-html-sort-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $doc = $this->parseDom($dir . '/index.html');
            $x = new \DOMXPath($doc);

            $ths = $x->query('//table[@id="clusters"]/thead/tr/th');
            $this->assertNotNull($ths);
            $contract = [];
            foreach ($ths as $pos => $th) {
                $this->assertInstanceOf(\DOMElement::class, $th);
                if ($th->hasAttribute('data-sort')) {
                    $contract[$pos] = [$th->getAttribute('data-key'), $th->getAttribute('data-sort')];
                }
            }
            $this->assertSame([
                0 => ['num', 'num'],
                1 => ['size', 'num'],
                2 => ['similarity', 'num'],
                3 => ['impact', 'num'],
                4 => ['confidence', 'num'],
                5 => ['patterns', 'text'],
            ], $contract, 'sortable header positions/keys drifted from the contract app.js reads');
            $signatureTh = $ths->item(6);
            $this->assertInstanceOf(\DOMElement::class, $signatureTh);
            $this->assertFalse($signatureTh->hasAttribute('data-sort'), 'Signature column must stay non-sortable');

            $rows = $x->query('//table[@id="clusters"]/tbody/tr');
            $this->assertNotNull($rows);
            $this->assertGreaterThan(0, $rows->length, 'no rows to pin parity against');
            foreach ($rows as $tr) {
                $this->assertInstanceOf(\DOMElement::class, $tr);
                $this->assertSame(7, $x->query('td', $tr)->length ?? 0, 'row/header cell parity broken');
            }

            $first = $report->clusters[0];
            $cells0 = $x->query('td', $rows->item(0));
            $this->assertNotNull($cells0);
            $this->assertSame('1', $cells0->item(0)->getAttribute('data-num'));
            $this->assertSame((string) $first->size(), $cells0->item(1)->getAttribute('data-num'));
            $this->assertSame((string) $first->similarity, $cells0->item(2)->getAttribute('data-num'));
            $this->assertSame((string) $first->impact, $cells0->item(3)->getAttribute('data-num'));
            $this->assertSame((string) $first->confidence, $cells0->item(4)->getAttribute('data-num'));
            $this->assertSame(implode(', ', $first->patternTags), trim((string) $cells0->item(5)->textContent));

            $js = (string) (new \ReflectionMethod(HtmlReporter::class, 'js'))->invoke(new HtmlReporter());
            $this->assertStringContainsString('Column-index contract', $js, 'the JS contract must ship with the asset');
            $this->assertStringContainsString('Array.prototype.indexOf.call(th.parentNode.children, th)', $js);
            $this->assertStringNotContainsString('row.cells[idx]', $js, 'the rank-indexed read is the defect this step removed');
        } finally {
            $this->rrmdir($dir);
        }
    }

    // ------------------------------------------------------------------
    // php-dup-finder-once-104 (step once-S84): affordance truth. Every
    // instruction names a control that ships; the copy preview shows
    // exactly the bytes its button copies; a rejected clipboard write is
    // visible as a failure instead of a 'copied!'; a zero-cluster index
    // says so in words; a null signature carries no dead copy button.
    // Pins are structural (parsed DOM / stable JS markers), not rendered-
    // text mirrors; escape-order sites honor the once-S60 posture.
    // ------------------------------------------------------------------

    public function testIndexHintNamesTheControlsThatActuallyShip(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-html-hint-' . uniqid();
        try {
            (new HtmlReporter())->writeTo($report, $dir);
            $doc = $this->parseDom($dir . '/index.html');
            $x   = new \DOMXPath($doc);

            $hintNode = $x->query('//div[@class="controls"]//span[@class="hint"]')->item(0);
            $this->assertNotNull($hintNode, 'controls hint missing');
            $hint = (string)$hintNode->textContent;

            // The lie this step retired: there has never been a hover-copy
            // control — the real affordance is the ⎘ button.
            $this->assertStringNotContainsString('Hover', $hint, 'hint advertises a control the page does not ship');
            $this->assertStringContainsString('⎘', $hint, 'hint must name the copy control that exists');
            $this->assertStringContainsString('column header to sort', $hint);

            // Both promises have live witnesses on the page.
            $this->assertGreaterThan(0, $x->query('//th[@data-sort]')->length, 'sort claim without a sortable header');
            $this->assertGreaterThan(0, $x->query('//button[@class="copy"]')->length, 'copy claim without a copy button');
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testCopyPreviewIsExactlyTheBytesItsButtonCopies(): void
    {
        // output-S37's de-flatten makes shipped signatures single-line, but
        // the preview/payload identity is the affordance rule for ANY shape:
        // the old firstLine()-only preview silently copied bytes it never
        // showed. Multi-line fixture pins the rule structurally.
        $body = "<?php\nfunction m()\n{\n    return 1;\n}\n";
        $multiLine = "function m(string \$s): string\n{\n    return \$s;\n}";
        $root = sys_get_temp_dir() . '/phpdup-html-preview-' . uniqid();
        mkdir($root, 0o775, true);
        $dir = $root . '/out';
        try {
            (new HtmlReporter())->writeTo($this->s60SignatureReport($root, 'preview', $body, $multiLine), $dir);

            $doc = $this->parseDom($dir . '/index.html');
            $x   = new \DOMXPath($doc);
            $btn  = $x->query('//button[@class="copy"]')->item(0);
            $code = $x->query('//tbody//code')->item(0);
            $this->assertNotNull($btn);
            $this->assertNotNull($code);

            $this->assertSame($multiLine, (string)$code->textContent, 'preview is not the full copied text');
            $this->assertSame($multiLine, $btn->getAttribute('data-copy'), 'payload is not the previewed text');
            // The title promise matches the payload it carries.
            $this->assertStringContainsString('signature', (string)$btn->getAttribute('title'));
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testNullSignatureRowShipsNoDeadCopyButtonButAnHonestMarker(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-html-nulldup-' . uniqid();
        mkdir($root . '/a', 0o775, true);
        mkdir($root . '/b', 0o775, true);
        try {
            $mkBlock = static function (string $file): Block {
                file_put_contents($file, "<?php\nfunction m()\n{\n    return 1;\n}\n");
                $stmts = (new AstParser())->parseCode((string)file_get_contents($file));
                return new Block(
                    file: $file, range: new LineRange(2, 4), kind: 'function',
                    namespace: null, class: null, name: 'm', ast: $stmts[0],
                );
            };
            $nullCluster = new Cluster('N-NULL', [$mkBlock($root . '/a/f.php')], 1.0, false);
            // $signature stays null — the finding's dead-button shape.
            $realCluster = new Cluster('N-REAL', [$mkBlock($root . '/b/f.php')], 1.0, false);
            $realCluster->signature = 'function m(): int';
            $report = new Report(
                files: 2, blocks: 2, parseErrors: 0,
                clusters: [$nullCluster, $realCluster], config: Config::defaults([$root]),
            );

            $dir = $root . '/out';
            (new HtmlReporter())->writeTo($report, $dir);
            $doc = $this->parseDom($dir . '/index.html');
            $x   = new \DOMXPath($doc);

            // No live button carries an empty payload anywhere on the page.
            $this->assertSame(0, $x->query('//button[@class="copy" and @data-copy=""]')->length,
                'dead copy button (data-copy="") is back — once-104 class');
            $this->assertSame(1, $x->query('//button[@class="copy"]')->length,
                'exactly the real-signature row keeps a copy button');

            $rows = $x->query('//table[@id="clusters"]/tbody/tr');
            $this->assertNotNull($rows);
            $this->assertSame(2, $rows->length);
            $nullRow = $rows->item(0);
            $this->assertInstanceOf(\DOMElement::class, $nullRow);
            $this->assertSame(7, $x->query('td', $nullRow)->length, 'column contract broken by the marker row');
            $this->assertSame(0, $x->query('.//button', $nullRow)->length);
            $marker = $x->query('.//em[@class="no-signature"]', $nullRow);
            $this->assertNotNull($marker);
            $this->assertSame(1, $marker->length, 'null-signature row lost its honest marker');
            $this->assertStringContainsString('no suggested signature', strtolower((string)$marker->item(0)->textContent));

            // The detail page for the null-signature cluster keeps no orphan copy button either.
            $page = $this->parseDom($dir . '/cluster-001.html');
            $xp   = new \DOMXPath($page);
            $this->assertSame(0, $xp->query('//button[@class="copy"]')->length);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testZeroClusterIndexRendersHonestEmptyStateRowNotAnEmptyTbody(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-html-zero-' . uniqid();
        mkdir($root, 0o775, true);
        try {
            $report = new Report(
                files: 0, blocks: 0, parseErrors: 0,
                clusters: [], config: Config::defaults([$root]),
            );
            $dir = $root . '/out';
            (new HtmlReporter())->writeTo($report, $dir);

            $doc = $this->parseDom($dir . '/index.html');
            $x   = new \DOMXPath($doc);
            $rows = $x->query('//table[@id="clusters"]/tbody/tr');
            $this->assertNotNull($rows);
            $this->assertSame(1, $rows->length, 'zero-cluster tbody is silent again');
            $row = $rows->item(0);
            $this->assertInstanceOf(\DOMElement::class, $row);
            $this->assertStringContainsString('empty-state', $row->getAttribute('class'));
            $tds = $x->query('td', $row);
            $this->assertNotNull($tds);
            $this->assertSame(1, $tds->length);
            $this->assertSame('7', $tds->item(0)->getAttribute('colspan'));
            $this->assertStringContainsString('No clusters found', (string)$tds->item(0)->textContent);

            // app.js keeps the sentence out of the sortable/filterable pool
            // (the 1:1 cell contract must never be applied to a colspan row).
            $js = (string)(new \ReflectionMethod(HtmlReporter::class, 'js'))->invoke(new HtmlReporter());
            $this->assertStringContainsString("tbody.querySelectorAll('tr:not(.empty-state)')", $js);
            $this->assertStringContainsString("document.querySelectorAll('#clusters tbody tr:not(.empty-state)')", $js);

            $css = (string)(new \ReflectionMethod(HtmlReporter::class, 'css'))->invoke(new HtmlReporter());
            $this->assertStringContainsString('.empty-state td', $css);

            // And a non-empty report must NOT grow the row.
            $report2 = $this->buildReport();
            $dir2 = $root . '/full';
            (new HtmlReporter())->writeTo($report2, $dir2);
            $doc2 = $this->parseDom($dir2 . '/index.html');
            $x2   = new \DOMXPath($doc2);
            $this->assertSame(0, $x2->query('//tr[contains(@class,"empty-state")]')->length);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testHoleHelpNamesTheExportButtonsOwnLabel(): void
    {
        $root = sys_get_temp_dir() . '/phpdup-html-help-' . uniqid();
        mkdir($root . '/a', 0o775, true);
        mkdir($root . '/b', 0o775, true);
        try {
            $mkBlock = static function (string $file, int $delta): Block {
                file_put_contents($file, "<?php\nfunction m()\n{\n    return {$delta};\n}\n");
                $stmts = (new AstParser())->parseCode((string)file_get_contents($file));
                return new Block(
                    file: $file, range: new LineRange(2, 4), kind: 'function',
                    namespace: null, class: null, name: 'm', ast: $stmts[0],
                );
            };
            $cluster = new Cluster('HELP', [$mkBlock($root . '/a/f.php', 1), $mkBlock($root . '/b/f.php', 2)], 1.0, false);
            $cluster->signature = 'function m(): int';
            $cluster->holes     = [new Hole('$__P0', 'literal', ['1', '2'], true)];
            $report = new Report(
                files: 2, blocks: 2, parseErrors: 0,
                clusters: [$cluster], config: Config::defaults([$root]),
            );

            $dir = $root . '/out';
            (new HtmlReporter())->writeTo($report, $dir);
            $doc = $this->parseDom($dir . '/cluster-001.html');
            $x   = new \DOMXPath($doc);

            $btn = $x->query('//button[@class="copy-tweaks"]')->item(0);
            $this->assertNotNull($btn, 'export button missing');
            $label = trim((string)$btn->textContent);

            // The help sentence's copy-named <em> must be the button's OWN
            // label — not a paraphrase a hover/tooltip hunt cannot finish.
            $copyEm = null;
            $ems = $x->query('//p[@class="hole-help"]//em');
            $this->assertNotNull($ems);
            foreach ($ems as $em) {
                if (stripos((string)$em->textContent, 'copy') !== false) {
                    $copyEm = trim((string)$em->textContent);
                    break;
                }
            }
            $this->assertNotNull($copyEm, 'help paragraph names no copy control at all');
            $this->assertSame($label, $copyEm, 'help text misnames the export button');
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testClipboardFlashesAreGatedOnTheAwaitedWriteVerdict(): void
    {
        $js = (string)(new \ReflectionMethod(HtmlReporter::class, 'js'))->invoke(new HtmlReporter());

        // One awaited clipboard site feeds every flash — the legacy
        // fallback's boolean is the verdict, a failed copy never reads done.
        $this->assertSame(1, substr_count($js, 'await navigator.clipboard.writeText('),
            'clipboard writes must flow through one awaited verdict path');
        $this->assertStringContainsString('const writeClipboard = async (text) =>', $js);
        $this->assertStringContainsString('return legacy === true;', $js);

        // Both copy paths gate their visible flash on that verdict.
        $this->assertSame(2, substr_count($js, '= await writeClipboard('),
            'button.copy and copy-tweaks must both consume the verdict');
        $this->assertStringContainsString("btn.textContent = ok ? '✓' : '✗ copy failed';", $js);
        $this->assertStringContainsString("status.textContent = ok ? 'copied!' : 'copy failed';", $js);

        // The retired lie: success reveal after an ignored rejection.
        $this->assertStringNotContainsString(
            'try { await navigator.clipboard.writeText(json); } catch (e) { /* ignore */ }',
            $js,
            'unconditional success flash is back',
        );

        // Failure is styled, not just worded.
        $css = (string)(new \ReflectionMethod(HtmlReporter::class, 'css'))->invoke(new HtmlReporter());
        $this->assertStringContainsString('.copy-status.failed', $css);
    }

    // ------------------------------------------------------------------
    // php-dup-finder-once-074 (step once-S65): dir-owned stale-page sweep
    // + honest mkdir collision on the directory writer. One-generation law:
    // a re-run into a reused dir presents EXACTLY this run's pages — the
    // sweep removes only names THIS reporter can mint (pattern-scoped, one
    // dir, no recursion), refuses loudly (ReportWriteException, the shared
    // fail-loud venue) when the disk says no, and an unusable target path
    // never reaches a success line. Also lands the output-S55 §7b pin
    // (routed to this file's holder by the S55-r1 LOW-1): the build() map
    // equals the landed site byte for byte.
    // ------------------------------------------------------------------

    public function testShrunkReRunPresentsExactlyOneGenerationOfPages(): void
    {
        [$report8, $root8] = $this->diffCapableReport(8);
        [$report2, $root2] = $this->diffCapableReport(2);
        // The page census the reporter itself could have written this run:
        // mintable names ([cluster-001..N], the cluster-%03d image) that are
        // regular files. Witness entries planted below are deliberately
        // OUTSIDE this set (non-mintable names, a directory wearing an
        // owned name) — the sweep must leave every one of them alone.
        $mintablePages = static function (string $dir): array {
            $names = [];
            foreach (glob($dir . '/cluster-*.html') ?: [] as $path) {
                if (is_file($path) && preg_match('/^cluster-\d{3,}\.html$/', basename($path)) === 1) {
                    $names[] = basename($path);
                }
            }
            sort($names);
            return $names;
        };
        try {
            $dir = $root8 . '/reused';
            (new HtmlReporter(null, 2))->writeTo($report8, $dir);
            $this->assertCount(8, $mintablePages($dir), 'premise: the first generation is eight pages');

            // Foreign witnesses for the ownership boundary, seeded BEFORE
            // the shrinking run: plain files the reporter cannot mint, a
            // DIRECTORY wearing an owned name (the sweep skips it; had a
            // page tried to land under it the trait would fail loudly),
            // and a symlink parked at a stale-page name — the sweep must
            // remove the LINK, never write through it.
            file_put_contents($dir . '/notes.txt', 'user file: never the sweep\'s business');
            file_put_contents($dir . '/cluster-10.html', 'two-digit name is not a cluster-%03d image');
            file_put_contents($dir . '/cluster-abc.html', 'letters are outside the digit run');
            mkdir($dir . '/cluster-009.html');
            $outside = $root2 . '/outside-target.txt';
            file_put_contents($outside, 'untouched');
            unlink($dir . '/cluster-007.html');
            symlink($outside, $dir . '/cluster-007.html');

            (new HtmlReporter(null, 2))->writeTo($report2, $dir);

            // The acceptance shape: 8→2 in a reused dir leaves exactly the
            // current generation — no cluster-003..008 survivors.
            $this->assertSame(['cluster-001.html', 'cluster-002.html'], $mintablePages($dir));
            // Ownership honored: everything the sweep does not own survives,
            // byte-intact, and the symlink is gone as a LINK only.
            $this->assertFileExists($dir . '/notes.txt');
            $this->assertSame(
                'two-digit name is not a cluster-%03d image',
                file_get_contents($dir . '/cluster-10.html'),
            );
            $this->assertSame(
                'letters are outside the digit run',
                file_get_contents($dir . '/cluster-abc.html'),
            );
            $this->assertDirectoryExists($dir . '/cluster-009.html');
            $this->assertFalse(is_link($dir . '/cluster-007.html'), 'stale owned-name symlink removed');
            $this->assertFileExists($outside);
            $this->assertSame('untouched', file_get_contents($outside), 'the sweep never writes through a link');
            // The surviving pages are REGENERATED (not last-run leftovers):
            // byte-identical to the same report written into a fresh dir.
            $fresh = $root2 . '/fresh';
            (new HtmlReporter(null, 2))->writeTo($report2, $fresh);
            $this->assertFileEquals($fresh . '/cluster-001.html', $dir . '/cluster-001.html');
            $this->assertFileEquals($fresh . '/index.html', $dir . '/index.html');

            // Empty re-run: the generation clears to zero pages — a removed
            // cluster's page must not outlive the cluster.
            $empty = new Report(
                files: 0, blocks: 0, parseErrors: 0, clusters: [], config: Config::defaults([$root8]),
            );
            (new HtmlReporter(null, 2))->writeTo($empty, $dir);
            $this->assertSame([], $mintablePages($dir), 'zero clusters present zero pages');
            $this->assertFileExists($dir . '/index.html');
            $this->assertFileExists($dir . '/notes.txt');
            $this->assertFileExists($dir . '/cluster-10.html');
        } finally {
            $this->rrmdir($root8);
            $this->rrmdir($root2);
        }
    }

    /**
     * output-S55 future pin (routes to the next HtmlReporterTest holder,
     * S97 bar — proposed by the convergence lane, verified against the live
     * class in a scratch copy): the pure build() seam maps the same site
     * writeTo() lands, byte for byte.
     */
    public function testBuildMapEqualsTheLandedSiteByteForByte(): void
    {
        [$report, $root] = $this->diffCapableReport(3);
        try {
            $map = (new HtmlReporter(null, 2))->build($report);
            ksort($map);
            $this->assertSame(
                ['app.js', 'cluster-001.html', 'cluster-002.html', 'cluster-003.html', 'index.html', 'style.css'],
                array_keys($map),
            );
            $dir = $root . '/build-seam';
            (new HtmlReporter(null, 2))->writeTo($report, $dir);
            foreach ($map as $name => $html) {
                $this->assertSame($html, (string)file_get_contents($dir . '/' . $name), "{$name} diverged");
            }
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testReportDirectoryPathHeldByAFileFailsLoudly(): void
    {
        [$report, $root] = $this->diffCapableReport(1);
        try {
            $blocked = $root . '/site';
            file_put_contents($blocked, 'I am a file where a directory belongs');
            try {
                (new HtmlReporter(null, 2))->writeTo($report, $blocked);
                $this->fail('target-path-is-a-file must raise ReportWriteException, not print a success line');
            } catch (ReportWriteException $e) {
                $this->assertStringContainsString('creating directory', $e->getMessage());
                $this->assertStringContainsString($blocked, $e->getMessage());
            }
            $this->assertStringEqualsFile($blocked, 'I am a file where a directory belongs');
            $this->assertSame([], glob($blocked . '/*') ?: []);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testUnwritableOutputDirRefusesStalePageSweepLoudly(): void
    {
        $this->skipIfRoot('chmod-based sweep refusal');
        [$report, $root] = $this->diffCapableReport(1);
        $dir = $root . '/locked-site';
        try {
            (new HtmlReporter(null, 2))->writeTo($report, $dir);
            // Owner-readable/listable but NOT writable: the sweep lists its
            // stale page and the unlink is refused — a mixed-generation
            // site is exactly the silent success this venue exists to kill.
            chmod($dir, 0o500);
            $this->expectException(ReportWriteException::class);
            $this->expectExceptionMessage('removing stale report page ' . $dir . '/cluster-001.html failed:');
            (new HtmlReporter(null, 2))->writeTo($report, $dir);
        } finally {
            chmod($dir, 0o700);
            $this->rrmdir($root);
        }
    }

    /** DAC-based refusal guard (same posture as WritesReportFileTest). */
    private function skipIfRoot(string $why): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: ' . $why);
        }
    }

    private function parseDom(string $file): \DOMDocument
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML((string)file_get_contents($file));
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->assertNotFalse($loaded, $file . ' did not parse');
        // libxml's HTML parser is HTML4-era: it rejects the report's valid
        // HTML5 sectioning tags by NAME ("Tag section invalid"). Those name
        // warnings are whitelisted; anything else — mismatched or broken
        // markup a bad escape would cause — fails the pin.
        $significant = preg_grep(
            '/Tag (section|main|article|aside|nav|header|footer|figure|figcaption) invalid/',
            array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors),
            PREG_GREP_INVERT,
        );
        $this->assertSame([], $significant, $file . ' produced libxml parse errors: ' . json_encode(array_values($significant)));
        return $doc;
    }
}
