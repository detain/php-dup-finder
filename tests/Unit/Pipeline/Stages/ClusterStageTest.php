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
     * "2 files total". Both legs run the real expandToCloneCohort path on
     * an identical-code fixture, separated only by --max-df arming.
     */
    public function testCohortHeadlineSingularizesAndStaysPlural(): void
    {
        $dir = $this->createTwinCloneFixture();
        try {
            $plural = $this->cohortRender($dir, maxDocumentFrequency: 1.0);
            $this->assertStringContainsString(
                'clone cohort: 1 changed + 1 related = 2 files total',
                $plural,
            );

            // Default max_df (0.01) caps postings at 1 block — the twin
            // posting is "too common to be informative", so nothing is
            // seeded as related and the cohort collapses to the lone
            // changed file.
            $singular = $this->cohortRender($dir, maxDocumentFrequency: 0.01);
            $this->assertStringContainsString(
                'clone cohort: 1 changed + 0 related = 1 file total',
                $singular,
            );
            $this->assertStringNotContainsString('1 files total', $singular);
        } finally {
            $this->removeDirectory($dir);
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
