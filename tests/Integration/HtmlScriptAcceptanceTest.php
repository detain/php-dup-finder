<?php

declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b — pipeline-level acceptance of inline-<script> extraction.
 *
 * Runs the real CLI over a TMP COPY of tests/Fixtures/html-script (never
 * the Fixtures tree itself) and pins the three mission claims end to end:
 *
 *  1. default config is inert — html_script never rides the default roster;
 *  2. --kinds html_script surfaces the chrome-pairing: the two .phtml files
 *     whose JS-in-<script> bodies match cluster ACROSS different HTML
 *     chrome (and the babel pair fuses despite language= vs type=);
 *  3. mixed-language apply stays NO-GO in v1: --apply halts with the typed
 *     HtmlFragmentApplyRefusal through the Command catch-union, writes
 *     nothing, and exits 1.
 */
final class HtmlScriptAcceptanceTest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;
    private string $corpus;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/t1a-htmlscript-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/cache', 0777, true);
        $this->corpus = $this->tmp . '/corpus';
        mkdir($this->corpus, 0777, true);
        foreach (glob(__DIR__ . '/../Fixtures/html-script/*.phtml') ?: [] as $file) {
            copy($file, $this->corpus . '/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testDefaultConfigNeverMintsHtmlScriptMembers(): void
    {
        $run = $this->cli(['analyze', $this->corpus, '--json', $this->tmp . '/default.json']);
        self::assertSame(0, $run['exit'], 'default run must exit 0: ' . $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/default.json'), true);
        self::assertIsArray($decoded);
        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['members'] as $member) {
                self::assertNotSame('html_script', $member['kind'], 'default roster must never surface html_script');
            }
        }
    }

    public function testOptInClustersScriptWindowsAcrossHtmlChromeVariance(): void
    {
        $run = $this->cli([
            'analyze', $this->corpus,
            '--kinds', 'html_script',
            '--min-impact', '5',
            '--workers', '1',
            '--json', $this->tmp . '/optin.json',
        ]);
        self::assertSame(0, $run['exit'], 'opt-in run must exit 0: ' . $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/optin.json'), true);
        self::assertIsArray($decoded);

        $chromeCluster = self::clusterPairing($decoded, 'chrome-a.phtml', 'chrome-b.phtml');
        self::assertNotNull($chromeCluster, 'identical JS must cluster across HTML variance');
        foreach ($chromeCluster as $member) {
            self::assertSame('html_script', $member['kind'], 'the pairing rides html_script window folds');
        }

        $babelCluster = self::clusterPairing($decoded, 'babel-a.phtml', 'babel-b.phtml');
        self::assertNotNull($babelCluster, 'text/babel windows must fuse across language= vs type= tag forms');
        foreach ($babelCluster as $member) {
            self::assertSame('html_script', $member['kind']);
        }

        // The escaped-window specimen is the corpus's only singleton fold:
        // it is extracted (unit-pinned in BlockExtractorHtmlScriptCorpusTest
        // with its full markup/stmt lineage) but has no twin to fuse with —
        // and it must NOT leak into either pairing cluster.
        self::assertNull(self::memberOf($decoded, 'escaped-window.phtml'));
        self::assertCount(2, $decoded['clusters'], 'exactly the chrome pair and the babel pair cluster');
    }

    public function testMixedLanguageApplyRefusesLoudlyAndWritesNothing(): void
    {
        $before = self::hashTree($this->corpus);

        $run = $this->cli([
            'analyze', $this->corpus,
            '--kinds', 'html_script',
            '--min-impact', '5',
            '--workers', '1',
            '--apply', '--dry-run=false',
        ]);

        self::assertSame(1, $run['exit'], 'v1 mixed-language apply halts with exit 1: ' . $run['combined']);
        self::assertStringContainsString('phpdup:', $run['combined']);
        self::assertStringContainsString('html_script', $run['combined']);

        self::assertSame($before, self::hashTree($this->corpus), 'refusal must not touch a single corpus byte');
        self::assertFileDoesNotExist($this->tmp . '/refactored');
        self::assertFileDoesNotExist($this->corpus . '/refactored');
    }

    /**
     * @param array<string,mixed>   $decoded
     * @param array<int,array{file:string,start:int,end:int,kind:string,namespace:?string,class:?string,name:?string,size:int,repo:string,scope:string}> $pair
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
