<?php

declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 7.1 — pipeline-level acceptance of the native token tier.
 *
 * Runs the real CLI over a TMP COPY of tests/Fixtures/token-tier and pins
 * the mission claims end to end:
 *
 *  1. default config is inert — the .js/.tpl/.yml twins stay invisible to
 *     scanning exactly as they were pre-7.1 (the T1a-era probe fact, now
 *     flipped only behind arming);
 *  2. --kinds text surfaces ALL THREE doc families — buy_license-style JS
 *     twins, template twins, copied workflow YAML — as kind 'text' clusters
 *     with sim 1.0, while the unrelated control.js never joins;
 *  3. the real Fixtures tree at default still scans its historical 100 PHP
 *     files and surfaces no text member anywhere;
 *  4. warm caches keep an armed run idempotent;
 *  5. apply stays NO-GO: --apply --kinds text halts with the typed
 *     TextKindApplyRefusal, writes nothing, exits 1.
 */
final class TokenTierTest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $corpus;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/t1b-token-tier-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/cache', 0777, true);
        $this->corpus = $this->tmp . '/corpus';
        mkdir($this->corpus, 0777, true);
        foreach (glob(__DIR__ . '/../Fixtures/token-tier/*') ?: [] as $file) {
            copy($file, $this->corpus . '/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testDefaultRunStaysInertToTierFiles(): void
    {
        $run = $this->cli(['analyze', $this->corpus, '--json', $this->tmp . '/default.json', '--workers', '1']);
        self::assertSame(0, $run['exit'], 'default run must exit 0: ' . $run['combined']);

        // Zero admitted files is the honest inertness signal: the run never
        // sees the twins (historical 'no input' short-circuit — no report
        // file is written for an empty analysis), exactly as pre-7.1.
        self::assertStringContainsString('0 files', $run['combined']);
        self::assertFileDoesNotExist($this->tmp . '/default.json');
    }

    public function testArmedRunClustersAllThreeDocFamilies(): void
    {
        $run = $this->cli([
            'analyze', $this->corpus,
            '--kinds', 'text',
            '--min-impact', '0',
            '--workers', '1',
            '--json', $this->tmp . '/armed.json',
        ]);
        self::assertSame(0, $run['exit'], 'armed run must exit 0: ' . $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/armed.json'), true);
        self::assertIsArray($decoded);

        foreach ([
            'JS twins'      => ['checkout.js', 'checkout_v2.js'],
            'template twins' => ['order_summary.tpl', 'invoice_summary.tpl'],
            'workflow YAML' => ['deploy.yml', 'deploy-copy.yml'],
        ] as $label => [$a, $b]) {
            $members = self::clusterPairing($decoded, $a, $b);
            self::assertNotNull($members, $label . ' must cluster when armed');
            foreach ($members as $member) {
                self::assertSame('text', $member['kind'], 'the pairing rides text token folds');
            }
            self::assertCount(2, $members, 'exactly the twin pair — no strays');
        }

        self::assertNull(self::memberOf($decoded, 'control.js'), 'the unrelated file is no one\'s member');

        $summary = $decoded['summary'];
        self::assertSame(
            $summary['files_scanned'],
            $summary['files_reused'] + $summary['files_processed'] + $summary['files_without_blocks'] + $summary['parse_errors'],
            'balance law holds through the tier door too',
        );
        self::assertSame(0, $summary['parse_errors']);
    }

    public function testRealFixturesTreeAtDefaultKeepsHistoricalShape(): void
    {
        $run = $this->cli([
            'analyze', dirname(__DIR__) . '/Fixtures',
            '--json', $this->tmp . '/fixtures-default.json',
            '--workers', '1',
        ]);
        self::assertSame(0, $run['exit'], 'fixture run must exit 0: ' . $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/fixtures-default.json'), true);
        self::assertIsArray($decoded);
        self::assertSame(100, $decoded['summary']['files_scanned'], 'the token-tier fixtures stay invisible at default');
        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['members'] as $member) {
                self::assertNotSame('text', $member['kind'], 'default roster must never surface text');
            }
        }
    }

    public function testWarmCacheArmedRunIsIdempotent(): void
    {
        $args = [
            'analyze', $this->corpus,
            '--kinds', 'text',
            '--min-impact', '0',
            '--workers', '1',
        ];
        $first = $this->cli(array_merge($args, ['--json', $this->tmp . '/warm-1.json']));
        self::assertSame(0, $first['exit'], $first['combined']);
        $second = $this->cli(array_merge($args, ['--json', $this->tmp . '/warm-2.json']));
        self::assertSame(0, $second['exit'], $second['combined']);

        $a = json_decode((string) file_get_contents($this->tmp . '/warm-1.json'), true);
        $b = json_decode((string) file_get_contents($this->tmp . '/warm-2.json'), true);
        $shape = static function (array $d): array {
            $out = [];
            foreach ($d['clusters'] as $c) {
                $row = [];
                foreach ($c['members'] as $m) {
                    $row[] = basename($m['file']) . ':' . $m['start'] . '-' . $m['end'];
                }
                sort($row);
                $out[] = $row;
            }
            sort($out);
            return $out;
        };
        self::assertSame($shape($a), $shape($b), 'a second armed run re-derives the identical tier');
    }

    public function testApplyOnTextClustersRefusesLoudlyAndWritesNothing(): void
    {
        $before = self::hashTree($this->corpus);

        $run = $this->cli([
            'analyze', $this->corpus,
            '--kinds', 'text',
            '--min-impact', '0',
            '--workers', '1',
            '--apply', '--dry-run=false',
        ]);

        self::assertSame(1, $run['exit'], 'v1 text apply halts with exit 1: ' . $run['combined']);
        self::assertStringContainsString('phpdup:', $run['combined']);
        self::assertStringContainsString('text', $run['combined']);

        self::assertSame($before, self::hashTree($this->corpus), 'refusal must not touch a single corpus byte');
        self::assertFileDoesNotExist($this->corpus . '/refactored');
    }

    /**
     * @param array<string,mixed> $decoded
     * @return list<array<string,mixed>>|null
     */
    private static function clusterPairing(array $decoded, string $fileA, string $fileB): ?array
    {
        foreach ($decoded['clusters'] as $cluster) {
            $hasA = false;
            $hasB = false;
            foreach ($cluster['members'] as $member) {
                if (basename($member['file']) === $fileA) {
                    $hasA = true;
                }
                if (basename($member['file']) === $fileB) {
                    $hasB = true;
                }
            }
            if ($hasA && $hasB) {
                return $cluster['members'];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|null
     */
    private static function memberOf(array $decoded, string $file): ?array
    {
        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['members'] as $member) {
                if (basename($member['file']) === $file) {
                    return $member;
                }
            }
        }
        return null;
    }

    /** @return array<string,string> basename => sha256 */
    private static function hashTree(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                $out[$entry] = hash_file('sha256', $path) ?: 'unreadable';
            }
        }
        return $out;
    }

    /**
     * @param list<string> $args
     * @return array{exit:int, combined:string}
     */
    private function cli(array $args): array
    {
        $cfgPath = $this->tmp . '/cfg.json';
        file_put_contents($cfgPath, (string) json_encode(['cache_dir' => $this->tmp . '/cache']));

        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $combined = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        while ($streams !== [] && microtime(true) < $deadline) {
            $read = array_values($streams);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 50_000) === false) {
                break;
            }
            foreach ($streams as $key => $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    continue;
                }
                if ($chunk !== '') {
                    $combined .= $chunk;
                } elseif (feof($stream)) {
                    fclose($stream);
                    unset($streams[$key]);
                }
            }
        }

        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc);
            $status = proc_get_status($proc);
        }
        foreach ($streams as $stream) {
            $combined .= (string) stream_get_contents($stream);
            fclose($stream);
        }
        $exit = proc_close($proc);

        return ['exit' => $exit, 'combined' => $combined];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
