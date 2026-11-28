<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 5.2 (W2b) — end-to-end through the real pipeline and the real
 * CLI: a vendored copy tree declared by the root's composer.lock, a
 * header-marked workerman-style copy outside any vendor dir, a clean
 * first-party root, and (via exclude_reset) a nested vendor/ tree that
 * the default excludes would normally hide. --scope arms the demotion;
 * the default run must not grow the key at all — the conditional-key
 * law goldens live inside.
 */
final class VendoredScopeE2ETest extends TestCase
{
    private string $tmp;
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = dirname(__DIR__, 2) . '/bin/phpdup';
        $this->tmp = sys_get_temp_dir() . '/phpdup-52-e2e-' . uniqid();
        mkdir($this->tmp . '/cache', 0o775, true);
        $this->buildAppA();
        $this->buildAppB();
        $this->buildAppC();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testUnarmedRunGrowsNoKeyAndLosesNoClusters(): void
    {
        $payload = $this->runRoot('appA', null, 'unarmed.json');

        self::assertSame(0, $payload['exit'], 'plain analyze exits 0');
        self::assertArrayNotHasKey('vendored_duplicates', $payload['summary']);

        $mainFiles = $this->mainFiles($payload);
        foreach (['N1', 'N2', 'W1', 'W2', 'S1', 'S2', 'X1', 'X2'] as $tag) {
            self::assertStringContainsString($tag, $mainFiles, "scope=all keeps $tag in the reported slice");
        }
    }

    public function testArmedScopeDemotesVendoredFamiliesOnly(): void
    {
        [$payload, $stderr] = $this->runRootCapturing('appA', 'appA', 'armed.json');

        self::assertSame(0, $payload['exit']);
        $section = $payload['summary']['vendored_duplicates'] ?? null;
        self::assertIsArray($section, 'armed scope opens the conditional key');
        self::assertGreaterThanOrEqual(2, $section['clusters'], 'the lock-leg pair and the header-leg pair both demote');

        $labels = [];
        foreach ($section['rows'] as $row) {
            foreach ($row['signals'] as $s) {
                $labels[$s] = true;
            }
        }
        self::assertArrayHasKey('composer_lock_path:eusebiob/nusoap', $labels, 'the doc archetype: nusoap committed under include/');
        self::assertArrayHasKey('upstream_header:workerman', $labels, 'a committed copy marked by its own banner');

        $mainFiles = $this->mainFiles($payload);
        self::assertStringNotContainsString('N1', $mainFiles);
        self::assertStringNotContainsString('W1', $mainFiles);
        self::assertStringContainsString('S1', $mainFiles, 'first-party clones stay in the slice');
        self::assertStringContainsString('X1', $mainFiles);
        self::assertStringContainsString('X2', $mainFiles, 'the fork-edited mixed cluster is not vendored<->vendored — it stays');

        self::assertStringContainsString('demoted', $stderr, 'one honest visibility line on stderr');
    }

    public function testArmedRunOverCleanRootIsZeroShapedAndQuiet(): void
    {
        [$payload, $stderr] = $this->runRootCapturing('appB', 'appB', 'clean.json');

        self::assertSame(0, $payload['exit']);
        self::assertSame(
            ['clusters' => 0, 'lines' => 0, 'omitted' => 0, 'rows' => []],
            $payload['summary']['vendored_duplicates'] ?? null,
            'armed + vendored-free = zero-shaped section, never a missing key',
        );
        self::assertStringNotContainsString('demoted', $stderr, 'nothing demoted, nothing announced');
        self::assertStringContainsString('S1', $this->mainFiles($payload), 'the first-party pair still reports');
    }

    public function testExcludeResetExposesNestedVendorTreeToTheVendorDirLeg(): void
    {
        [$payload, $stderr] = $this->runRootCapturing('appC', 'appC', 'nested.json', [
            'exclude_reset' => true,
        ]);

        self::assertSame(0, $payload['exit']);
        $section = $payload['summary']['vendored_duplicates'] ?? null;
        self::assertIsArray($section);
        self::assertGreaterThanOrEqual(1, $section['clusters'],
            'with the default vendor/** excludes lifted, the committed vendor tree is still caught — by its directory shape');

        $found = false;
        foreach ($section['rows'] as $row) {
            $found = $found || in_array('vendor_dir', $row['signals'], true);
        }
        self::assertTrue($found);
        self::assertStringContainsString('demoted', $stderr);
    }

    // ------------------------------------------------------------------ corpora

    /** appA: lock-declared copy + header-marked copy + first-party + mixed fork. */
    private function buildAppA(): void
    {
        $root = $this->tmp . '/appA';
        mkdir($root . '/include/nusoap', 0o775, true);
        mkdir($root . '/lib/wm', 0o775, true);
        mkdir($root . '/src', 0o775, true);

        file_put_contents($root . '/composer.lock', (string) json_encode([
            'packages'     => [['name' => 'eusebiob/nusoap', 'version' => '1.0.0']],
            'packages-dev' => [],
        ]));

        // Vendored family 1 (lock leg): copies of the declared package.
        $this->pair($root . '/include/nusoap', 'N', $this->soapBody());
        // Vendored family 2 (header leg): workerman-style copies, no lock row.
        $this->pair($root . '/lib/wm', 'W', $this->workerBody(), workermanHeader: true);
        // First-party family (stays under any scope).
        $this->pair($root . '/src', 'S', $this->serviceBody());
        // Mixed fork: first-party edit + committed copy of the same edited body.
        mkdir($root . '/mixedsplit', 0o775, true);
        $this->pair($root . '/mixedsplit', 'X', $this->forkBody());
        // X1 sits in src/ (first-party), X2 under include/nusoap/ (lock-leg).
        rename($root . '/mixedsplit/X1.php', $root . '/src/X1.php');
        rename($root . '/mixedsplit/X2.php', $root . '/include/nusoap/X2.php');
        rmdir($root . '/mixedsplit');
    }

    /** appB: clean root — no lock, no headers, nothing under vendor/. */
    private function buildAppB(): void
    {
        $root = $this->tmp . '/appB';
        mkdir($root . '/src', 0o775, true);
        $this->pair($root . '/src', 'S', $this->serviceBody());
    }

    /** appC: nested vendor tree WITHOUT a composer.lock — vendor_dir leg, exclude_reset. */
    private function buildAppC(): void
    {
        $root = $this->tmp . '/appC';
        mkdir($root . '/scripts/bot1/vendor/detain/clone', 0o775, true);
        $this->pair($root . '/scripts/bot1/vendor/detain/clone', 'B', $this->botBody());
    }

    /** @param array<string, mixed> $extraConfig @return array<string, mixed> */
    private function runRoot(string $rootName, ?string $scope, string $outName, array $extraConfig = []): array
    {
        [$payload] = $this->runRootCapturing($rootName, $scope, $outName, $extraConfig);
        return $payload;
    }

    /**
     * @param array<string, mixed> $extraConfig
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function runRootCapturing(string $rootName, ?string $scope, string $outName, array $extraConfig = []): array
    {
        $cfgPath = $this->tmp . '/' . $rootName . '.cfg.json';
        file_put_contents($cfgPath, (string) json_encode($extraConfig + [
            'cache_dir'          => $this->tmp . '/cache-' . $rootName,
            'min_cluster_impact' => 0,
        ]));

        $out = $this->tmp . '/' . $outName;
        $argv = ['analyze', $this->tmp . '/' . $rootName, '--json', $out, '-c', $cfgPath];
        if ($scope !== null) {
            $argv[] = '--scope=' . $scope;
        }

        [$stdout, $stderr, $exit] = $this->cli($argv);
        self::assertFileExists($out, "CLI run produced no JSON (stdout: $stdout stderr: $stderr exit: $exit)");

        $payload = json_decode((string) file_get_contents($out), true);
        self::assertIsArray($payload);
        $payload['exit'] = $exit;
        return [$payload, $stderr];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function cli(array $argv): array
    {
        $cmd = array_merge([PHP_BINARY, $this->bin], $argv);
        $proc = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 120.0;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                self::fail('phpdup child exceeded 120s: ' . implode(' ', $cmd));
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$stdout, $stderr, $exit];
    }

    /** @param array<string, mixed> $payload */
    private function mainFiles(array $payload): string
    {
        $blob = '';
        foreach ($payload['clusters'] ?? [] as $cluster) {
            foreach ($cluster['members'] ?? [] as $member) {
                $blob .= ($member['file'] ?? '') . "\n";
            }
        }
        return $blob;
    }

    private function pair(string $dir, string $prefix, string $body, bool $workermanHeader = false): void
    {
        foreach (['1', '2'] as $i) {
            $head = $workermanHeader
                ? "/*\n * This file is part of the workerman framework, under the MIT license.\n */\n"
                : '';
            $fn = $prefix . 'func' . $i;
            file_put_contents(
                $dir . '/' . $prefix . $i . '.php',
                "<?php\n$head" . str_replace('__FN__', $fn, $body),
            );
        }
    }

    // Families differ in their tokens, not just their function names, so
    // each pair forms its OWN cluster and the pairs stay apart.

    private function soapBody(): string
    {
        return <<<'PHP'
            function __FN__($msg, $enc) {
                if ($enc === 'iso') { $out = iconv('UTF-8', 'ISO-8859-1', $msg); }
                elseif ($enc === 'utf16') { $out = mb_convert_encoding($msg, 'UTF-16', 'UTF-8'); }
                else { $out = $msg; }
                $len = strlen($out);
                for ($k = 0; $k < $len; $k += 4) { $chunk = substr($out, $k, 4); $sum = $sum . crc32($chunk); }
                return $sum;
            }
            PHP;
    }

    private function workerBody(): string
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

    private function serviceBody(): string
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

    private function forkBody(): string
    {
        return <<<'PHP'
            function __FN__(string $host, int $port): bool {
                $sock = @fsockopen($host, $port, $errNo, $errStr, 2.5);
                if ($sock === false) { error_log("connect failed $errStr"); return false; }
                stream_set_timeout($sock, 3);
                $ok = fwrite($sock, "PING\r\n") !== false;
                fclose($sock);
                return $ok;
            }
            PHP;
    }

    private function botBody(): string
    {
        return <<<'PHP'
            function __FN__(iterable $jobs, int $limit): int {
                $done = 0;
                foreach ($jobs as $job) {
                    if ($done >= $limit) { break; }
                    $handled = $job->run();
                    if ($handled) { $done++; }
                    usleep(50);
                }
                return $done;
            }
            PHP;
    }
}
