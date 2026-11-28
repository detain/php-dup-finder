<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Item 4.4 APPLY half: the appendix render surfaces. Accepted findings
 * are absent from the main payload — the CLI trailer and the HTML
 * section are what makes the suppression visible. Both renderers draw
 * nothing when no decisions were in play, so the default report keeps
 * its exact bytes.
 */
final class BaselineAppendixTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $appendix @param list<\Phpdup\Clustering\Cluster> $clusters */
    private function report(array $clusters = [], array $appendix = []): Report
    {
        $cfg = Config::defaults([__DIR__]);
        return new Report(
            4, 10, 0, $clusters, $cfg,
            reusedFiles: 0, processedFiles: 4, skippedFiles: 0,
            baselineAppendix: $appendix,
        );
    }

    /** @return array<string, mixed> */
    private function row(string $id, string $source, array $overrides = []): array
    {
        return $overrides + [
            'finding_id' => $id,
            'status' => 'accepted',
            'source' => $source,
            'resurfaced' => false,
            'accepted_at' => '2026-11-21T08:00:00+00:00',
            'git_sha' => null,
            'members' => 2,
            'impact' => 40,
            'files' => ['src/A.php', 'src/B.php'],
            'mtimes' => [],
            'member_hashes' => ['sha256:1', 'sha256:2'],
            'note' => null,
        ];
    }

    private function buffered(): BufferedOutput
    {
        return new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
    }

    // ------------------------------------------------------------------
    // CLI
    // ------------------------------------------------------------------

    public function testCliRendersNothingWithoutDecisions(): void
    {
        $out = $this->buffered();
        (new CliReporter())->render($this->report(), $out);
        $this->assertStringNotContainsString('baseline', $out->fetch());
    }

    public function testCliAppendixNamesSuppressionsInTrailer(): void
    {
        $appendix = [
            $this->row('Freg1111', 'registry', ['note' => 'kept mirror']),
            $this->row('Fdir1111', 'directive', [
                'note' => 'intentional',
                'directive_file' => 'src/A.php',
                'directive_line' => 7,
            ]),
            $this->row('Funr1111', 'registry', [
                'status' => 'unresolved',
                'members' => 0,
                'impact' => 0,
                'files' => [],
            ]),
        ];

        $out = $this->buffered();
        (new CliReporter())->render($this->report([], $appendix), $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('baseline  2 accepted · 0 resurfaced · 1 unresolved', $rendered);
        $this->assertStringContainsString('Freg1111  accepted (registry)', $rendered);
        $this->assertStringContainsString('"kept mirror"', $rendered);
        $this->assertStringContainsString('Fdir1111  accepted (directive, src/A.php:7)', $rendered);
        $this->assertStringContainsString('Funr1111  unresolved (registry)', $rendered);
        $this->assertStringContainsString('since 2026-11-21T08:00:00+00:00', $rendered);
    }

    public function testCliResurfacedRowIsExplicit(): void
    {
        $appendix = [$this->row('Fnew2222', 'registry', [
            'resurfaced' => true,
            'accepted_id' => 'Fold2222',
        ])];

        $out = $this->buffered();
        (new CliReporter())->render($this->report([], $appendix), $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('baseline  1 accepted · 1 resurfaced · 0 unresolved', $rendered);
        $this->assertStringContainsString('RESURFACED (was accepted as Fold2222', $rendered);
        $this->assertStringContainsString('re-accept or remove', $rendered);
    }

    public function testCliAppendixSurvivesEmptyMainReport(): void
    {
        // All findings accepted: "clean." would be a lie by omission —
        // the appendix must still print under the no-clusters branch.
        $appendix = [$this->row('Fonly3333', 'config', ['note' => 'accepted list in phpdup.json'])];

        $out = $this->buffered();
        (new CliReporter())->render($this->report([], $appendix), $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('clean.', $rendered, 'empty main payload keeps its verdict line');
        $this->assertStringContainsString('Fonly3333  accepted (config)', $rendered);
    }

    public function testCliTrailerCountsCoverMainOnly(): void
    {
        // The summary numbers are the MAIN report's; the appendix is the
        // record of what was suppressed, never silently folded in.
        $cluster = new \Phpdup\Clustering\Cluster(
            'Fmain4444',
            [new \Phpdup\Extraction\Block(
                file: 'src/M.php',
                range: new \Phpdup\Util\LineRange(1, 5),
                kind: 'method',
                namespace: null,
                class: null,
                name: 'm',
                ast: new \PhpParser\Node\Stmt\Nop(),
            )],
            1.0,
            false,
        );
        $cluster->impact = 5;

        $appendix = [$this->row('Facc4444', 'registry')];
        $out = $this->buffered();
        (new CliReporter(CliReporter::VERBOSITY_SUMMARY_ONLY))->render($this->report([$cluster], $appendix), $out);
        $rendered = $out->fetch();

        $this->assertStringContainsString('summary  1 clusters', $rendered);
        $this->assertStringContainsString('Facc4444', $rendered);
    }

    // ------------------------------------------------------------------
    // HTML
    // ------------------------------------------------------------------

    public function testHtmlOmitsSectionWithoutDecisions(): void
    {
        [$report, $root] = $this->htmlPair();
        try {
            (new HtmlReporter())->writeTo($report, $root);
            $html = (string)file_get_contents("$root/index.html");
            $this->assertStringNotContainsString('baseline-appendix', $html);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testHtmlSectionEnumeratesAcceptances(): void
    {
        [$report, $root] = $this->htmlPair([
            $this->row('Freg5555', 'registry', ['note' => 'kept mirror']),
            $this->row('Frsf5555', 'registry', ['resurfaced' => true, 'accepted_id' => 'Fold5555']),
        ]);
        try {
            (new HtmlReporter())->writeTo($report, $root);
            $html = (string)file_get_contents("$root/index.html");

            $this->assertStringContainsString('<section class="baseline-appendix" id="baseline">', $html);
            $this->assertStringContainsString('Baseline appendix', $html);
            $this->assertStringContainsString('<code>Freg5555</code>', $html);
            $this->assertStringContainsString('kept mirror', $html);
            $this->assertStringContainsString('<strong class="resurfaced">resurfaced</strong>', $html);
            $this->assertStringContainsString('accepted as <code>Fold5555</code>', $html);
            $this->assertStringContainsString('src/A.php', $html);
        } finally {
            $this->rrmdir($root);
        }
    }

    public function testHtmlEscapesHostileProvenance(): void
    {
        // Registry provenance is parsed JSON; the directive file path is
        // attacker-adjacent text — both must land escaped.
        [$report, $root] = $this->htmlPair([
            $this->row('Fevil6', 'directive', [
                'note' => '<script>alert(1)</script>',
                'directive_file' => 'src/<img onerror=1>.php',
                'directive_line' => 3,
            ]),
        ]);
        try {
            (new HtmlReporter())->writeTo($report, $root);
            $html = (string)file_get_contents("$root/index.html");

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
            $this->assertStringNotContainsString('<img onerror=1>', $html);
            $this->assertStringContainsString('src/&lt;img onerror=1&gt;.php', $html);
        } finally {
            $this->rrmdir($root);
        }
    }

    /** @param array<int, array<string, mixed>> $appendix @return array{Report, string} */
    private function htmlPair(array $appendix = []): array
    {
        $root = sys_get_temp_dir() . '/phpdup-baseline-html-' . uniqid();
        mkdir($root, 0o775, true);

        return [
            $this->report([], $appendix),
            $root,
        ];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
