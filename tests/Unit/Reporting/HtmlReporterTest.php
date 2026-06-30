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
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
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
                    $this->assertStringContainsString('class="absent"', $body, '<absent> sentinel rendered with .absent class');
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
        $refl->setAccessible(true);

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
        $refl->setAccessible(true);

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
}
