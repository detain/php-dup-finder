<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

final class ClusterStageTest extends TestCase
{
    public function testEmptyBlocksIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__]));

        (new ClusterStage(exactOnly: false))->run($state, new NullOutput());

        $this->assertSame([], $state->clusters);
        $this->assertNull($state->index);
    }

    public function testProducesClustersFromFixtureBlocks(): void
    {
        $state = new PipelineState(Config::defaults([__DIR__ . '/../../../Fixtures/sql']));
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        (new ClusterStage(exactOnly: true))->run($state, new NullOutput());

        $this->assertNotNull($state->index, 'BlockIndex should be populated');
        $this->assertNotEmpty(
            $state->clusters,
            'sql fixture has identical findById methods that should cluster',
        );
        $this->assertGreaterThanOrEqual(0.0, $state->timings['cluster']);
    }

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Clustering,
            (new ClusterStage(exactOnly: false))->name(),
        );
    }

    /**
     * ui-grammar: the cohort headline totals via the shared phrase —
     * a single-file cohort reads "1 file total", a two-file cohort keeps
     * "2 files total". All legs run the real expandToCloneCohort path.
     *
     * Post engine-001 (df cap floors at 2) the DEFAULT max_df arms the
     * twin leg too: a gram shared by exactly two blocks is at the cap,
     * not above it, so the cohort no longer collapses at 0.01. The
     * singular leg therefore moves to a corpus that genuinely has no
     * clone twin — the only honest way to get "0 related".
     */
    public function testCohortHeadlineSingularizesAndStaysPlural(): void
    {
        $dir = $this->createTwinCloneFixture();
        $solo = $this->createSoleFileFixture();
        try {
            $armed = $this->cohortRender($dir, maxDocumentFrequency: 1.0);
            $this->assertStringContainsString(
                'clone cohort: 1 changed + 1 related = 2 files total',
                $armed,
            );

            // engine-001 fix pin: at the shipped default (0.01) the pair
            // posting (df = 2) sits exactly at the floored cap and seeds
            // the related file. Pre-fix the cap floored at 1 and this
            // headline read "1 changed + 0 related = 1 file total".
            $default = $this->cohortRender($dir, maxDocumentFrequency: 0.01);
            $this->assertStringContainsString(
                'clone cohort: 1 changed + 1 related = 2 files total',
                $default,
            );

            // Singular grammar: a changed file whose code exists nowhere
            // else shares no gram with any other block, so the cohort is
            // the lone changed file at any df.
            $singular = $this->cohortRender($solo, maxDocumentFrequency: 0.01);
            $this->assertStringContainsString(
                'clone cohort: 1 changed + 0 related = 1 file total',
                $singular,
            );
            $this->assertStringNotContainsString('1 files total', $singular);
        } finally {
            $this->removeDirectory($dir);
            $this->removeDirectory($solo);
        }
    }

    private function cohortRender(string $dir, float $maxDocumentFrequency): string
    {
        $config = new Config(
            paths: [$dir],
            exclude: [],
            minBlockSize: 1,
            maxDocumentFrequency: $maxDocumentFrequency,
            cacheDir: $dir . '/cache',
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $changed = null;
        foreach ($state->files as $f) {
            if (str_ends_with($f, '/a.php')) {
                $changed = $f;
            }
        }
        $this->assertNotNull($changed, 'fixture must scan a.php');
        $state->diffBaseFiles = [$changed];

        $output = new BufferedOutput();
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $output);

        return $output->fetch();
    }

    /** Two files holding one identical clone-sized function each. */
    private function createTwinCloneFixture(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup_cohort_' . uniqid();
        @mkdir($dir . '/cache', 0o775, true);
        $body = '<?php
function handle($x)
{
    if ($x > 0) {
        return $x + 1;
    }
    if ($x < 0) {
        return $x - 1;
    }
    return $x;
}
';
        file_put_contents($dir . '/a.php', $body);
        file_put_contents($dir . '/b.php', $body);
        return $dir;
    }

    /** One file holding the clone-sized function; no twin anywhere in the corpus. */
    private function createSoleFileFixture(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup_cohort_solo_' . uniqid();
        @mkdir($dir . '/cache', 0o775, true);
        file_put_contents($dir . '/a.php', '<?php
function handle($x)
{
    if ($x > 0) {
        return $x + 1;
    }
    if ($x < 0) {
        return $x - 1;
    }
    return $x;
}
');
        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------
    // shell-S30 (findings shell-018 / shell-019): cluster-cache scope law
    // ------------------------------------------------------------------

    /**
     * shell-018: the cluster cache is a FULL-corpus snapshot keyed on the
     * pre-expansion block set, so a --diff-base run used to (a) HIT the
     * warm full snapshot and replay clusters from files outside the
     * changed cohort, and (b) on a miss, SAVE its post-expansion tokens
     * into the shared snapshot — poisoning a key no full run reproduces.
     * Pinned posture: under --diff-base the cluster cache is not
     * consulted at all — not for load, not for save.
     */
    public function testDiffBaseRunNeitherServedNorPoisonsTheClusterCache(): void
    {
        $dir = $this->createCohortProbeFixture();
        $cache = $dir . '/warm';
        @mkdir($cache, 0o775, true);
        try {
            $changed = $this->fixtureFile($dir, 'a.php');

            // Leg 1 — warm a full-corpus snapshot (no --diff-base).
            [$cold, ] = $this->clusterRun($dir, $cache, diffBaseFiles: null);
            $this->assertStringNotContainsString('reused', $cold, 'first full run must be cold');
            $file = $cache . '/clusters.idx';
            $this->assertFileExists($file, 'precondition: the full run saves its snapshot');
            $snapshot = (string) file_get_contents($file);

            // Leg 2 — the served face. Pre-fix the load at iter()'s top ran
            // on the FULL scanned set (--diff-base truncates nothing before
            // this stage), matched the leg-1 snapshot, and returned
            // out-of-scope clusters. Post-fix: plain recompute inside the
            // cohort, no replay, snapshot bytes untouched.
            [$diff, $diffState] = $this->clusterRun($dir, $cache, diffBaseFiles: [$changed]);
            $this->assertStringNotContainsString(
                'reused', $diff,
                '--diff-base must never be served the full-corpus cluster cache',
            );
            $this->assertFalse(
                $diffState->clustersReplayed,
                'the replayed-positions flag must stay unset under --diff-base',
            );
            $this->assertStringContainsString('clone cohort:', $diff,
                'precondition: the leg really ran the diff-base cohort path');
            $this->assertSame($snapshot, (string) file_get_contents($file),
                '--diff-base must leave the full-run snapshot byte-intact');

            // Leg 3 — the poison face, cold: a --diff-base run with nothing
            // cached must write NOTHING, so post-expansion keys can never
            // enter the shared snapshot at all.
            $cold2 = $dir . '/cold';
            @mkdir($cold2, 0o775, true);
            $this->clusterRun($dir, $cold2, diffBaseFiles: [$changed]);
            $this->assertFileDoesNotExist($cold2 . '/clusters.idx',
                'a --diff-base miss must not save into the cluster cache');

            // Leg 4 — consequence: the full-run key survived the whole
            // --diff-base detour and still replays.
            [$warm, ] = $this->clusterRun($dir, $cache, diffBaseFiles: null);
            $this->assertStringContainsString('reused', $warm,
                'the full-corpus snapshot must survive --diff-base runs untouched');
        } finally {
            $this->removeDirectory($dir);
        }
    }

    /**
     * shell-019 end-to-end: --scorer=ir swaps the pair-verdict engine
     * (buildClusterer/PairScoreWorker consume it), so a snapshot warmed
     * under the IR tier must replay as a MISS into a default-tier run —
     * the exact misfeed the finding named. Key-level binding is pinned in
     * ClusterCacheVersionTest; this pins the live serve path.
     */
    public function testScorerTierFlipReplaysTheClusterCacheAsMiss(): void
    {
        $dir = $this->createCohortProbeFixture();
        $cache = $dir . '/tier';
        @mkdir($cache, 0o775, true);
        try {
            [$warm, ] = $this->clusterRun($dir, $cache, diffBaseFiles: null, scorer: 'ir');
            $this->assertStringNotContainsString('reused', $warm, 'IR-tier warm-up must be cold');

            [$same, ] = $this->clusterRun($dir, $cache, diffBaseFiles: null, scorer: 'ir');
            $this->assertStringContainsString('reused', $same,
                'non-vacuity: the same tier must replay from cache');

            [$cross, ] = $this->clusterRun($dir, $cache, diffBaseFiles: null, scorer: 'default');
            $this->assertStringNotContainsString('reused', $cross,
                'an IR-tier snapshot must never serve a default-tier run');
        } finally {
            $this->removeDirectory($dir);
        }
    }

    /**
     * @return array{0: string, 1: PipelineState} run output + terminal state
     */
    private function clusterRun(string $dir, string $cacheDir, ?array $diffBaseFiles, string $scorer = 'default'): array
    {
        $config = new Config(
            paths: [$dir],
            exclude: [],
            minBlockSize: 1,
            cacheDir: $cacheDir,
            scorer: $scorer,
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        $state->diffBaseFiles = $diffBaseFiles;

        $output = new BufferedOutput();
        (new ClusterStage(exactOnly: false))->run($state, $output);
        return [$output->fetch(), $state];
    }

    /** Resolve a fixture file the way ScanningStage records it, for diffBaseFiles. */
    private function fixtureFile(string $dir, string $name): string
    {
        foreach ((array) glob($dir . '/' . $name) as $f) {
            return (string) $f;
        }
        $this->fail("fixture is missing {$name}");
    }

    /** a.php/b.php hold one identical clone; c.php shares no gram with them. */
    private function createCohortProbeFixture(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup_ccohort_' . uniqid();
        @mkdir($dir, 0o775, true);
        $body = '<?php
function handle($x)
{
    if ($x > 0) {
        return $x + 1;
    }
    if ($x < 0) {
        return $x - 1;
    }
    return $x;
}
';
        file_put_contents($dir . '/a.php', $body);
        file_put_contents($dir . '/b.php', $body);
        file_put_contents($dir . '/c.php', '<?php
function describe($items)
{
    $out = [];
    foreach ($items as $k => $v) {
        $out[] = $k . \'=\' . $v;
    }
    return join(\',\', $out);
}
');
        return $dir;
    }
}
