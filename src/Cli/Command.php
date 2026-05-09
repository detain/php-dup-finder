<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\NgramInvertedIndex;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
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
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable AST cache for this run');
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
        ], fn($v) => $v !== null);

        $config = (new ConfigLoader())->load(
            paths: $paths,
            configFile: $input->getOption('config'),
            overrides: $overrides,
        );

        $useCache = !$input->getOption('no-cache');
        $exactOnly = (bool)$input->getOption('exact-only');
        $showStats = (bool)$input->getOption('stats');
        $limit = (int)$input->getOption('limit');

        $scanner = new FileScanner($config->exclude);
        $cache = $useCache ? new AstCache($config->cacheDir) : new AstCache('');
        $parser = new AstParser();
        $extractor = new BlockExtractor($config->minBlockSize, $config->maxBlockSize);
        $normalizer = new Normalizer($config->normalizationMode);
        $hasher = new SubtreeHasher();
        $fingerprinter = new NgramFingerprint($config->ngramSize);

        $files = 0;
        $parseErrors = 0;
        $blocks = [];
        $timings = ['parse' => 0.0, 'normalize' => 0.0, 'fingerprint' => 0.0, 'cluster' => 0.0, 'refactor' => 0.0];

        $output->writeln("<info>phpdup</info> scanning " . count($config->paths) . " path(s)...");

        foreach ($config->paths as $root) {
            foreach ($scanner->scan($root) as $path) {
                $files++;
                $tParse = microtime(true);
                $stmts = $cache->get($path);
                if ($stmts === null) {
                    $stmts = $parser->parseFile($path);
                    if ($stmts === null) {
                        $parseErrors++;
                        continue;
                    }
                    $cache->put($path, $stmts);
                }
                $timings['parse'] += microtime(true) - $tParse;
                foreach ($extractor->extract($path, $stmts) as $block) {
                    $tNorm = microtime(true);
                    $normalizer->normalize($block);
                    $timings['normalize'] += microtime(true) - $tNorm;
                    $tFp = microtime(true);
                    $block->structuralHash = $hasher->hash($block->canonical);
                    $block->ngramBag = $fingerprinter->fingerprint($block->canonical);
                    $timings['fingerprint'] += microtime(true) - $tFp;
                    $block->id = substr($block->structuralHash, 0, 8) . '_' . count($blocks);
                    $blocks[] = $block;
                }
            }
        }

        $output->writeln(sprintf(
            "<info>phpdup</info> scanned %d files, %d blocks (%d parse errors)",
            $files, count($blocks), $parseErrors,
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

        $tCluster = microtime(true);
        $clusterer = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            maxDocumentFrequency: $config->maxDocumentFrequency,
            exactOnly: $exactOnly,
        );
        $clusters = $clusterer->cluster($index);
        $timings['cluster'] = microtime(true) - $tCluster;

        $tRefactor = microtime(true);
        $antiUnifier = new AntiUnifier();
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
            $out = $output;
            $out->writeln('  timings (s):');
            foreach ($timings as $k => $v) {
                $out->writeln(sprintf('    %-12s %6.2f', $k, $v));
            }
        }

        $ranker = new Ranker($config->minClusterImpact);
        $clusters = $ranker->rank($clusters);

        $report = new Report(
            files: $files,
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
