<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Output\NullOutput;

final class PreprocessStageTest extends TestCase
{
    public function testPerFileBlocksNotInSource(): void
    {
        $result = shell_exec('grep -r \'perFileBlocks\' ' . escapeshellarg(__DIR__ . '/../../../src') . ' 2>/dev/null');
        $this->assertSame('', (string)$result, 'perFileBlocks should not exist in src/ - M3 optimization removed the double-copy accumulator');
    }

    public function testProducesBlocksFromFixtureFiles(): void
    {
        $state = $this->scannedState(__DIR__ . '/../../../Fixtures/sql');

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertNotEmpty($state->blocks, 'sql fixture should produce duplicate-eligible blocks');
        foreach ($state->blocks as $b) {
            $this->assertNotSame('', $b->id, 'each block must be assigned an id');
        }
        $this->assertGreaterThan(0.0, $state->timings['preprocess']);
        $this->assertSame(0, $state->parseErrors);
    }

    public function testEmptyFileListIsNoOp(): void
    {
        $state = new PipelineState(Config::defaults(['/nonexistent/path/that/does/not/exist']));
        $state->files = [];

        (new PreprocessStage(useCache: false))->run($state, new NullOutput());

        $this->assertSame([], $state->blocks);
        $this->assertSame(0, $state->parseErrors);
        $this->assertSame(0, $state->processedFiles);
    }

    public function testCacheAndNoCacheProduceSameBlocks(): void
    {
        $tmpDir = $this->createTempFixture();
        try {
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                incremental: true,
                cacheDir: $tmpDir . '/cache',
            );
            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());

            (new PreprocessStage(useCache: false))->run($state, new NullOutput());
            $withoutCache = $state->blocks;

            $state2 = new PipelineState($config);
            (new ScanningStage())->run($state2, new NullOutput());

            (new PreprocessStage(useCache: true))->run($state2, new NullOutput());
            $withCache = $state2->blocks;

            $this->assertCount(count($withoutCache), $withCache, 'block count must match');
            foreach ($withoutCache as $i => $b) {
                $this->assertSame($b->structuralHash, $withCache[$i]->structuralHash, "block $i structuralHash must match");
                $this->assertSame($b->file, $withCache[$i]->file, "block $i file must match");
                $this->assertSame($b->kind, $withCache[$i]->kind, "block $i kind must match");
            }
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    public function testCacheReuseSetsReusedFilesCount(): void
    {
        $tmpDir = $this->createTempFixture();
        try {
            $config = new Config(
                paths: [$tmpDir],
                exclude: [],
                minBlockSize: 1,
                incremental: true,
                cacheDir: $tmpDir . '/cache',
            );

            $state = new PipelineState($config);
            (new ScanningStage())->run($state, new NullOutput());
            (new PreprocessStage(useCache: true))->run($state, new NullOutput());
            $this->assertSame(0, $state->reusedFiles, 'first run: nothing to reuse');

            $state2 = new PipelineState($config);
            (new ScanningStage())->run($state2, new NullOutput());
            (new PreprocessStage(useCache: true))->run($state2, new NullOutput());
            $this->assertGreaterThan(0, $state2->reusedFiles, 'second run: files must be reused from cache');
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    private function createTempFixture(): string
    {
        $tmpDir = sys_get_temp_dir() . '/phpdup_test_' . uniqid();
        @mkdir($tmpDir . '/cache', 0o775, true);
        file_put_contents($tmpDir . '/a.php', '<?php
function foo() { return 1; }
function bar() { return 2; }
');
        file_put_contents($tmpDir . '/b.php', '<?php
function baz() { return 3; }
function qux() { return 4; }
');
        return $tmpDir;
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

    public function testReportsName(): void
    {
        $this->assertSame(
            Stage::Preprocessing,
            (new PreprocessStage(useCache: false))->name(),
        );
    }

    private function scannedState(string $path): PipelineState
    {
        $state = new PipelineState(Config::defaults([$path]));
        (new ScanningStage())->run($state, new NullOutput());
        return $state;
    }
}
