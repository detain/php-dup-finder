<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Util\LineRange;

/**
 * output-038: the shared per-run source-text store — one read path, an
 * honest unavailable posture, and FileScanner-style symlink confinement on
 * the READ side (the durable fix behind the FOLLOW_SYMLINKS root-cause row
 * the shell group shares with output-010).
 */
final class SourceTextStoreTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-store-' . uniqid('', true);
        mkdir($this->tmp . '/root', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testRepeatedLookupsReadOnceAndAnswerTheSameLines(): void
    {
        $file = $this->tmp . '/root/a.php';
        file_put_contents($file, "<?php\nalpha\nbravo\n");
        $store = new SourceTextStore([$this->tmp . '/root']);

        $first  = $store->lines($file);
        $second = $store->lines($file);

        $this->assertSame(['<?php', 'alpha', 'bravo', ''], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $store->readCount(), 'cache hit must not re-read the tree');
        $this->assertNull($store->reason($file));
        $this->assertSame([], $store->skipNotes());
    }

    public function testModifiedFileIsRevalidatedBySizeThenByMtime(): void
    {
        $file  = $this->tmp . '/root/m.php';
        $store = new SourceTextStore([$this->tmp . '/root']);

        file_put_contents($file, "<?php\nfirst\n");
        $this->assertSame(['<?php', 'first', ''], $store->lines($file));

        // Size moves → the stamp differs → re-read.
        file_put_contents($file, "<?php\nsecond-longer\n");
        $this->assertSame(['<?php', 'second-longer', ''], $store->lines($file));
        $this->assertSame(2, $store->readCount());

        // Same size ('second-XXXXXX' is as long as 'second-longer'),
        // different bytes: only the mtime arm can catch this one.
        file_put_contents($file, "<?php\nsecond-XXXXXX\n");
        touch($file, time() + 10);
        $this->assertSame(['<?php', 'second-XXXXXX', ''], $store->lines($file));
        $this->assertSame(3, $store->readCount(), 'equal-size edits must not be served stale');
    }

    public function testMissingFileIsHonestAndNegativelyCached(): void
    {
        $file  = $this->tmp . '/root/gone.php';
        $store = new SourceTextStore([$this->tmp . '/root']);

        $this->assertNull($store->lines($file));
        $this->assertSame(SourceTextStore::REASON_MISSING, $store->reason($file));
        $this->assertNull($store->lines($file));
        $this->assertSame(0, $store->readCount(), 'a failed read is negative-cached, never retried per lookup');
        $this->assertSame([], $store->skipNotes(), 'plain absence is informative silence, not a skip note');

        // If the tree state changes underneath, the negative cache ages out.
        file_put_contents($file, "<?php\nnow-here\n");
        $this->assertSame(['<?php', 'now-here', ''], $store->lines($file));
        $this->assertNull($store->reason($file));
    }

    public function testUnreadableFileEarnsTheSkipNote(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads anything; the unreadable posture is untestable here');
        }
        $file = $this->tmp . '/root/locked.php';
        file_put_contents($file, "<?php\nsecret\n");
        chmod($file, 0o000);
        $store = new SourceTextStore([$this->tmp . '/root'], warnOnSkip: false);
        try {
            $this->assertNull($store->lines($file));
            $this->assertSame(SourceTextStore::REASON_UNREADABLE, $store->reason($file));
            $this->assertSame(
                ["skipped unreadable path {$file} (unreadable)"],
                $store->skipNotes(),
                'FileScanner skipNotes() dialect, one note per tree state',
            );
            $this->assertNull($store->lines($file));
            $this->assertCount(1, $store->skipNotes(), 'negative cache must not re-note the same refusal');
        } finally {
            chmod($file, 0o644);
        }
    }

    public function testSymlinkDivergenceFromScanRootIsRefusedAndNoted(): void
    {
        $root   = $this->tmp . '/root';
        $secret = $this->tmp . '/outside/secret.php';
        mkdir(dirname($secret), 0o775, true);
        file_put_contents($secret, "<?php\nplanted-content\n");
        $link = $root . '/link.php';
        symlink($secret, $link);

        $store = new SourceTextStore([$root], warnOnSkip: false);
        $this->assertNull($store->lines($link));
        $this->assertSame(SourceTextStore::REASON_ESCAPE, $store->reason($link));
        $this->assertSame(
            ["skipped symlinked path {$link} (target escapes the scan root)"],
            $store->skipNotes(),
        );
        $this->assertNull($store->lines($link));
        $this->assertCount(1, $store->skipNotes(), 'the refusal is negative-cached like any other');
    }

    public function testLibraryModeWithoutRootsProceedsUnconfined(): void
    {
        $secret = $this->tmp . '/outside/secret2.php';
        mkdir(dirname($secret), 0o775, true);
        file_put_contents($secret, "<?php\nplain-content\n");
        $link = $this->tmp . '/root/link2.php';
        symlink($secret, $link);

        $store = new SourceTextStore([], warnOnSkip: false);
        $this->assertSame(['<?php', 'plain-content', ''], $store->lines($link), 'roots empty = confinement undecidable, reads proceed');
        $this->assertSame([], $store->skipNotes());
    }

    public function testDanglingSymlinkRefusesWithUnresolvedReason(): void
    {
        $root = $this->tmp . '/root';
        $link = $root . '/dangling.php';
        symlink($this->tmp . '/nowhere.php', $link);

        $store = new SourceTextStore([$root], warnOnSkip: false);
        $this->assertNull($store->lines($link));
        $this->assertSame(SourceTextStore::REASON_UNRESOLVED, $store->reason($link));
        $this->assertSame(["skipped symlinked path {$link} (target does not resolve)"], $store->skipNotes());
    }

    public function testLinkIntoRootItselfReentersAndIsRefused(): void
    {
        $root = $this->tmp . '/root';
        mkdir($root . '/sub', 0o775, true);
        $link = $root . '/sub/self.php';
        symlink($root, $link);

        $store = new SourceTextStore([$root], warnOnSkip: false);
        $this->assertNull($store->lines($link));
        $this->assertSame(SourceTextStore::REASON_REENTER, $store->reason($link));
    }

    public function testSymlinkToInRootTargetStillReads(): void
    {
        $root = $this->tmp . '/root';
        file_put_contents($root . '/real.php', "<?php\nfine\n");
        $link = $root . '/alias.php';
        symlink($root . '/real.php', $link);

        $store = new SourceTextStore([$root], warnOnSkip: false);
        // The link target resolves inside the same root → the read proceeds
        // (shell-006 equivalence: links to in-root targets keep working).
        $this->assertSame(['<?php', 'fine', ''], $store->lines($link));
        $this->assertSame([], $store->skipNotes());
    }

    public function testOneReadPerFileAcrossAllFourSurfacesOverThirtyClusters(): void
    {
        $root = $this->tmp . '/root';
        $paths = [];
        for ($i = 0; $i < 6; $i++) {
            $p = $root . "/f{$i}.php";
            file_put_contents($p, "<?php\nfunction dup() { return {$i}; }\n");
            $paths[] = $p;
        }

        // 30 clusters, each spanning all six files — the re-read storm
        // output-016 measured as "file in 30 clusters re-read 30x".
        $clusters = [];
        for ($c = 0; $c < 30; $c++) {
            $members = [];
            foreach ($paths as $i => $p) {
                $members[] = $this->block($p, 2, 2, "dup-{$i}");
            }
            $cluster     = new Cluster("C-{$c}", $members, 0.95, false);
            $cluster->signature = 'function dup(): int';
            $clusters[]  = $cluster;
        }
        $report = new Report(
            files: 6, blocks: 180, parseErrors: 0,
            clusters: $clusters, config: Config::defaults([$root]),
        );

        $store = new SourceTextStore([$root]);
        $out   = $this->tmp . '/out';
        (new DiffReporter($store))->writeDir($report, $out . '/diff');
        (new DiffReporter($store))->writePatch($report, $out . '/all.patch');
        (new RefactorPatchReporter($store))->writeTo($report, $out . '/patches');
        (new HtmlReporter($store))->writeTo($report, $out . '/html');
        (new JsonReporter($store))->writeTo($report, $out . '/report.json');

        $this->assertSame(6, $store->readCount(), 'six distinct files = six reads, not 180 member slots × surfaces');

        $json = json_decode((string)file_get_contents($out . '/report.json'), true);
        $this->assertIsArray($json);
        $this->assertCount(30, $json['clusters']);
        foreach ($json['clusters'] as $payload) {
            $this->assertStringNotContainsString('<source unavailable', (string)($payload['source']['text'] ?? ''));
        }
    }

    public function testPlaceholderDialectNeverReappearsInAnySurfaceOutput(): void
    {
        $root = $this->tmp . '/root';
        $good = $root . '/good.php';
        file_put_contents($good, "<?php\nfunction m() { return 1; }\n");
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0,
            clusters: [new Cluster('PX', [$this->block($good, 2, 2), $this->block($root . '/absent.php', 2, 2)], 1.0, false)],
            config: Config::defaults([$root]),
        );

        $out   = $this->tmp . '/px';
        $store = new SourceTextStore([$root], warnOnSkip: false);
        (new DiffReporter($store))->writePatch($report, $out . '.patch');
        (new HtmlReporter($store))->writeTo($report, $out . '-html');

        $diff = (string)file_get_contents($out . '.patch');
        $html = implode('', array_map(
            static fn(string $f): string => (string)file_get_contents($f),
            glob($out . '-html/*.html') ?: [],
        ));

        foreach (['diff' => $diff, 'html' => $html] as $surface => $body) {
            $this->assertStringNotContainsString('<source unavailable', $body, "{$surface} carries no placeholder text");
            $this->assertStringNotContainsString('<unreadable', $body, "{$surface} carries no placeholder text");
        }
        $this->assertStringContainsString('#   source not read (file not present)', $diff);
        $this->assertStringContainsString('[source not read: file not present]', $html);
    }

    private function block(string $file, int $start, int $end, ?string $name = null): Block
    {
        $stmts = (new AstParser())->parseCode(is_file($file) ? (string)file_get_contents($file) : '<?php $x;');
        return new Block(
            file: $file,
            range: new LineRange($start, $end),
            kind: 'function',
            namespace: null, class: null,
            name: $name ?? 'm',
            ast: $stmts[0],
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_link($p) ? @unlink($p) : (is_dir($p) ? $this->rrmdir($p) : @unlink($p));
        }
        @rmdir($dir);
    }
}
