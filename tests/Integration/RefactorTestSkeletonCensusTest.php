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
