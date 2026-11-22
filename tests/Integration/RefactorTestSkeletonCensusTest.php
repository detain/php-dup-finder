<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * r2 N-1 — the pin whose absence let 128/159 real-corpus skeletons ship
 * unparseable while every synthetic test stayed green: project the ACTUAL
 * hole vocabulary (sigil-carrying names, class-string types) by running the
 * real CLI over the real corpora, then php -l every emitted skeleton.
 *
 * Roots scanned: src/ (the repo self-scan the review ran) and tests/Fixtures/
 * (the golden corpora). Both must reach ZERO lint failures — the census
 * count itself is also asserted so the run cannot silently go vacuous.
 */
final class RefactorTestSkeletonCensusTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-skel-census-' . uniqid('', true);
        mkdir($this->tmp . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    public function testSelfScanSkeletonsAllParse(): void
    {
        $this->census('src', 100);
    }

    public function testFixturesCorpusSkeletonsAllParse(): void
    {
        $this->census('tests/Fixtures', 1);
    }

    /**
     * output-S35 (php-dup-finder-output-007, CRITICAL) — the load-bearing
     * pin, driven through the REAL pipeline exactly as SEC re-proved it:
     * a scanned tree whose cluster's differing statement holds a concat
     * expression (prettyPrintExpr of any expression is quote-shaped, first
     * char === last char). The retired passthrough emitted
     * `'' . touch(canary) . ''` RAW into casesProvider(), where PHPUnit
     * evaluates it at COLLECTION time — a generator-side code-execution
     * sink. This test demands, on real emitted bytes:
     *   1. non-vacuity: the payload token DID reach the skeleton;
     *   2. the raw `=> ['' . touch` row shape is GONE;
     *   3. every skeleton lints;
     *   4. the provider COLLECTS under `disable_functions=touch` and
     *      returns the payload byte-for-byte as string data (the exact
     *      array PHPUnit would build);
     *   5. the canary file the payload names was never created.
     */
    public function testHostileQuoteShapedExprShipsAsDataNotLiveCode(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $this->tmp . '/hostile';
        $out = $this->tmp . '/hostile_out';
        mkdir($src, 0o775, true);
        mkdir($out, 0o775, true);
        $canary = $this->tmp . '/CANARY_MUST_NOT_EXIST';

        // Aligned bodies, one statement diverging in SHAPE: the seed's
        // whole concat becomes the hole's observed value (measured at the
        // live repro — a same-shape pair recurses to the string leaf and
        // never produces the sink shape).
        $commonHead = "<?php\nclass %s\n{\n    public function stamp(string \$tag): string\n    {\n"
            . "        \$log = strtolower(\$tag);\n        \$len = strlen(\$log);\n"
            . "        \$pad = str_repeat('.', \$len);\n        \$now = time();\n"
            . "        \$env = getenv('APP_ENV');\n";
        $commonTail = "        \$out = \$pad . '|' . \$log . '|' . \$env;\n"
            . "        error_log('stamp ' . \$out);\n        return \$out . \$v;\n    }\n}\n";
        file_put_contents($src . '/A.php', sprintf($commonHead, 'HostileA') . "        \$v = '' . touch('" . $canary . "') . '';\n" . $commonTail);
        file_put_contents($src . '/B.php', sprintf($commonHead, 'HostileB') . "        \$v = date('c');\n" . $commonTail);

        $config = $this->tmp . '/phpdup-hostile.json';
        file_put_contents($config, json_encode(['cache_dir' => $this->tmp . '/cache']) ?: '{}');
        $cmd = sprintf(
            '%s %s analyze %s --refactor-tests %s --min-impact 0 --max-df 0.5 --workers 1 --config %s --no-cache 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($src),
            escapeshellarg($out),
            escapeshellarg($config),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, 'hostile scan failed:\n' . implode("\n", array_slice($lines, -8)));

        $files = glob($out . '/*.php') ?: [];
        $this->assertNotEmpty($files, 'hostile fixture must emit at least one skeleton (vacuity crux)');

        $payloadSeen = false;
        foreach ($files as $f) {
            $contents = (string)file_get_contents($f);
            if (str_contains($contents, 'touch(')) {
                $payloadSeen = true;
                // The injection verbatim: an array opener handing the
                // payload back as live code. Must not exist.
                $this->assertStringNotContainsString("=> ['' . touch", $contents, "raw sink shape survived in {$f}");
            }
            $lint = shell_exec('php -l ' . escapeshellarg($f) . ' 2>&1');
            $this->assertStringContainsString('No syntax errors', (string)$lint, "unparseable skeleton {$f}");
        }
        $this->assertTrue($payloadSeen, 'fixture no longer drives an expression-shaped observed value — the pin went vacuous');

        // 4. Real collection: build the provider array exactly as PHPUnit
        //    does, with touch() DISABLED — code-shaped data cannot run, and
        //    a resurrected sink fatals the collector.
        $runner = $this->tmp . '/collect.php';
        file_put_contents($runner, implode("\n", [
            '<?php',
            'require $argv[2];',           // vendor autoloader (the skeleton extends TestCase)
            'require $argv[1];',
            'preg_match("/final class (\\w+) extends/", file_get_contents($argv[1]), $m);',
            '$rows = call_user_func("Refactored\\\\Tests\\\\" . $m[1] . "::casesProvider");',
            'foreach ($rows as $row) { foreach ($row as $v) {',
            '    if (is_string($v)) { fwrite(STDOUT, $v . "\n"); }',
            '    elseif ($v !== null && !is_bool($v)) { fwrite(STDOUT, "[data:" . gettype($v) . "]\n"); }',
            '}}',
        ]));
        $scriptOut = [];
        exec(
            sprintf(
                '%s -d disable_functions=touch %s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($runner),
                escapeshellarg($files[0]),
                escapeshellarg($repo . '/vendor/autoload.php'),
            ),
            $scriptOut,
            $collectRc,
        );
        $this->assertSame(0, $collectRc, 'provider collection failed:\n' . implode("\n", $scriptOut));
        $this->assertContains(
            "'' . touch('" . $canary . "') . ''",
            $scriptOut,
            'the payload must round-trip through casesProvider() byte-for-byte as string data',
        );

        // 5. And it never EXECUTED: the canary it names stays absent.
        $this->assertFileDoesNotExist($canary, 'collection executed the payload — the sink is back');
    }

    /** @param int $minFiles the run must at least emit this many skeletons */
    private function census(string $root, int $minFiles): void
    {
        $repo = dirname(__DIR__, 2);
        $outDir = $this->tmp . '/rt_' . str_replace('/', '_', $root);
        mkdir($outDir, 0o775, true);
        $config = $this->tmp . '/phpdup.json';
        file_put_contents($config, json_encode(['cache_dir' => $this->tmp . '/cache']) ?: '{}');

        $cmd = sprintf(
            '%s %s analyze %s --refactor-tests %s --min-impact 1 --workers 1 --config %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($repo . '/bin/phpdup'),
            escapeshellarg($repo . '/' . $root),
            escapeshellarg($outDir),
            escapeshellarg($config),
        );
        exec($cmd, $lines, $rc);
        $this->assertSame(0, $rc, 'scan of ' . $root . " failed:\n" . implode("\n", array_slice($lines, -8)));

        $files = glob($outDir . '/*.php') ?: [];
        $this->assertGreaterThanOrEqual(
            $minFiles,
            count($files),
            'census must exercise the real hole vocabulary, got ' . count($files) . ' skeletons from ' . $root,
        );

        // One shell, every file: exactly the review's find-and-lint census.
        $lintCmd = sprintf(
            'for f in %s/*.php; do php -l "$f" > /dev/null 2>&1 || echo "$f"; done',
            escapeshellarg($outDir),
        );
        $broken = array_values(array_filter(explode("\n", trim((string)shell_exec($lintCmd)))));
        $this->assertSame([], $broken, 'unparseable skeletons in ' . $root . ' census');

        // And the specific defect token must not even appear in a header.
        foreach ($files as $f) {
            $contents = (string)file_get_contents($f);
            if (preg_match('/function testAbstractionMatchesEachMember\(([^)]*)\)/', $contents, $m) === 1) {
                $this->assertStringNotContainsString(
                    '$$',
                    $m[1],
                    "double-sigil header survived in {$f}",
                );
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
