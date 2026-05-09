<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
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
            ->addOption('no-lazy-ast', null, InputOption::VALUE_NONE, 'Keep all original ASTs in memory (higher RSS, faster anti-unification)')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Run pipeline only up to STAGE (scanning|preprocessing|clustering|refactoring|reporting); useful for incremental debugging')
            ->addOption('validate-config', null, InputOption::VALUE_NONE, 'Validate the --config file against the documented schema and exit (no analysis is run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('validate-config')) {
            return $this->validateConfigOnly($input, $output);
        }

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

        $stopAfter = null;
        $stageOpt  = $input->getOption('stage');
        if ($stageOpt !== null) {
            $stopAfter = Stage::tryFrom(strtolower((string)$stageOpt));
            if ($stopAfter === null) {
                $allowed = implode('|', array_map(fn(Stage $s) => $s->value, Stage::ordered()));
                $output->writeln("<error>phpdup: --stage must be one of {$allowed}</error>");
                return 2;
            }
        }

        $pipeline = new Pipeline(
            stages: [
                new ScanningStage(),
                new PreprocessStage($useCache, $showStats),
                new ClusterStage($exactOnly),
                new RefactorStage($useCache),
                new ReportStage($limit, $showStats),
            ],
            stopAfter: $stopAfter,
        );

        $pipeline->run(new PipelineState($config), $output);

        return 0;
    }

    private function validateConfigOnly(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $input->getOption('config');
        if ($configFile === null) {
            $output->writeln('<error>phpdup: --validate-config requires --config=FILE</error>');
            return 2;
        }
        if (!is_file($configFile)) {
            $output->writeln("<error>phpdup: config file not found: {$configFile}</error>");
            return 2;
        }
        $decoded = json_decode((string)file_get_contents($configFile), true);
        if (!is_array($decoded)) {
            $output->writeln("<error>phpdup: config file is not valid JSON: {$configFile}</error>");
            return 2;
        }

        try {
            (new ConfigLoader())->validate($decoded, $configFile);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
            return 2;
        }

        $output->writeln("<info>phpdup</info> config OK: {$configFile}");
        return 0;
    }
}
