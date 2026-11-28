<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 5.1 (this lane) — end-to-end ladder census through the real CLI
 * over REAL marker trees (nearest composer.json + .git resolved inside
 * the scan-root boundary), plus the above-boundary decoy law and the
 * tripwire flip from the verification fleet: '--scope cross-repo' is now
 * a closed-set word (pre-fix it was an open-set typo that matched
 * nothing and earned the hint).
 *
 * Corpus (all roots real under one temp dir):
 *   P/.git + P/composer.json(vendor/ABOVE)   ← decoy: parent of every root
 *   P/rootX/src/   composer name vendor/x-main (manifest at rootX)
 *      Twin.php        family T — two identical methods in ONE file   → intra-file
 *      I1.php + I2.php family I — identical pair, one package         → intra-package
 *      M1.php          family M — pair split across packages          → cross-package
 *   P/rootX/pkg/sub/composer.json vendor/x-sub
 *      M2.php
 *   P/rootY/.git  (no manifest: package degrades to the repo word)
 *      N2.php + rootX/src/N1.php family N — identical pair            → cross-repo
 *
 * Family bodies are structurally distinct (foreach/if, for/concat,
 * clamp-accumulator, switch) so the clusterer cannot merge them.
 */
final class LadderScopeE2ETest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $rootX;
    private string $rootY;

    protected function setUp(): void
    {
        $this->tmp   = sys_get_temp_dir() . '/phpdup_ladder51_' . uniqid('t', true);
        $this->rootX = $this->tmp . '/rootX';
        $this->rootY = $this->tmp . '/rootY';

        // Decoy carrier ABOVE every scan root (VendorMarker M-1 law).
        @mkdir($this->tmp . '/.git', 0o775, true);
        file_put_contents($this->tmp . '/composer.json', (string) json_encode(['name' => 'belt/ABOVE']));

        @mkdir($this->rootX . '/src', 0o775, true);
        @mkdir($this->rootX . '/pkg/sub', 0o775, true);
        @mkdir($this->rootY, 0o775, true);
        @mkdir($this->tmp . '/cache', 0o775, true);
        file_put_contents($this->rootX . '/composer.json', (string) json_encode(['name' => 'vendor/x-main']));
        file_put_contents($this->rootX . '/pkg/sub/composer.json', (string) json_encode(['name' => 'vendor/x-sub']));
        @mkdir($this->rootY . '/.git', 0o775, true);

        // Family T — intra-file (two identical methods, one file).
        file_put_contents($this->rootX . '/src/Twin.php', "<?php\n"
            . str_replace('__FN__', 'twinFirst', $this->twinBody()) . "\n"
            . str_replace('__FN__', 'twinSecond', $this->twinBody()) . "\n");
        // Family I — intra-package.
        file_put_contents($this->rootX . '/src/I1.php', "<?php\n" . str_replace('__FN__', 'iFirst', $this->iterBody()));
        file_put_contents($this->rootX . '/src/I2.php', "<?php\n" . str_replace('__FN__', 'iSecond', $this->iterBody()));
        // Family M — cross-package (same repo, two composer names).
        file_put_contents($this->rootX . '/src/M1.php', "<?php\n" . str_replace('__FN__', 'mFirst', $this->clampBody()));
        file_put_contents($this->rootX . '/pkg/sub/M2.php', "<?php\n" . str_replace('__FN__', 'mSecond', $this->clampBody()));
        // Family N — cross-repo.
        file_put_contents($this->rootX . '/src/N1.php', "<?php\n" . str_replace('__FN__', 'nFirst', $this->switchBody()));
        file_put_contents($this->rootY . '/N2.php', "<?php\n" . str_replace('__FN__', 'nSecond', $this->switchBody()));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testUnarmedRunGrowsNoScopeLabelKeys(): void
    {
        $d = $this->invoke(['--json'], 'unarmed.json');
        self::assertSame(0, $d['exit'], $d['combined']);
        $p = $d['payload'];
        self::assertArrayNotHasKey('scope_counts', $p['summary'], 'OFF byte-identity: the key is absent, not zero-shaped');
        foreach ($p['clusters'] as $c) {
            self::assertArrayNotHasKey('scope_ladder', $c);
            foreach ($c['members'] as $m) {
                self::assertArrayNotHasKey('package', $m);
            }
        }
    }

    public function testArmedRunCountsEveryRungAndLabelsMembers(): void
    {
        $d = $this->invoke(['--json', '--scope-labels'], 'armed.json');
        self::assertSame(0, $d['exit'], $d['combined']);
        $p = $d['payload'];

        $counts = $p['summary']['scope_counts'] ?? null;
        self::assertIsArray($counts, 'armed run opens the census key');
        self::assertSame(
            $p['summary']['clusters'],
            array_sum($counts),
            'sum identity: every reported cluster lands in exactly one rung',
        );
        self::assertSame(
            ['intra-file', 'intra-package', 'cross-package', 'cross-repo'],
            array_keys($counts),
            'four buckets, ladder order, always present',
        );

        $byLadder = [];
        foreach ($p['clusters'] as $c) {
            $files = array_map(static fn($m) => basename($m['file']), $c['members']);
            sort($files);
            $byLadder[$c['scope_ladder']][] = implode('+', $files);
        }
        // The same family can be minted at more than one block granularity
        // (named function pair + unnamed pair): every intra-file cluster is
        // built exclusively from Twin.php, and the twin pair shows up.
        $intraFiles = $byLadder['intra-file'] ?? [];
        self::assertNotSame([], $intraFiles, 'the same-file twin pair yields an intra-file cluster');
        foreach ($intraFiles as $key) {
            self::assertSame('Twin.php+Twin.php', $key, 'intra-file rung admits only same-file members');
        }
        self::assertContains('I1.php+I2.php', $byLadder['intra-package'] ?? [], 'one package, two files');
        self::assertContains('M1.php+M2.php', $byLadder['cross-package'] ?? [], 'two composer names under one repo label');
        self::assertContains('N1.php+N2.php', $byLadder['cross-repo'] ?? [], 'two .git carriers — the coarsest rung wins');

        // Member labels: nearest in-root composer wins; boundary decoy NEVER read.
        $labels = [];
        foreach ($p['clusters'] as $c) {
            foreach ($c['members'] as $m) {
                $labels[basename($m['file'])] = [$m['repo'], $m['package']];
            }
        }
        self::assertSame(['rootX', 'vendor/x-main'], $labels['I1.php']);
        self::assertSame(['rootX', 'vendor/x-sub'], $labels['M2.php'], 'inner manifest beats the outer — nearest wins');
        self::assertSame(['rootX', 'vendor/x-main'], $labels['M1.php']);
        self::assertSame(['rootY', 'rootY'], $labels['N2.php'], 'no in-boundary manifest degrades package to the repo word (rootY has its own .git)');
        self::assertStringNotContainsString('ABOVE', (string) json_encode($labels), 'the above-boundary decoy never leaks a label');
    }

    public function testLadderWordsFilterAndCrossRepoWordIsClosedSet(): void
    {
        // TRIPWIRE FLIP (verification fleet p5 red-pin iii): pre-fix,
        // '--scope=cross-repo' was an open-set typo-label — zero matches
        // AND the hint. It is now a closed-set word equal to 'multi'.
        $cross = $this->invoke(['--json', '--scope=cross-repo'], 'x.json');
        self::assertSame(0, $cross['exit'], $cross['combined']);
        self::assertGreaterThanOrEqual(1, count($cross['payload']['clusters']), 'the cross-repo word keeps the N pair');
        self::assertStringNotContainsString('matched no clusters', $cross['combined'], 'closed-set words never earn the typo hint');
        foreach ($cross['payload']['clusters'] as $c) {
            self::assertSame('multi', $c['scope'], 'cross-repo is the spelled form of multi');
        }

        // Armed so member rows carry their package labels for the audit
        // below (the filter itself is config-independent).
        $pkg = $this->invoke(['--json', '--scope-labels', '--scope=cross-package'], 'p.json');
        self::assertSame(0, $pkg['exit'], $pkg['combined']);
        self::assertStringNotContainsString('matched no clusters', $pkg['combined']);
        $seen = [];
        foreach ($pkg['payload']['clusters'] as $c) {
            self::assertGreaterThan(1, count(array_unique(array_map(static fn($m) => $m['package'], $c['members']))),
                'every cluster the cross-package filter keeps spans two package labels');
            foreach ($c['members'] as $m) {
                $seen[basename($m['file'])] = true;
            }
        }
        self::assertArrayHasKey('M1.php', $seen, 'the cross-package filter keeps the split-package pair');
        self::assertArrayNotHasKey('I1.php', $seen, 'single-package pairs are NOT cross-package');

        $intra = $this->invoke(['--json', '--scope=intra-package'], 'i.json');
        self::assertSame(0, $intra['exit'], $intra['combined']);
        $files = [];
        foreach ($intra['payload']['clusters'] as $c) {
            foreach ($c['members'] as $m) {
                $files[basename($m['file'])] = true;
                self::assertSame('rootX', $m['repo']);
            }
        }
        self::assertArrayHasKey('I1.php', $files);
        self::assertArrayNotHasKey('M1.php', $files, 'intra-package excludes cross-package rungs (rung equality, not subset)');

        $file = $this->invoke(['--json', '--scope=intra-file'], 'f.json');
        self::assertSame(0, $file['exit'], $file['combined']);
        $twin = 0;
        foreach ($file['payload']['clusters'] as $c) {
            foreach ($c['members'] as $m) {
                self::assertSame('Twin.php', basename($m['file']));
                $twin++;
            }
        }
        self::assertGreaterThanOrEqual(2, $twin, 'the same-file pair is the intra-file cohort');
    }

    public function testSingleRootRunStillLabelsScopeAsSingle(): void
    {
        // Legacy scope-word law untouched: one root, one repo — members
        // read scope 'single' whatever the .git/composer labels say.
        $d = $this->invoke(['--json', '--scope-labels'], 'one.json', roots: [$this->rootX]);
        self::assertSame(0, $d['exit'], $d['combined']);
        foreach ($d['payload']['clusters'] as $c) {
            foreach ($c['members'] as $m) {
                self::assertSame('single', $m['scope']);
                self::assertSame('rootX', $m['repo'], 'repo label is the boundary-resolved word, not the scope');
            }
        }
        // Within one root: packages still ladder cross-package clusters.
        self::assertGreaterThanOrEqual(1, $d['payload']['summary']['scope_counts']['cross-package'] ?? 0);
    }

    // ------------------------------------------------------------------ harness

    /** @param list<string> $flags @return array{exit:int,payload:array<string,mixed>,combined:string} */
    private function invoke(array $flags, string $outName, ?array $roots = null): array
    {
        $cfgPath = $this->tmp . '/cfg.json';
        file_put_contents($cfgPath, (string) json_encode([
            'cache_dir'          => $this->tmp . '/cache',
            'min_cluster_impact' => 0,
        ]));

        $jsonFlags = in_array('--json', $flags, true);
        $argv = array_merge(
            ['analyze'],
            $roots ?? [$this->rootX, $this->rootY],
            $jsonFlags ? ['--json', $this->tmp . '/' . $outName] : [],
            array_values(array_filter($flags, static fn($f) => $f !== '--json')),
            ['-c', $cfgPath],
        );

        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/phpdup'], $argv);
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::fail('phpdup child exceeded ' . self::TIMEOUT_S . 's: ' . implode(' ', $cmd));
            }
            usleep(50_000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $payload = [];
        if (in_array('--json', $flags, true)) {
            $raw = @file_get_contents($this->tmp . '/' . $outName);
            self::assertIsString($raw, "no JSON produced (stdout: $out stderr: $err exit: $exit)");
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded);
            $payload = $decoded;
        }
        return ['exit' => $exit, 'payload' => $payload, 'combined' => $out . $err];
    }

    // Bodies — one structurally distinct family each.

    private function twinBody(): string
    {
        return <<<'PHP'
            function __FN__(string $seed, int $times): string {
                $parts = [];
                for ($k = 0; $k < $times; $k++) {
                    $parts[] = strtoupper($seed) . ':' . $k;
                    if ($k % 2 === 0) { $parts[] = '-' . strlen($seed); }
                }
                return implode('|', $parts);
            }
            PHP;
    }

    private function iterBody(): string
    {
        return <<<'PHP'
            function __FN__(array $rows, callable $pick): float {
                $total = 0.0;
                foreach ($rows as $row) {
                    $sel = $pick($row);
                    if ($sel === null) { continue; }
                    $total += (float) $sel * 1.08;
                }
                return round($total, 2);
            }
            PHP;
    }

    private function clampBody(): string
    {
        return <<<'PHP'
            function __FN__(int $events, float $rate): array {
                $acc = [];
                for ($j = 0; $j < $events; $j++) {
                    $bucket = (int) ($j * $rate);
                    $acc[$bucket] = ($acc[$bucket] ?? 0) + 1;
                    if ($bucket > 100) { $acc['overflow'] = true; }
                }
                ksort($acc);
                return $acc;
            }
            PHP;
    }

    private function switchBody(): string
    {
        return <<<'PHP'
            function __FN__(string $k): string {
                switch ($k) {
                    case 'a': return 'one';
                    case 'b': return 'two';
                    case 'c': return 'three';
                    case 'd': return 'four';
                    default:  return 'zero';
                }
            }
            PHP;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
