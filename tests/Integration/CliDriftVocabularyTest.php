<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Item 4.1 (r1 M-1): the drift_flags vocabulary reaches the shipped
 * pipeline through the CLI — profile tier and root config key — and the
 * analyzer is provably INERT without either. This replaces the purely
 * in-process proofs of the first batches with wire evidence: real
 * `bin/phpdup` children over the planted drift/e2e fixture, reading
 * the --json report's architectural_findings spine.
 */
final class CliDriftVocabularyTest extends TestCase
{
    private const TIMEOUT_S = 120.0;

    private string $tmp;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::repoRoot() . '/bin/phpdup')) {
            throw new \RuntimeException('drift vocabulary harness cannot find bin/phpdup');
        }
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup_driftvocab_' . uniqid('t', true);
        mkdir($this->tmp . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    public function testProfileGenericArmsTheGuardDriftFinding(): void
    {
        $run = $this->analyze(['--profile', 'generic']);
        self::assertSame(0, $run['exit'], $run['combined']);

        self::assertContains('drift-missing-guard', $this->driftCodes($run),
            '--profile generic must arm the guard-drift predicate end-to-end');
    }

    public function testRunWithoutProfileOrKeyStaysInert(): void
    {
        $run = $this->analyze([]);
        self::assertSame(0, $run['exit'], $run['combined']);

        self::assertSame([], $this->driftCodes($run),
            'NEGATIVE CONTROL: no profile, no drift_flags key ⇒ zero drift findings on the firing fixture');
    }

    public function testProfileWithoutTheBlockKeepsTheRunInert(): void
    {
        // laravel ships exclude/kinds tuning but NO drift_flags block —
        // admitting a different profile must not smuggle a vocabulary.
        $run = $this->analyze(['--profile', 'laravel']);
        self::assertSame(0, $run['exit'], $run['combined']);

        self::assertSame([], $this->driftCodes($run),
            'NEGATIVE CONTROL: a profile without drift_flags leaves the analyzer inert');
    }

    public function testRootConfigKeyArmsTheFindingWithoutAnyProfile(): void
    {
        $run = $this->analyze([], [
            'drift_flags' => [
                'predicates' => ['guard-drift'],
            ],
        ]);
        self::assertSame(0, $run['exit'], $run['combined']);

        self::assertContains('drift-missing-guard', $this->driftCodes($run),
            'the root phpdup.json drift_flags key arms the analyzer on its own');
    }

    public function testMalformedBlockIsRefusedLoudly(): void
    {
        $run = $this->analyze([], ['drift_flags' => ['predicates' => 'nope']]);
        self::assertSame(2, $run['exit'], $run['combined']);
        self::assertStringContainsString('drift_flags', $run['combined']);
    }

    public function testUnknownPredicateCodeIsRefusedLoudly(): void
    {
        $run = $this->analyze([], ['drift_flags' => ['predicates' => ['bogus-code']]]);
        self::assertSame(2, $run['exit'], $run['combined']);
        self::assertStringContainsString('drift_flags.predicates', $run['combined']);
    }

    /**
     * analyze the planted guard-drift fixture dir; $args ride the CLI,
     * $cfgExtra the root config file (cache_dir is always set hermetic).
     *
     * @param list<string> $args
     * @param array<string,mixed> $cfgExtra
     * @return array{exit:int,out:string,err:string,combined:string}
     */
    private function analyze(array $args, array $cfgExtra = []): array
    {
        // The e2e fixture dir is a FOUR-member near-clone family: the
        // default df floor (max(2, 1% of blocks)) would starve every
        // shared-tail posting list before scoring, so the ride member
        // never becomes a candidate. --max-df 1.0 opens the gate; this
        // is a candidate-GENERATION knob only — the analyzer arming
        // under test is --profile generic / the root drift_flags key.
        $target = realpath(self::repoRoot() . '/tests/Fixtures/drift/e2e');
        self::assertIsString($target);
        $this->reportPath = $this->tmp . '/out.json';

        return $this->cli(
            array_merge(['analyze', $target, '--min-impact', '0', '--max-df', '1.0', '--limit', '10', '--json', $this->reportPath], $args),
            $cfgExtra,
        );
    }

    /** @return list<string> every drift-* finding code in the report */
    private function driftCodes(array $run): array
    {
        self::assertIsString($this->reportPath);
        self::assertFileExists($this->reportPath, 'no --json report produced: ' . substr($run['combined'], 0, 400));
        $decoded = json_decode((string) file_get_contents($this->reportPath), true);
        self::assertIsArray($decoded, 'the --json report must be parseable');

        $codes = [];
        foreach ($decoded['clusters'] ?? [] as $cluster) {
            foreach ($cluster['architectural_findings'] ?? [] as $finding) {
                if (is_array($finding) && is_string($finding['code'] ?? null) && str_starts_with($finding['code'], 'drift-')) {
                    $codes[] = $finding['code'];
                }
            }
        }
        return $codes;
    }

    private string $reportPath = '';

    /** @return array{exit:int,out:string,err:string,combined:string} */
    private function cli(array $args, array $cfgExtra = []): array
    {
        $cfgPath = $this->tmp . '/cfg.json';
        file_put_contents($cfgPath, (string) json_encode(['cache_dir' => $this->tmp . '/cache'] + $cfgExtra));

        $cmd  = array_merge([PHP_BINARY, self::repoRoot() . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->tmp);
        self::assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        $outOpen = $errOpen = true;
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
                self::fail('phpdup child timed out: ' . implode(' ', $args));
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

        $result = ['exit' => proc_close($proc), 'out' => $out, 'err' => $err, 'combined' => $out . $err];
        return $result;
    }

    private static function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../../');
        self::assertIsString($root);
        return $root;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
