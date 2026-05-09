<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\Block;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PreprocessStage implements StageInterface
{
    public function __construct(
        private readonly bool $useCache,
        private readonly bool $showStats = false,
    ) {}

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
                } else {
                    $toProcess[] = $f;
                }
            }
        } else {
            $toProcess = $files;
        }

        // Phase 2b: process the rest, in parallel when possible.
        $tPre = microtime(true);
        if ($toProcess) {
            $worker = new PreprocessWorker($config);
            $workerCount = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            $pool = new WorkerPool(workers: $workerCount);
            $task = static fn(array $batch): array => $worker->process($batch);
            $rows = $pool->run($toProcess, $task);
            $perFileBlocks = [];
            foreach ($rows as $row) {
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
            $state->processedFiles = count(array_unique(array_column($rows, 'file')));
            if ($store !== null) {
                foreach ($perFileBlocks as $file => $list) {
                    $store->save($file, $list);
                }
            }
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
