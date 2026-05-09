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
            ->addOption('max-df', null, InputOption::VALUE_REQUIRED, 'Max document-frequency (0..1) for n-grams to be candidate-pair seeds. 0.01 default suits real codebases; bump to ~0.5 for small fixtures.')
            ->addOption('optional-blocks', null, InputOption::VALUE_REQUIRED, 'Type-3 / "optional segment" detection: on|off (default on). When on, blocks whose statements differ in length but share a common skeleton cluster together with bool $include* params for the absent-from-some-members segments.')
            ->addOption('optional-blocks-containment', null, InputOption::VALUE_REQUIRED, 'Containment threshold (0..1) for the type-3 fallback path. Default 0.85.')
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
            'min_block_size'              => $input->getOption('min-block-size'),
            'normalization_mode'          => $input->getOption('mode'),
            'similarity_threshold'        => $input->getOption('similarity'),
            'min_cluster_impact'          => $input->getOption('min-impact'),
            'html'                        => $input->getOption('html'),
            'json'                        => $input->getOption('json'),
            'workers'                     => $input->getOption('workers'),
            'max_df'                      => $input->getOption('max-df'),
            'optional_blocks_containment' => $input->getOption('optional-blocks-containment'),
        ], fn($v) => $v !== null);
        $obFlag = $input->getOption('optional-blocks');
        if ($obFlag !== null) {
            $obFlag = strtolower((string)$obFlag);
            if (!in_array($obFlag, ['on', 'off', 'true', 'false', '1', '0', 'yes', 'no'], true)) {
                $output->writeln('<error>phpdup: --optional-blocks must be on|off</error>');
                return 2;
            }
            $overrides['optional_blocks_enabled'] = in_array($obFlag, ['on', 'true', '1', 'yes'], true);
        }
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
        $tuiMode   = $this->shouldRunTui($input, $output);
        $themeName = (string)$input->getOption('theme');
        if ($tuiMode && !in_array(strtolower($themeName), TuiRunner::knownThemes(), true)) {
            $output->writeln(sprintf(
                '<error>phpdup: --theme must be one of %s</error>',
                implode('|', TuiRunner::knownThemes()),
            ));
            return 2;
        }

        // Factory closure used both for live TUI mode (init() + restart on watch change)
        // and for the synchronous code path. Captures all the runtime knobs by value;
        // $modelRef is by-reference so the listener resolves once the model is built.
        $modelRef    = null;
        $reportArgs  = [
            'limit'          => $limit,
            'showStats'      => $showStats,
            'sarifFile'      => $input->getOption('sarif'),
            'gitlabSastFile' => $input->getOption('gitlab-sast'),
            'diffDir'        => $input->getOption('diff'),
            'patchFile'      => $input->getOption('patch'),
            'checkstyleFile' => $input->getOption('checkstyle'),
        ];
        $buildPipeline = static function (?ProgressListener $listener) use (
            $useCache, $exactOnly, $showStats, $maxMemoryMb, $stopAfter, $reportArgs
        ): Pipeline {
            return new Pipeline(
                stages: [
                    new ScanningStage($listener),
                    new PreprocessStage($useCache, $showStats, $listener, $maxMemoryMb),
                    new ClusterStage($exactOnly, $maxMemoryMb),
                    new RefactorStage($useCache),
                    new ReportStage(
                        limit:          $reportArgs['limit'],
                        showStats:      $reportArgs['showStats'],
                        sarifFile:      $reportArgs['sarifFile'],
                        gitlabSastFile: $reportArgs['gitlabSastFile'],
                        diffDir:        $reportArgs['diffDir'],
                        patchFile:      $reportArgs['patchFile'],
                        checkstyleFile: $reportArgs['checkstyleFile'],
                    ),
                ],
                stopAfter: $stopAfter,
                listener:  $listener,
            );
        };

        $tuiRunner = new TuiRunner();

        if ($tuiMode) {
            $iteratorFactory = static function () use (&$modelRef, $buildPipeline, $config, $output): array {
                $state = new PipelineState($config);
                /** @var ProgressListener|null $listener */
                $listener = $modelRef;
                $gen = $buildPipeline($listener)->iter($state, $output);
                return [$gen, $state];
            };
            $modelRef = $tuiRunner->buildLiveModel($themeName, $iteratorFactory);

            if ($watchMode) {
                return $this->runWatchTui($modelRef, $tuiRunner, $config, $output);
            }
            return $tuiRunner->runWithModel($modelRef);
        }

        if ($watchMode) {
            $pipeline = $buildPipeline(null);
            $rebuild  = static fn(): PipelineState => new PipelineState($config);
            return (new WatchRunner($pipeline, $rebuild, $output))->run();
        }

        $state    = new PipelineState($config);
        $pipeline = $buildPipeline(null);
        $pipeline->run($state, $output);

        return 0;
    }

    private function runWatchTui(
        PhpdupModel $model,
        TuiRunner $tuiRunner,
        Config $config,
        OutputInterface $output,
    ): int {
        $loop    = \React\EventLoop\Loop::get();
        $program = $tuiRunner->makeProgram($model, useAltScreen: true, loop: $loop);

        // Watch by polling source mtimes once the first analysis pass has emitted $state->files.
        // We rebuild the snapshot whenever the model fires a fresh analysis run.
        $snapshot = [];
        $reload   = 0;
        $loop->addPeriodicTimer(1.5, function () use ($model, &$snapshot, &$reload, $program): void {
            $files = $model->state->files;
            if ($files === [] || !$model->viewState->analysisComplete) {
                // Still working on the current run — don't double-trigger.
                return;
            }
            if ($snapshot === []) {
                $snapshot = $this->snapshotMtimes($files);
                return;
            }
            $changed = $this->pollChanges($snapshot, $files);
            if ($changed === []) return;
            $reload++;
            $program->send(new \Phpdup\Tui\Msg\RestartPipelineMsg($reload));
            $snapshot = []; // re-snapshot after the new run completes.
        });

        $program->run();
        return 0;
    }

    /**
     * @param list<string> $files
     * @return array<string,int>
     */
    private function snapshotMtimes(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            clearstatcache(true, $f);
            $m = @filemtime($f);
            if ($m !== false) {
                $out[$f] = $m;
            }
        }
        return $out;
    }

    /**
     * @param array<string,int> $previous
     * @param list<string> $current
     * @return list<string>
     */
    private function pollChanges(array &$previous, array $current): array
    {
        $changed = [];
        $known = $previous;
        foreach ($current as $f) {
            clearstatcache(true, $f);
            $m = @filemtime($f);
            if ($m === false) continue;
            if (!isset($known[$f])) {
                $changed[] = $f;       // new file
                $previous[$f] = $m;
            } elseif ($known[$f] !== $m) {
                $changed[] = $f;       // mtime changed
                $previous[$f] = $m;
            }
        }
        // detect deletions
        foreach (array_keys($previous) as $f) {
            if (!in_array($f, $current, true)) {
                $changed[] = $f;
                unset($previous[$f]);
            }
        }
        return $changed;
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
