<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Bench;

use PHPUnit\Framework\TestCase;
use Phpdup\Testing\BenchmarkResultsRenderer;

final class BenchmarkResultsRendererTest extends TestCase
{
    public function testRenderRunMarkdownEmitsHeaderAndTableHeader(): void
    {
        $rows = [[
            'corpus'   => 'sample',
            'files'    => 5,
            'tool'     => 'phpdup',
            'wall_s'   => 1.23,
            'peak_mb'  => 64.0,
            'clusters' => 7,
            'extra'    => ['impact' => 219, 'top_tags' => 'sql-builder/crud-handler'],
        ]];
        $md = (new BenchmarkResultsRenderer())->renderRunMarkdown($rows);
        self::assertStringContainsString('# Comparative benchmark', $md);
        self::assertStringContainsString('## Corpus: `sample`', $md);
        self::assertStringContainsString('| Tool | Wall (s) | Peak RSS (MB) | Clusters | Notes |', $md);
        self::assertStringContainsString('| phpdup | 1.23 | 64.0 | 7 |', $md);
        self::assertStringContainsString('impact=219', $md);
        self::assertStringContainsString('5 PHP files', $md);
    }

    public function testNullValuesRenderAsEmDash(): void
    {
        $rows = [[
            'corpus'   => 'corpus-x',
            'files'    => null,
            'tool'     => 'phpcpd',
            'wall_s'   => null,
            'peak_mb'  => null,
            'clusters' => null,
            'extra'    => [],
        ]];
        $md = (new BenchmarkResultsRenderer())->renderRunMarkdown($rows);
        self::assertStringContainsString('| phpcpd | — | — | — | — |', $md);
        // Header without files-count parenthetical.
        self::assertStringNotContainsString('(0 PHP files)', $md);
    }

    public function testArrayValuedExtrasAreSkippedFromNotes(): void
    {
        $rows = [[
            'corpus'   => 'c',
            'files'    => 1,
            'tool'     => 't',
            'wall_s'   => 0.1,
            'peak_mb'  => 1.0,
            'clusters' => 0,
            'extra'    => ['members' => [[['file' => 'a.php', 'start' => 1, 'end' => 2]]], 'note' => 'ok'],
        ]];
        $md = (new BenchmarkResultsRenderer())->renderRunMarkdown($rows);
        self::assertStringContainsString('note=ok', $md);
        self::assertStringNotContainsString('members=', $md);
    }

    public function testEmptyRowsRendersFallbackBody(): void
    {
        $md = (new BenchmarkResultsRenderer())->renderRunMarkdown([]);
        self::assertStringContainsString('# Comparative benchmark', $md);
        self::assertStringContainsString('_(no results)_', $md);
    }

    public function testDetectionRateMarkdownTableShape(): void
    {
        $scores = [[
            'tool'         => 'phpdup',
            'precision'    => 1.0,
            'recall'       => 0.75,
            'f1'           => 0.857,
            'tp'           => 3,
            'fp'           => 0,
            'fn'           => 1,
            'reported'     => 3,
            'ground_truth' => 4,
        ]];
        $md = (new BenchmarkResultsRenderer())->renderDetectionRateMarkdown($scores);
        self::assertStringContainsString('# Detection-rate scoring', $md);
        self::assertStringContainsString('| Tool | Precision | Recall | F1 | TP | FP | FN |', $md);
        self::assertStringContainsString('| phpdup | 1.00 | 0.75 |', $md);
    }

    public function testDetectionRateEmptyShowsExplanatoryFallback(): void
    {
        $md = (new BenchmarkResultsRenderer())->renderDetectionRateMarkdown([]);
        self::assertStringContainsString('# Detection-rate scoring', $md);
        self::assertStringContainsString('(no scores;', $md);
    }

    public function testRunMarkdownGroupsRowsByCorpus(): void
    {
        $rows = [
            ['corpus' => 'A', 'files' => 1, 'tool' => 'phpdup', 'wall_s' => 0.1, 'peak_mb' => 1.0, 'clusters' => 1, 'extra' => []],
            ['corpus' => 'B', 'files' => 2, 'tool' => 'phpdup', 'wall_s' => 0.2, 'peak_mb' => 2.0, 'clusters' => 2, 'extra' => []],
            ['corpus' => 'A', 'files' => 1, 'tool' => 'phpcpd', 'wall_s' => 0.3, 'peak_mb' => 3.0, 'clusters' => 0, 'extra' => []],
        ];
        $md = (new BenchmarkResultsRenderer())->renderRunMarkdown($rows);
        // Two corpus headers, in input order.
        $aPos = strpos($md, '## Corpus: `A`');
        $bPos = strpos($md, '## Corpus: `B`');
        self::assertNotFalse($aPos);
        self::assertNotFalse($bPos);
        self::assertLessThan($bPos, $aPos);
    }
}
