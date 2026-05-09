<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Index\BlockIndex;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Persistence\IndexStore;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Scanning\FileScanner;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Command extends SymfonyCommand
{
    protected function configure(): void
    {
        $this->setName('analyze')
            ->setDescription('Recursively scan PHP source roots for duplicated logic.')
            ->addArgument('paths', InputArgument::IS_ARRAY, 'One or more paths to scan', [])
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to phpdup.json config file')
            ->addOption('min-block-size', null, InputOption::VALUE_REQUIRED, 'Minimum AST node count for a block')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Normalization mode: strict|default|aggressive')
            ->addOption('similarity', null, InputOption::VALUE_REQUIRED, 'Jaccard similarity threshold (0..1)')
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Minimum cluster impact to report')
            ->addOption('html', null, InputOption::VALUE_REQUIRED, 'Write HTML report to this directory')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Write JSON report to this file')
            ->addOption('exact-only', null, InputOption::VALUE_NONE, 'Skip near-duplicate detection (faster)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Show at most N clusters in CLI output', 50)
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show pipeline statistics')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable AST cache for this run')
            ->addOption('workers', 'j', InputOption::VALUE_REQUIRED, 'Worker count for parallel preprocess + pair scoring (0 = auto, 1 = serial)')
            ->addOption('no-incremental', null, InputOption::VALUE_NONE, 'Disable per-file index reuse')
            ->addOption('no-lazy-ast', null, InputOption::VALUE_NONE, 'Keep all original ASTs in memory (higher RSS, faster anti-unification)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $input->getArgument('paths');
        if (!$paths) {
            $output->writeln('<error>phpdup: at least one path is required</error>');
            return 2;
        }

        $overrides = array_filter([
            'min_block_size'       => $input->getOption('min-block-size'),
            'normalization_mode'   => $input->getOption('mode'),
            'similarity_threshold' => $input->getOption('similarity'),
            'min_cluster_impact'   => $input->getOption('min-impact'),
            'html'                 => $input->getOption('html'),
            'json'                 => $input->getOption('json'),
            'workers'              => $input->getOption('workers'),
        ], fn($v) => $v !== null);
        if ($input->getOption('no-incremental')) $overrides['incremental'] = false;
        if ($input->getOption('no-lazy-ast'))    $overrides['lazy_ast']   = false;

        $config = (new ConfigLoader())->load(
            paths: $paths,
            configFile: $input->getOption('config'),
            overrides: $overrides,
        );

        $useCache  = !$input->getOption('no-cache');
        $exactOnly = (bool)$input->getOption('exact-only');
        $showStats = (bool)$input->getOption('stats');
        $limit     = (int)$input->getOption('limit');

        $scanner = new FileScanner($config->exclude);

        $files = [];
        foreach ($config->paths as $root) {
            foreach ($scanner->scan($root) as $path) {
                $files[] = $path;
            }
        }
        sort($files);

        $output->writeln(sprintf(
            "<info>phpdup</info> scanning %d path(s) → %d files",
            count($config->paths), count($files)
        ));

        $timings = ['preprocess' => 0.0, 'cluster' => 0.0, 'refactor' => 0.0];
        $blocks = [];
        $parseErrors = 0;
        $reusedFiles = 0;
        $processedFiles = 0;

        $configKey = sha1(serialize([
            $config->minBlockSize, $config->maxBlockSize,
            $config->normalizationMode, $config->ngramSize,
        ]));
        $store = ($useCache && $config->incremental) ? new IndexStore($config->cacheDir, $configKey) : null;

        // Phase 1: split files into "reuse" (incremental index hit) and "process" (need work).
        $toProcess = [];
        if ($store !== null) {
            foreach ($files as $f) {
                $cached = $store->load($f);
                if ($cached !== null) {
                    foreach ($cached as $b) {
                        $blocks[] = $b;
                    }
                    $reusedFiles++;
                } else {
                    $toProcess[] = $f;
                }
            }
        } else {
            $toProcess = $files;
        }

        // Phase 2: process the rest, in parallel when possible.
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
                    $parseErrors++;
                    continue;
                }
                if ($row['type'] === 'block') {
                    /** @var Block $b */
                    $b = $row['block'];
                    $perFileBlocks[$row['file']][] = $b;
                    $blocks[] = $b;
                }
            }
            $processedFiles = count(array_unique(array_column($rows, 'file')));
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
        $timings['preprocess'] = microtime(true) - $tPre;

        $output->writeln(sprintf(
            "<info>phpdup</info> %d files (%d reused · %d processed) → %d blocks · %d parse errors",
            count($files), $reusedFiles, $processedFiles, count($blocks), $parseErrors,
        ));

        if ($showStats) {
            $this->printStats($output, $blocks);
        }

        if (!$blocks) {
            return 0;
        }

        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }

        // Optional: drop original ASTs to free memory; reload lazily during refactor.
        if ($config->lazyAst) {
            foreach ($blocks as $b) {
                $b->unloadAst();
            }
        }

        // Phase 3: cluster.
        $tCluster = microtime(true);
        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            maxDocumentFrequency: $config->maxDocumentFrequency,
            exactOnly: $exactOnly,
        );

        $edges = null;
        if (!$exactOnly) {
            $candidatePairs = $clusterer->generateCandidatePairs($index);
            $workers = $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount();
            if (count($candidatePairs) >= 64 && $workers > 1) {
                $scoreWorker = new PairScoreWorker(
                    $index, $config->similarityThreshold, $config->treeThreshold,
                );
                $pool = new WorkerPool(workers: $workers);
                $task = static fn(array $pairs): array => $scoreWorker->score($pairs);
                $edges = $pool->run($candidatePairs, $task);
            }
        }
        $clusters = $clusterer->cluster($index, $edges);
        $timings['cluster'] = microtime(true) - $tCluster;

        // Phase 4: refactor synthesis (deserialised blocks may have unloaded ASTs).
        $tRefactor = microtime(true);
        $loader = $config->lazyAst
            ? new BlockAstLoader(new AstCache($useCache ? $config->cacheDir : ''), new AstParser())
            : null;
        $antiUnifier = new AntiUnifier($loader);
        $synth = new ParameterSynthesizer();
        $sigBuilder = new SignatureBuilder();
        $patterns = new PatternRecognizer();
        foreach ($clusters as $cluster) {
            $antiUnifier->unify($cluster);
            $synth->synthesize($cluster);
            $sigBuilder->buildSignature($cluster);
            $patterns->tag($cluster);
        }
        $timings['refactor'] = microtime(true) - $tRefactor;

        if ($showStats) {
            $output->writeln('  timings (s):');
            foreach ($timings as $k => $v) {
                $output->writeln(sprintf('    %-12s %6.2f', $k, $v));
            }
            $output->writeln(sprintf(
                '  workers: %d (pcntl %s)',
                $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount(),
                WorkerPool::isAvailable() ? 'available' : 'unavailable',
            ));
        }

        $clusters = (new Ranker($config->minClusterImpact))->rank($clusters);

        $report = new Report(
            files: count($files),
            blocks: count($blocks),
            parseErrors: $parseErrors,
            clusters: $clusters,
            config: $config,
        );

        (new CliReporter())->render($report, $output, $limit);

        if ($config->jsonReportFile !== null) {
            (new JsonReporter())->writeTo($report, $config->jsonReportFile);
            $output->writeln("<info>phpdup</info> json report → {$config->jsonReportFile}");
        }
        if ($config->htmlReportDir !== null) {
            (new HtmlReporter())->writeTo($report, $config->htmlReportDir);
            $output->writeln("<info>phpdup</info> html report → {$config->htmlReportDir}/index.html");
        }
        return 0;
    }

    /** @param list<\Phpdup\Extraction\Block> $blocks */
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
