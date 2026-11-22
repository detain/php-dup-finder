<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * engine-040 half B — end-to-end proof that the relocation-miss hard
 * failure on a mainstream corpus is closed.
 *
 * The finding (php-dup-finder-engine-040) killed a full-corpus run with an
 * uncaught RuntimeException ("BlockAstLoader: could not relocate block …
 * (kind if, hinted range …)") once a file hosted the compact one-line
 * `if … } else if …` shape (php-parser nests the `else if` as a SECOND If_
 * node on the exact same span) AND a warm cluster-cache replay handed the
 * refactor stage stale member hints. Two loader laws close it: the
 * node-identity claims + always-skip+note escalation (engine-S17a) and the
 * per-object anchor obligation across overlapping replayed clusters
 * (engine-S17b, the ProgressIndicator crash the vendor probe reproduced).
 *
 * This test drives the REAL CLI over a generated corpus of that family:
 * cold run → warm replay (nothing changed) → comment-only drift (line
 * numbers move, structuralHashes do not — the cluster cache still hits and
 * the replayed hints go stale) → warm replay again. The drifted replay must
 * exit 0 with a COMPLETE report, must actually replay from the cluster
 * cache (the non-vacuity witness), and the report it emits must be
 * byte-identical to what a fresh cold scan of the drifted corpus finds —
 * the rebase anchors exactly like the truth, never a silent mis-anchor.
 */
final class RelocationWarmReplayTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) sys_get_temp_dir() . '/phpdup-s17b-replay-' . uniqid('', true);
        mkdir($this->tmp . '/corpus', 0o775, true);
        mkdir($this->tmp . '/reports', 0o775, true);
        foreach (self::corpusFiles() as $rel => $contents) {
            $path = $this->tmp . '/corpus/' . $rel;
            @mkdir(dirname($path), 0o775, true);
            file_put_contents($path, $contents);
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testWarmReplayOverCompactChainCorpusExitsZeroAndAnchorsLikeFreshTruth(): void
    {
        // 1. COLD: the cluster cache is seeded from live bytes; every range
        //    is minted in-process (trustFreshScan) so the sweep must not run.
        $coldOut = $this->cacheDir('cold');
        $cold = $this->analyze($this->tmp . '/reports/cold.json', $coldOut, 'cold');
        self::assertSame(0, $cold['rc'], 'cold run must exit 0' . $this->tailOf($cold['out']));
        self::assertStringNotContainsString(self::CRASH_TOKEN, self::squeeze($cold['out']),
            'a cold run must never meet the relocation crash');
        $coldReport = $this->report($this->tmp . '/reports/cold.json');
        self::assertGreaterThanOrEqual(1, (int) $coldReport['summary']['clusters'],
            'the corpus must produce clusters or the whole probe is vacuous');
        self::assertGreaterThan(0, $this->clustersTouching($coldReport, 'compact/'),
            'a cluster must cover the compact-chain family (the finding\'s shape)');

        // 2. WARM, nothing changed: the cluster cache hits, the replay
        //    announces itself, and the sweep sees hints == live truth.
        $warm = $this->analyze($this->tmp . '/reports/warm0.json', $coldOut, 'warm0');
        self::assertSame(0, $warm['rc'], 'warm replay must exit 0' . $this->tailOf($warm['out']));
        self::assertMatchesRegularExpression('/reused \d+ clusters from cache/', $warm['out'],
            'the replay witness: the cluster cache must actually serve this run');

        // 3. Comment-only drift: every line number below the header shifts by
        //    3 while every structuralHash survives (comments are not in the
        //    canonical stream) — the cluster cache still hits, so the replayed
        //    member hints are now stale. This is the engine-040 trigger.
        $this->driftCorpus();

        $drifted = $this->analyze($this->tmp . '/reports/warm1.json', $coldOut, 'warm1');
        self::assertSame(0, $drifted['rc'],
            'the stale-hint replay must exit 0 — no uncaught relocation RuntimeException'
            . $this->tailOf($drifted['out']));
        self::assertMatchesRegularExpression('/reused \d+ clusters from cache/', $drifted['out'],
            'the drifted run must still be a cluster-cache replay (non-vacuous sweep)');
        self::assertStringNotContainsString(self::CRASH_TOKEN, self::squeeze($drifted['out']),
            'the run may skip with a note, never die: ' . $this->tailOf($drifted['out']));
        $driftReport = $this->report($this->tmp . '/reports/warm1.json');
        self::assertSame((int) $coldReport['summary']['clusters'], (int) $driftReport['summary']['clusters'],
            'the drifted replay must emit a complete report, not a truncated one');

        // 4. The determinism fence: a FRESH cold scan of the SAME drifted
        //    corpus (separate cache) is the ground truth for every member
        //    line. The replayed + rebased report must equal it BYTE-FOR-BYTE —
        //    the sweep re-anchors each member onto the node a fresh scan
        //    finds, which is the exact end-to-end meaning of "never silently
        //    mis-anchor".
        $truthOut = $this->cacheDir('truth');
        $truth = $this->analyze($this->tmp . '/reports/truth.json', $truthOut, 'truth');
        self::assertSame(0, $truth['rc'], 'the fresh-truth run must exit 0' . $this->tailOf($truth['out']));
        self::assertSame(
            (string) file_get_contents($this->tmp . '/reports/truth.json'),
            (string) file_get_contents($this->tmp . '/reports/warm1.json'),
            'the warm-replayed report must equal the fresh cold scan byte-for-byte',
        );
    }

    /**
     * The crash line as the Symfony box would render it, whitespace-squeezed:
     * the boxed renderer hard-wraps long messages at terminal width and can
     * break the literal anywhere, so the match runs on the squashed text.
     */
    private const CRASH_TOKEN = 'couldnotrelocate';

    private static function squeeze(string $text): string
    {
        return (string) preg_replace('/\s+/', '', $text);
    }

    /** @return array<string,string> corpus-relative path => contents */
    private static function corpusFiles(): array
    {
        // The Php7.php:1635 shape: a compact one-line if/else-if/else chain.
        // php-parser nests the `else if` (two keywords, not `elseif`) as a
        // second If_ node sharing the outer node's exact span — the geometry
        // whose span-shadow killed the vendor run. Each host carries the
        // chain TWICE (content twins: the outer class and the inner class
        // each get two live members) plus an ordinary multi-line twin gate,
        // so the warm replay sweeps several positional keys at once.
        $chain = '        if ($x > 1) { $x += 1; } else if ($x > 2) { $x = min($x, 2); } else { $x += 3; };';

        $files = [];
        foreach (['A', 'B', 'C'] as $host) {
            $fillers = '';
            for ($i = 1; $i <= 6; $i++) {
                $fillers .= sprintf("        // f%02d\n", $i);
            }
            $files["compact/Host{$host}.php"] =
                "<?php\n\nnamespace S17b\\Compact;\n\nclass Host{$host}\n{\n"
                . "    public function chain(int \$x): int {\n"
                . $fillers . $chain . "\n\n" . $chain . "\n"
                . "        return \$x;\n    }\n\n"
                . self::gateMethod("gate{$host}") . "\n}\n";
        }
        foreach (['One', 'Two'] as $n) {
            $files["plain/{$n}.php"] =
                "<?php\n\nnamespace S17b\\Plain;\n\nclass {$n}\n{\n" . self::gateMethod($n) . "\n}\n";
        }
        return $files;
    }

    /** The ordinary-duplicate family (vendor/symfony-shaped bulk geometry). */
    private static function gateMethod(string $marker): string
    {
        return "    public function gate{$marker}(int \$v): int {\n"
            . "        \$a = \$v * 2;\n        \$b = abs(\$a - 7);\n"
            . "        if (\$b > 3) {\n            \$b = \$b + \$a;\n        } else {\n            \$b = min(\$b, \$a);\n        }\n"
            . "        if (\$b === 0) {\n            throw new \\RuntimeException('zero gate');\n        }\n"
            . "        return \$b;\n    }";
    }

    private function cacheDir(string $name): string
    {
        $dir = $this->tmp . '/cache-' . $name;
        mkdir($dir, 0o775, true);
        return $dir;
    }

    /**
     * Run the real CLI over the corpus. --workers 1 pins the serial path so
     * every phase shares one deterministic assignment law; --min-impact 1
     * keeps the tiny clusters reportable.
     *
     * @return array{rc:int,out:string}
     */
    private function analyze(string $jsonPath, string $cacheDir, string $label): array
    {
        $config = $this->tmp . '/cfg-' . $label . '.json';
        file_put_contents($config, (string) json_encode(['cache_dir' => $cacheDir]));
        $cmd = sprintf(
            '%s %s analyze %s --json %s --workers 1 --min-impact 1 --config %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/phpdup'),
            escapeshellarg($this->tmp . '/corpus'),
            escapeshellarg($jsonPath),
            escapeshellarg($config),
        );
        $lines = [];
        exec($cmd, $lines, $rc);
        return ['rc' => $rc, 'out' => implode("\n", $lines)];
    }

    /** Shift every line below the header by 3 with comment-only drift. */
    private function driftCorpus(): void
    {
        foreach (self::corpusFiles() as $rel => $_) {
            $path = $this->tmp . '/corpus/' . $rel;
            $lines = explode("\n", (string) file_get_contents($path));
            array_splice($lines, 1, 0, ['// drift-a', '// drift-b', '// drift-c']);
            file_put_contents($path, implode("\n", $lines));
        }
    }

    /** @return array<string,mixed> */
    private function report(string $path): array
    {
        self::assertFileExists($path, 'the --json sink must carry a complete report');
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'the JSON report must parse');
        return $decoded;
    }

    /** @param array<string,mixed> $report */
    private function clustersTouching(array $report, string $pathNeedle): int
    {
        $hits = 0;
        foreach ($report['clusters'] ?? [] as $cluster) {
            foreach ($cluster['members'] ?? [] as $member) {
                if (str_contains((string) ($member['file'] ?? ''), $pathNeedle)) {
                    $hits++;
                    break;
                }
            }
        }
        return $hits;
    }

    private function tailOf(string $out): string
    {
        return $out === '' ? '' : "\n--- last lines ---\n" . implode("\n", array_slice(explode("\n", $out), -8));
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
