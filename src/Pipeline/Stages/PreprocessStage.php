<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Extraction\Block;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Util\MemoryDebug;
use Symfony\Component\Console\Output\OutputInterface;

final class PreprocessStage implements CooperativeStageInterface
{
    /** Yield to the runtime every N records streamed from workers. */
    private const YIELD_EVERY = 32;

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
        $rssMb = (int)floor(memory_get_peak_usage(true) / (1024 * 1024));
        // Debug: always emit memory RSS when verbosity is DEBUG.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $output->writeln(sprintf('<debug>phpdup</debug> preprocess check: %s</debug>', MemoryDebug::getMemoryUsage()));
        }
        if ($this->maxMemoryMb <= 0) {
            return;
        }
        if ($rssMb > $this->maxMemoryMb && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
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
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    /**
     * Streaming preprocessing pipeline.
     *
     * Blocks are accumulated in a single $blocks array throughout the run.
     * At cache-save time a per-file map is built from $blocks in one O(n) pass
     * (no separate per-file accumulator), keeping peak block memory at ~1x.
     *
     * @return \Generator<Stage>
     */
    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        $config = $state->config;
        $files  = $state->files;

        $configKey = sha1(serialize([
            $config->minBlockSize, $config->maxBlockSize,
            $config->normalizationMode, $config->ngramSize,
            // Include dbAware + trinityCollapse + the custom DB
            // symbol maps so caches are invalidated when the user
            // toggles flags or changes the symbol-equivalence
            // registry between runs (the canonical AST is
            // structurally different in each case).
            $config->dbAware,
            $config->trinityCollapse,
            $config->dbSymbolsMethods,
            $config->dbSymbolsFunctions,
            // Scorer mode controls whether IR token bags are
            // pre-computed during preprocessing; flipping it must
            // invalidate so the cached Block records carry (or omit)
            // their irBag consistently with the live config.
            $config->scorer,
        ]));
        $store = ($this->useCache && $config->incremental)
            ? new IndexStore($config->cacheDir, $configKey)
            : null;

        $blocks = [];

        // Phase 2a: split files into reuse (cache hit) and process (need work).
        // Yield every YIELD_EVERY cache lookups so the renderer can repaint while
        // we walk a large file list.
        $toProcess  = [];
        $sinceYield = 0;
        if ($store !== null) {
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $msg = sprintf('preprocess: checking cache for %d files [%s]', count($files), MemoryDebug::getMemoryUsage());
                $output->writeln($msg);
                $state->pushDebugMessage($msg);
            }
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
                if (++$sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                    $state->rssBytes = memory_get_usage(false);
                    $state->peakBytes = memory_get_peak_usage(true);
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                        $msg = sprintf('preprocess: cache check progress %d/%d files [%s]', $state->scannedFiles, count($files), MemoryDebug::getMemoryUsage());
                        $output->writeln($msg);
                        $state->pushDebugMessage($msg);
                    }
                    yield Stage::Preprocessing;
                }
            }
        } else {
            $toProcess = $files;
        }

        // Phase 2b: process the rest. Streaming WorkerPool yields records as
        // each child produces them; we yield up to the runtime every
        // YIELD_EVERY records so the TUI can repaint mid-stage instead of
        // freezing for the duration of the parallel work.
        $tPre = microtime(true);
        if ($toProcess) {
            $worker = new PreprocessWorker($config);
            $workerCount = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            $pool = new WorkerPool(workers: $workerCount);
            $task = static function (array $batch) use ($worker, $output): array {
                return $worker->process($batch, $output);
            };

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $msg = sprintf('preprocess: processing %d files across %d workers [%s]', count($toProcess), $workerCount, MemoryDebug::getMemoryUsage());
                $output->writeln($msg);
                $state->pushDebugMessage($msg);
            }

            $processedFilesSet = [];
            $sinceYield        = 0;

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                $msg = sprintf('preprocess: collecting results from workers... [%s]', MemoryDebug::getMemoryUsage());
                $output->writeln($msg);
                $state->pushDebugMessage($msg);
            }
            foreach ($pool->runStreaming($toProcess, $task) as $row) {
                $processedFilesSet[$row['file']] = true;
                if ($row['type'] === 'error') {
                    $state->parseErrors++;
                } elseif ($row['type'] === 'block') {
                    /** @var Block $b */
                    $b = $row['block'];
                    $blocks[] = $b;
                }
                $state->processedFiles = count($processedFilesSet);

                if (++$sinceYield >= self::YIELD_EVERY) {
                    $sinceYield = 0;
                    $state->rssBytes = memory_get_usage(false);
                    $state->peakBytes = memory_get_peak_usage(true);
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                        $msg = sprintf('preprocess: processed %d files, %d blocks so far [%s]', $state->processedFiles, count($blocks), MemoryDebug::getMemoryUsage());
                        $output->writeln($msg);
                        $state->pushDebugMessage($msg);
                    }
                    $this->listener->onFilePreprocessed(
                        $state->processedFiles, $state->reusedFiles, $state->parseErrors,
                    );
                    yield Stage::Preprocessing;
                }
            }
            unset($processedFilesSet);

            if ($store !== null) {
                $byFile = [];
                foreach ($blocks as $b) {
                    $byFile[$b->file][] = $b;
                }
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $msg = sprintf('preprocess: saving %d files to cache... [%s]', count($byFile), MemoryDebug::getMemoryUsage());
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                foreach ($byFile as $file => $list) {
                    $store->save($file, $list);
                }
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $msg = sprintf('preprocess: cache save complete [%s]', MemoryDebug::getMemoryUsage());
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
            }
            $this->listener->onFilePreprocessed(
                $state->processedFiles, $state->reusedFiles, $state->parseErrors,
            );
        }

        // Assign IDs (after collecting from all sources).
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $msg = sprintf('preprocess: assigning IDs to %d blocks... [%s]', count($blocks), MemoryDebug::getMemoryUsage());
            $output->writeln($msg);
            $state->pushDebugMessage($msg);
        }
        foreach ($blocks as $i => $b) {
            $b->id = substr($b->structuralHash, 0, 8) . '_' . $i;
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $msg = sprintf('preprocess: ID assignment complete, transitioning to next stage... [%s]', MemoryDebug::getMemoryUsage());
            $output->writeln($msg);
            $state->pushDebugMessage($msg);
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
            $p = fn(float $q): int => $sizes[(int)floor($q * (count($sizes) - 1))];
            $out->writeln(sprintf(
                '  size: min=%d p50=%d p90=%d p99=%d max=%d',
                $sizes[0], $p(0.5), $p(0.9), $p(0.99), end($sizes)
            ));
        }
    }
}
