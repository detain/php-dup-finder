<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 5.1 end-to-end: multi-repo roots produce repo/scope labels in
 * the JSON payload, and the --scope / --score-mode surfaces (CLI flag,
 * config-file key, exit contract) behave per docs/CLI.md.
 *
 * CliSmokeTest-style in-process-free invocation of bin/phpdup against
 * a throwaway two-root corpus (deep temp subdirectory — never bare
 * /tmp; stdin closed — the </dev/null law).
 */
final class ScopeFlagTest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $alpha;
    private string $beta;

    protected function setUp(): void
    {
        $this->tmp   = sys_get_temp_dir() . '/phpdup_scope51_' . uniqid('t', true);
        $this->alpha = $this->tmp . '/repoAlpha';
        $this->beta  = $this->tmp . '/repoBeta';
        mkdir($this->alpha . '/cache', 0o775, true);
        mkdir($this->beta, 0o775, true);

        // Three structurally DISTINCT clone families so the clusterer
        // cannot merge them: an if-chain, a for-loop, a switch.
        $alphaDup = <<<'PHP'
            <?php
            function alphaOne(int $n): int
            {
                $total = 0;
                if ($n > 10) { $total += 1; }
                if ($n > 20) { $total += 2; }
                if ($n > 30) { $total += 3; }
                if ($n > 40) { $total += 4; }
                if ($n > 50) { $total += 5; }
                return $total;
            }
            PHP;
        $betaDup  = <<<'PHP'
            <?php
            function betaOne(int $n): int
            {
                $total = 0;
                for ($i = 0; $i < $n; $i++) { $total += $i; }
                for ($j = 0; $j < $n; $j++) { $total -= $j; }
                return max(0, $total);
            }
            PHP;
        $crossDup = <<<'PHP'
            <?php
            function gammaOne(string $k): string
            {
                switch ($k) {
                    case 'a': return 'one';
                    case 'b': return 'two';
                    case 'c': return 'three';
                    case 'd': return 'four';
                    default:  return 'zero';
                }
            }
            PHP;

        file_put_contents($this->alpha . '/a1.php', str_replace('One', 'OneAlphaA', $alphaDup));
        file_put_contents($this->alpha . '/a2.php', str_replace('One', 'OneAlphaB', $alphaDup));
        file_put_contents($this->beta  . '/b1.php', str_replace('One', 'OneBetaA', $betaDup));
        file_put_contents($this->beta  . '/b2.php', str_replace('One', 'OneBetaB', $betaDup));
        file_put_contents($this->alpha . '/a3.php', str_replace('One', 'OneCrossA', $crossDup));
        file_put_contents($this->beta  . '/b3.php', str_replace('One', 'OneCrossB', $crossDup));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testDefaultRunSurfacesLabelsAndClusterScope(): void
    {
        $run = $this->cli(['analyze', $this->alpha, $this->beta, '--json', $this->tmp . '/r.json']);
        $this->assertSame(0, $run['exit'], $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $this->assertIsArray($decoded);

        $byFamily = [];
        foreach ($decoded['clusters'] as $c) {
            $this->assertArrayHasKey('scope', $c);
            foreach ($c['members'] as $m) {
                $this->assertArrayHasKey('repo', $m);
                $this->assertArrayHasKey('scope', $m);
                $file = basename($m['file']);
                $byFamily[substr($file, 0, 1)][$file] = $m;
                // Two roots this run: member scope == repo label.
                $this->assertSame($m['repo'], $m['scope']);
                $this->assertContains($m['repo'], ['repoAlpha', 'repoBeta']);
            }
        }

        // Every member file must have been attributed to its root.
        $this->assertSame('repoAlpha', $byFamily['a']['a1.php']['repo']);
        $this->assertSame('repoBeta', $byFamily['b']['b1.php']['repo']);

        // The three planted families → expected scopes. (Impact sorting
        // makes cluster order non-deterministic across scorer tweaks,
        // so we key by the member-file set instead.)
        $scopes = [];
        foreach ($decoded['clusters'] as $c) {
            $files = array_map(static fn($m) => basename($m['file']), $c['members']);
            sort($files);
            $scopes[implode('+', $files)] = $c['scope'];
        }
        $this->assertGreaterThanOrEqual(3, count($decoded['clusters']));
        $this->assertSame('single', $scopes['a1.php+a2.php'] ?? null, 'alpha family is one-repo');
        $this->assertSame('single', $scopes['b1.php+b2.php'] ?? null, 'beta family is one-repo');
        $this->assertSame('multi', $scopes['a3.php+b3.php'] ?? null, 'gamma family crosses repos');
    }

    public function testScopeFilterFlags(): void
    {
        $base = ['analyze', $this->alpha, $this->beta, '--json'];

        $multi = $this->cli([...$base, $this->tmp . '/m.json', '--scope=multi']);
        $this->assertSame(0, $multi['exit'], $multi['combined']);
        $m = json_decode((string) file_get_contents($this->tmp . '/m.json'), true);
        foreach ($m['clusters'] as $c) {
            $this->assertSame('multi', $c['scope']);
        }
        $this->assertGreaterThanOrEqual(1, count($m['clusters']), 'the gamma family is cross-repo');

        $single = $this->cli([...$base, $this->tmp . '/s.json', '--scope=single']);
        $this->assertSame(0, $single['exit'], $single['combined']);
        foreach (json_decode((string) file_get_contents($this->tmp . '/s.json'), true)['clusters'] as $c) {
            $this->assertSame('single', $c['scope']);
        }

        $label = $this->cli([...$base, $this->tmp . '/l.json', '--scope=repoBeta']);
        $this->assertSame(0, $label['exit'], $label['combined']);
        foreach (json_decode((string) file_get_contents($this->tmp . '/l.json'), true)['clusters'] as $c) {
            $repos = array_map(static fn($mm) => $mm['repo'], $c['members']);
            $this->assertContains('repoBeta', $repos, 'label filter keeps every cluster touching the repo');
        }

        $ghost = $this->cli([...$base, $this->tmp . '/g.json', '--scope=no-such-repo']);
        $this->assertSame(0, $ghost['exit'], 'a zero-match filter is a clean ok, not an error');
        $this->assertSame(0, json_decode((string) file_get_contents($this->tmp . '/g.json'), true)['summary']['clusters']);

        // Review m-2: the typo'd open-set label must earn one friendly
        // stderr hint naming the labels this run produced — on STDERR
        // only, exit code untouched, and with no usage-dump footer.
        $this->assertStringContainsString('--scope=no-such-repo matched no clusters', $ghost['stderr']);
        $this->assertStringContainsString('repoAlpha', $ghost['stderr']);
        $this->assertStringContainsString('repoBeta', $ghost['stderr']);
        $this->assertStringNotContainsString('matched no clusters', $ghost['stdout'], 'hint rides stderr, never the report stream');
        $this->assertStringNotContainsString('Usage:', $ghost['combined'], 'friendly hint, not a usage dump');

        // Closed-set words may legitimately match zero clusters (a
        // single-root run has no multi cohort) — those stay silent.
        $closedWord = $this->cli(['analyze', $this->alpha, '--json', $this->tmp . '/cw.json', '--scope=multi']);
        $this->assertSame(0, $closedWord['exit'], $closedWord['combined']);
        $this->assertSame(0, json_decode((string) file_get_contents($this->tmp . '/cw.json'), true)['summary']['clusters']);
        $this->assertStringNotContainsString('matched no clusters', $closedWord['combined'], "'multi' is a known word: zero matches is honest, not a typo");
    }

    public function testScopeAndScoreModeAsConfigFileKeys(): void
    {
        $run = $this->cli(
            ['analyze', $this->alpha, $this->beta, '--json', $this->tmp . '/c.json'],
            ['scope' => 'multi'],
        );
        $this->assertSame(0, $run['exit'], $run['combined']);
        $decoded = json_decode((string) file_get_contents($this->tmp . '/c.json'), true);
        $this->assertGreaterThanOrEqual(1, count($decoded['clusters']));
        foreach ($decoded['clusters'] as $c) {
            $this->assertSame('multi', $c['scope']);
        }

        $modeRun = $this->cli(
            ['analyze', $this->alpha, $this->beta, '--json', $this->tmp . '/t.json'],
            ['score_mode' => 'typed'],
        );
        $this->assertSame(0, $modeRun['exit'], $modeRun['combined']);

        $plain = $this->cli(['analyze', $this->alpha, $this->beta, '--json', $this->tmp . '/u.json']);
        $this->assertSame(0, $plain['exit'], $plain['combined']);
        $pickMulti = function (array $d): array {
            foreach ($d['clusters'] as $c) {
                if ($c['scope'] === 'multi') {
                    return $c;
                }
            }
            $this->fail('no multi cluster in fixture');
        };
        $this->assertGreaterThan(
            $pickMulti(json_decode((string) file_get_contents($this->tmp . '/u.json'), true))['safety'],
            $pickMulti(json_decode((string) file_get_contents($this->tmp . '/t.json'), true))['safety'],
            'the config-file score_mode key must drive the same stance as the flag',
        );
    }

    public function testTypedModeRewardsTheCrossRepoClusterOnly(): void
    {
        $globalRun = $this->cli(['analyze', $this->alpha, $this->beta, '--json', $this->tmp . '/g.json']);
        $typedRun  = $this->cli(['analyze', $this->alpha, $this->beta, '--json', $this->tmp . '/y.json',
            '--score-mode=typed']);
        $this->assertSame(0, $globalRun['exit'], $globalRun['combined']);
        $this->assertSame(0, $typedRun['exit'], $typedRun['combined']);

        $pick = static function (array $decoded, string $scope): ?array {
            foreach ($decoded['clusters'] as $c) {
                if ($c['scope'] === $scope) {
                    return $c;
                }
            }
            return null;
        };
        $g = json_decode((string) file_get_contents($this->tmp . '/g.json'), true);
        $y = json_decode((string) file_get_contents($this->tmp . '/y.json'), true);

        $multiGlobal = $pick($g, 'multi');
        $multiTyped  = $pick($y, 'multi');
        $this->assertNotNull($multiGlobal);
        $this->assertNotNull($multiTyped);
        $this->assertGreaterThanOrEqual(
            (float) $multiGlobal['safety'],
            (float) $multiTyped['safety'],
        );

        // Same-repo clusters: byte-equal stance-to-stance (the 'global
        // branch untouched' law, proven on the CLI surface too).
        $singleG = $pick($g, 'single');
        $singleY = $pick($y, 'single');
        if ($singleG !== null && $singleY !== null) {
            $this->assertSame($singleG['confidence'], $singleY['confidence']);
            $this->assertSame($singleG['safety'], $singleY['safety']);
        }
    }

    public function testInvalidScopeAndScoreModeExitTwoWithFriendlyMessages(): void
    {
        $empty = $this->cli(['analyze', $this->alpha, '--json', $this->tmp . '/e.json', '--scope=']);
        $this->assertSame(2, $empty['exit'], $empty['combined']);
        $this->assertStringContainsString('phpdup:', $empty['combined']);

        $bogus = $this->cli(['analyze', $this->alpha, '--json', $this->tmp . '/b.json', '--score-mode=sideways']);
        $this->assertSame(2, $bogus['exit'], $bogus['combined']);
        $this->assertStringContainsString('phpdup:', $bogus['combined']);
        $this->assertStringContainsString('--score-mode', $bogus['combined'],
            'the CLI pre-check names the flag; the config-file path names score_mode (ConfigLoader assertEnum)');

        $cfgBogus = $this->cli(
            ['analyze', $this->alpha, '--json', $this->tmp . '/cb.json'],
            ['score_mode' => 'sideways'],
        );
        $this->assertSame(2, $cfgBogus['exit'], $cfgBogus['combined']);
        $this->assertStringContainsString('score_mode', $cfgBogus['combined']);
    }

    // ------------------------------------------------------------------ harness

    /** @param list<string> $args @param array<string, mixed> $cfgExtra */
    private function cli(array $args, array $cfgExtra = [], ?string $cacheDir = null): array
    {
        $cfgPath      = $this->tmp . '/cfg.json';
        $cfgExtraFull = ['cache_dir' => $cacheDir ?? $this->tmp . '/cache', 'min_cluster_impact' => 0] + $cfgExtra; // E3 6.2 re-baseline: this suite pins scope labels, not the impact floor
        file_put_contents($cfgPath, (string) json_encode($cfgExtraFull));

        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        $this->assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

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
                $this->fail('phpdup child exceeded ' . self::TIMEOUT_S . 's: ' . implode(' ', $cmd));
            }
            usleep(50_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $out, 'stderr' => $err, 'combined' => $out . $err];
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
