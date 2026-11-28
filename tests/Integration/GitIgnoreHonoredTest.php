<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 5.2's .gitignore leg (W2a) — end-to-end acceptance through the REAL
 * `bin/phpdup` surface: the --honor-gitignore flag and the root config key
 * must prune the SAME paths (dir-only rule, basename glob with its negation
 * revival, nested per-directory rule) and account them in the scan_notes
 * channel, while the OFF run stays byte-compatible with the historical
 * posture. The report's top-level key SET must not move (the accounting
 * rides the existing scan_notes list; no new JSON keys without a schema
 * bump, and this lane is golden-frozen).
 *
 * Harness laws copied from CliSmokeTest: stdin closed by the runner, every
 * child gets a config file carrying an absolute throwaway cache_dir (a
 * FRESH one per phase so no cross-run snapshot can blur the counts),
 * explicit corpus directory under the temp root, wall-clock deadline with
 * SIGKILL on overrun.
 */
final class GitIgnoreHonoredTest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $proj;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup_gi_' . uniqid('t', true);
        $this->proj = $this->tmp . '/proj';
        mkdir($this->proj . '/gen', 0o775, true);
        mkdir($this->proj . '/sub', 0o775, true);

        file_put_contents($this->proj . '/.gitignore', "gen/\n*.bak.php\n!keep.bak.php\n");
        file_put_contents($this->proj . '/sub/.gitignore', "deep.php\n");

        // Eight scannable php files; three die under the ignores when ON:
        // gen/hidden.php (dir-only), x.bak.php (glob; keep.bak.php revived
        // by the negation), sub/deep.php (nested rule; top-level deep.php
        // survives — the sub rule is scoped to its subtree).
        foreach (
            ['a.php', 'b.php', 'app.php', 'keep.bak.php', 'x.bak.php', 'gen/hidden.php', 'deep.php', 'sub/deep.php']
            as $rel
        ) {
            file_put_contents($this->proj . '/' . $rel, "<?php\nfunction probe_" . md5($rel) . "(\$v) { return \$v + 1; }\n");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testFlagPrunesAndNotesAccounting(): void
    {
        $run = $this->cli(['analyze', $this->proj, '--json', $this->tmp . '/on-flag.json', '--honor-gitignore'], cacheTag: 'flag');
        self::assertSame(0, $run['exit'], 'run must exit 0: ' . $run['combined']);

        $report = json_decode((string) file_get_contents($this->tmp . '/on-flag.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(5, $report['summary']['files_scanned'],
            'only the ignore-surviving five files may reach the pipeline');
        self::assertContains('gitignore: 3 paths excluded by .gitignore rules under scan root ' . $this->proj, $report['summary']['scan_notes']);
    }

    public function testConfigKeyMirrorsTheFlag(): void
    {
        $viaKey = $this->cli(
            ['analyze', $this->proj, '--json', $this->tmp . '/on-cfg.json'],
            cfgExtra: ['honor_gitignore' => true],
            cacheTag: 'cfg',
        );
        self::assertSame(0, $viaKey['exit'], 'run must exit 0: ' . $viaKey['combined']);

        $viaFlag = $this->cli(['analyze', $this->proj, '--json', $this->tmp . '/on-flag2.json', '--honor-gitignore'], cacheTag: 'flag2');
        self::assertSame(0, $viaFlag['exit']);

        $a = json_decode((string) file_get_contents($this->tmp . '/on-cfg.json'), true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode((string) file_get_contents($this->tmp . '/on-flag2.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(5, $a['summary']['files_scanned']);
        self::assertSame($a['summary']['scan_notes'], $b['summary']['scan_notes'], 'the config key and the flag must agree exactly');
    }

    public function testOffPostureIsInert(): void
    {
        $off = $this->cli(['analyze', $this->proj, '--json', $this->tmp . '/off.json'], cacheTag: 'off');
        self::assertSame(0, $off['exit'], 'run must exit 0: ' . $off['combined']);

        $report = json_decode((string) file_get_contents($this->tmp . '/off.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(8, $report['summary']['files_scanned'], 'the default posture scans everything');
        foreach ($report['summary']['scan_notes'] as $note) {
            self::assertStringNotContainsString('gitignore:', $note, 'no gitignore accounting may appear when OFF');
        }
    }

    public function testTopLevelKeySetDoesNotMove(): void
    {
        $offRun = $this->cli(['analyze', $this->proj, '--json', $this->tmp . '/k-off.json'], cacheTag: 'koff');
        self::assertSame(0, $offRun['exit']);
        $off = json_decode((string) file_get_contents($this->tmp . '/k-off.json'), true, 512, JSON_THROW_ON_ERROR);

        $onRun = $this->cli(['analyze', $this->proj, '--json', $this->tmp . '/k-on.json', '--honor-gitignore'], cacheTag: 'kon');
        self::assertSame(0, $onRun['exit']);
        $on = json_decode((string) file_get_contents($this->tmp . '/k-on.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            array_keys($off),
            array_keys($on),
            'honor_gitignore must not mint new top-level JSON keys (golden-frozen schema)',
        );
    }

    // ------------------------------------------------------------------ harness

    /** @param list<string> $args @param array<string,mixed> $cfgExtra */
    private function cli(array $args, array $cfgExtra = [], string $cacheTag = 'x', float $timeout = self::TIMEOUT_S): array
    {
        $cfgPath = $this->tmp . '/cfg-' . $cacheTag . '.json';
        $cacheDir = $this->tmp . '/cache-' . $cacheTag;
        mkdir($cacheDir, 0o775, true);
        file_put_contents($cfgPath, (string) json_encode(['cache_dir' => $cacheDir] + $cfgExtra));

        $cmd = array_merge([PHP_BINARY, self::repoRoot() . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        $outOpen = true;
        $errOpen = true;
        while ($outOpen || $errOpen) {
            $read = array_filter([$outOpen ? $pipes[1] : null, $errOpen ? $pipes[2] : null]);
            $w = $e = null;
            if ($read !== [] && stream_select($read, $w, $e, 1) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($stream === $pipes[1]) {
                        $out .= $chunk === false ? '' : $chunk;
                    } else {
                        $err .= $chunk === false ? '' : $chunk;
                    }
                }
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::fail(sprintf('phpdup child timed out after %.0fs: %s', $timeout, implode(' ', $args)));
            }
            if (@feof($pipes[1])) {
                $outOpen = false;
            }
            if (@feof($pipes[2])) {
                $errOpen = false;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'combined' => $out . $err];
    }

    private static function repoRoot(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/bin/phpdup')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        throw new \RuntimeException('cannot locate repo root from ' . __DIR__);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if (is_link($entry) || is_file($entry)) {
                @unlink($entry);
            } elseif (is_dir($entry)) {
                @rmdir($entry);
            }
        }
        @rmdir($dir);
    }
}
