<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use Phpdup\Watch\WatchRunner;
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
            ->addOption('validate-config', null, InputOption::VALUE_NONE, 'Validate the --config file against the documented schema and exit (no analysis is run)')
            ->addOption('sarif', null, InputOption::VALUE_REQUIRED, 'Write SARIF 2.1.0 report to FILE (for GitHub/GitLab PR annotations)')
            ->addOption('gitlab-sast', null, InputOption::VALUE_REQUIRED, 'Write GitLab SAST report (v15) to FILE')
            ->addOption('diff', null, InputOption::VALUE_REQUIRED, 'Write per-cluster unified diffs into DIR')
            ->addOption('patch', null, InputOption::VALUE_REQUIRED, 'Write a single cumulative patch file containing every cluster diff')
            ->addOption('checkstyle', null, InputOption::VALUE_REQUIRED, 'Write Checkstyle XML report to FILE')
            ->addOption('kinds', null, InputOption::VALUE_REQUIRED, 'Comma-separated block kinds to include (e.g. method,closure). Default: all of method|function|closure|arrow|if|for|foreach|while|do|try|switch|match')
            ->addOption('max-memory', null, InputOption::VALUE_REQUIRED, 'Soft memory ceiling in MB. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests --exact-only.')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Stay running and re-analyze on file changes (poll-based; Ctrl+C to exit)')
            ->addOption('tui', null, InputOption::VALUE_NONE, 'Show interactive SugarCraft dashboard after analysis completes')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Force plain CLI output (no TUI, no ANSI colours)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'TUI theme: ansi|plain|charm|dracula|nord|catppuccin', 'ansi');
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
        $kindsOpt = $input->getOption('kinds');
        if ($kindsOpt !== null) {
            $kinds = array_values(array_filter(array_map('trim', explode(',', (string)$kindsOpt))));
            $invalid = array_diff($kinds, \Phpdup\Extraction\BlockExtractor::ALL_KINDS);
            if ($invalid) {
                $output->writeln(sprintf(
                    '<error>phpdup: --kinds invalid: %s. Valid: %s</error>',
                    implode(',', $invalid),
                    implode(',', \Phpdup\Extraction\BlockExtractor::ALL_KINDS),
                ));
                return 2;
            }
            $overrides['allowed_kinds'] = $kinds;
        }

        $config = (new ConfigLoader())->load(
            paths: $paths,
            configFile: $input->getOption('config'),
            overrides: $overrides,
        );

        $useCache  = !$input->getOption('no-cache');
        $exactOnly = (bool)$input->getOption('exact-only');
        $showStats = (bool)$input->getOption('stats');
        $limit     = (int)$input->getOption('limit');
        $maxMemoryMb = $input->getOption('max-memory') !== null
            ? (int)$input->getOption('max-memory')
            : 0;

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

        $watchMode = (bool)$input->getOption('watch');
        $state     = new PipelineState($config);
        $tuiMode   = $this->shouldRunTui($input, $output);
        if ($watchMode && $tuiMode) {
            $output->writeln('<error>phpdup: --watch is incompatible with --tui (Phase 10 ships plain-mode watch only)</error>');
            return 2;
        }
        $themeName = (string)$input->getOption('theme');
        if ($tuiMode && !in_array(strtolower($themeName), TuiRunner::knownThemes(), true)) {
            $output->writeln(sprintf(
                '<error>phpdup: --theme must be one of %s</error>',
                implode('|', TuiRunner::knownThemes()),
            ));
            return 2;
        }

        $tuiRunner = new TuiRunner();
        $model     = $tuiMode ? $tuiRunner->buildModel($state, $themeName) : null;
        $listener  = $model instanceof ProgressListener ? $model : null;

        $pipeline = new Pipeline(
            stages: [
                new ScanningStage($listener),
                new PreprocessStage($useCache, $showStats, $listener, $maxMemoryMb),
                new ClusterStage($exactOnly, $maxMemoryMb),
                new RefactorStage($useCache),
                new ReportStage(
                    limit: $limit,
                    showStats: $showStats,
                    sarifFile: $input->getOption('sarif'),
                    gitlabSastFile: $input->getOption('gitlab-sast'),
                    diffDir: $input->getOption('diff'),
                    patchFile: $input->getOption('patch'),
                    checkstyleFile: $input->getOption('checkstyle'),
                ),
            ],
            stopAfter: $stopAfter,
            listener: $listener,
        );

        if ($watchMode) {
            $rebuild = static fn(): PipelineState => new PipelineState($config);
            return (new WatchRunner($pipeline, $rebuild, $output))->run();
        }

        $pipeline->run($state, $output);

        if ($model !== null) {
            $model->viewState->analysisComplete = true;
            return $tuiRunner->runWithModel($model);
        }

        return 0;
    }

    private function shouldRunTui(InputInterface $input, OutputInterface $_output): bool
    {
        if ($input->getOption('plain')) {
            return false;
        }
        // Phase 2: require --tui explicitly. Auto-enable on TTY is deferred until Phase 3
        // wires live progress; auto-launching today would surprise users running interactively.
        return (bool)$input->getOption('tui');
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
