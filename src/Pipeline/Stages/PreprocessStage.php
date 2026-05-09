<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\Block;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PreprocessStage implements StageInterface
{
    private readonly ProgressListener $listener;

    public function __construct(
        private readonly bool $useCache,
        private readonly bool $showStats = false,
        ?ProgressListener $listener = null,
        private readonly int $maxMemoryMb = 0,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    private function checkMemory(OutputInterface $output): void
    {
        if ($this->maxMemoryMb <= 0) {
            return;
        }
        $rssMb = (int)floor(memory_get_peak_usage(true) / (1024 * 1024));
        if ($rssMb > $this->maxMemoryMb) {
            $output->writeln(sprintf(
                "<comment>phpdup: peak RSS %d MB exceeded --max-memory=%d. Consider --exact-only or a larger --min-block-size.</comment>",
                $rssMb, $this->maxMemoryMb,
            ));
        }
    }

    public function name(): Stage
    {
        return Stage::Preprocessing;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        $config = $state->config;
        $files  = $state->files;

        $configKey = sha1(serialize([
            $config->minBlockSize, $config->maxBlockSize,
            $config->normalizationMode, $config->ngramSize,
        ]));
        $store = ($this->useCache && $config->incremental)
            ? new IndexStore($config->cacheDir, $configKey)
            : null;

        $blocks = [];

        // Phase 2a: split files into reuse (cache hit) and process (need work).
        $toProcess = [];
        if ($store !== null) {
            foreach ($files as $f) {
                $cached = $store->load($f);
                if ($cached !== null) {
                    foreach ($cached as $b) {
                        $blocks[] = $b;
                    }
                    $state->reusedFiles++;
                    $this->listener->onFilePreprocessed(
                        $state->processedFiles, $state->reusedFiles, $state->parseErrors,
                    );
                } else {
                    $toProcess[] = $f;
                }
            }
        } else {
            $toProcess = $files;
        }

        // Phase 2b: process the rest, in parallel when possible.
        // Note: the WorkerPool fork model returns one big result array per pool->run(); true
        // generator-based streaming across forks would require restructuring WorkerPool to
        // pipe results back incrementally. For now we drop intermediate arrays as soon as
        // they're flushed to the IndexStore so peak RSS stays bounded by one batch's worth.
        $tPre = microtime(true);
        if ($toProcess) {
            $worker = new PreprocessWorker($config);
            $workerCount = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            $pool = new WorkerPool(workers: $workerCount);
            $task = static fn(array $batch): array => $worker->process($batch);
            $rows = $pool->run($toProcess, $task);
            $perFileBlocks = [];
            $processedFilesSet = [];
            foreach ($rows as $row) {
                $processedFilesSet[$row['file']] = true;
                if ($row['type'] === 'error') {
                    $state->parseErrors++;
                    continue;
                }
                if ($row['type'] === 'block') {
                    /** @var Block $b */
                    $b = $row['block'];
                    $perFileBlocks[$row['file']][] = $b;
                    $blocks[] = $b;
                }
            }
            $state->processedFiles = count($processedFilesSet);
            unset($rows, $processedFilesSet);
            if ($store !== null) {
                foreach ($perFileBlocks as $file => $list) {
                    $store->save($file, $list);
                }
            }
            unset($perFileBlocks);
            $this->listener->onFilePreprocessed(
                $state->processedFiles, $state->reusedFiles, $state->parseErrors,
            );
        }

        // Assign IDs (after collecting from all sources).
        foreach ($blocks as $i => $b) {
            $b->id = substr($b->structuralHash, 0, 8) . '_' . $i;
        }

        $state->blocks = $blocks;
        $state->timings['preprocess'] = microtime(true) - $tPre;

        $output->writeln(sprintf(
            "<info>phpdup</info> %d files (%d reused · %d processed) → %d blocks · %d parse errors",
            count($files), $state->reusedFiles, $state->processedFiles, count($blocks), $state->parseErrors,
        ));

        if ($this->showStats) {
            $this->printStats($output, $blocks);
        }

        $this->checkMemory($output);
    }

    /** @param list<Block> $blocks */
    private function printStats(OutputInterface $out, array $blocks): void
    {
        $kinds = [];
        $sizes = [];
        foreach ($blocks as $b) {
            $kinds[$b->kind] = ($kinds[$b->kind] ?? 0) + 1;
            $sizes[] = $b->size;
        }
        ksort($kinds);
        $out->writeln('  block kinds:');
        foreach ($kinds as $k => $n) {
            $out->writeln(sprintf('    %-10s %d', $k, $n));
        }
        if ($sizes) {
            sort($sizes);
            $p = fn(float $q) => $sizes[(int)floor($q * (count($sizes) - 1))];
            $out->writeln(sprintf(
                '  size: min=%d p50=%d p90=%d p99=%d max=%d',
                $sizes[0], $p(0.5), $p(0.9), $p(0.99), end($sizes)
            ));
        }
    }
}
