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
}
