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
            ->setHelp($this->buildGroupedHelp())
            ->addArgument('paths', InputArgument::IS_ARRAY, 'One or more paths to scan', []);

        // ── Configuration ──────────────────────────────────────────────────
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to phpdup.json config file')
            ->addOption('validate-config', null, InputOption::VALUE_NONE, 'Validate the --config file against the documented schema and exit (no analysis is run)');

        // ── Detection tuning ───────────────────────────────────────────────
        $this
            ->addOption('min-block-size', null, InputOption::VALUE_REQUIRED, 'Minimum AST node count for a block')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Normalization mode: strict|default|aggressive')
            ->addOption('similarity', null, InputOption::VALUE_REQUIRED, 'Jaccard similarity threshold (0..1)')
            ->addOption('max-df', null, InputOption::VALUE_REQUIRED, 'Max document-frequency (0..1) for n-grams to be candidate-pair seeds. 0.01 default suits real codebases; bump to ~0.5 for small fixtures.')
            ->addOption('optional-blocks', null, InputOption::VALUE_REQUIRED, 'Type-3 / "optional segment" detection: on|off (default on). When on, blocks whose statements differ in length but share a common skeleton cluster together with bool $include* params for the absent-from-some-members segments.')
            ->addOption('optional-blocks-containment', null, InputOption::VALUE_REQUIRED, 'Containment threshold (0..1) for the type-3 fallback path. Default 0.85.')
            ->addOption('min-impact', null, InputOption::VALUE_REQUIRED, 'Minimum cluster impact to report')
            ->addOption('exact-only', null, InputOption::VALUE_NONE, 'Skip near-duplicate detection (faster)')
            ->addOption('kinds', null, InputOption::VALUE_REQUIRED, 'Comma-separated block kinds to include (e.g. method,closure). Default: all of method|function|closure|arrow|if|for|foreach|while|do|try|switch|match')
            ->addOption('auto-tune', null, InputOption::VALUE_NONE, 'Probe the corpus before analysis and pick min-block-size / max-df / min-impact based on size; --exact-only is forced on for very large trees. Picked profile is printed; explicit CLI overrides take precedence.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Apply a project profile (laravel|symfony|drupal|wordpress|generic|auto) to seed framework-aware excludes + tuning. "auto" sniffs the scan path for known markers. Explicit CLI flags + --config win over profile values.')
            ->addOption('min-safety', null, InputOption::VALUE_REQUIRED, 'Drop clusters whose refactor-safety score (0..1) is below this threshold. 0 = report all (default).')
            ->addOption('ted-weights', null, InputOption::VALUE_REQUIRED, 'Tree-edit cost model: default|semantic. semantic weights method calls / control flow heavier than literals.');

        // ── Output / reports ───────────────────────────────────────────────
        $this
            ->addOption('html', null, InputOption::VALUE_REQUIRED, 'Write HTML report to this directory')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Write JSON report to this file')
            ->addOption('sarif', null, InputOption::VALUE_REQUIRED, 'Write SARIF 2.1.0 report to FILE (for GitHub/GitLab PR annotations)')
            ->addOption('gitlab-sast', null, InputOption::VALUE_REQUIRED, 'Write GitLab SAST report (v15) to FILE')
            ->addOption('checkstyle', null, InputOption::VALUE_REQUIRED, 'Write Checkstyle XML report to FILE')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Write flat CSV (one row per cluster member) to FILE')
            ->addOption('prometheus', null, InputOption::VALUE_REQUIRED, 'Write Prometheus text-format metrics to FILE (for pushgateway / CI dashboards)')
            ->addOption('timeseries', null, InputOption::VALUE_REQUIRED, 'Append a JSONL summary record (commit-tagged) to FILE for tracking duplicate debt over time')
            ->addOption('graphviz', null, InputOption::VALUE_REQUIRED, 'Write a Graphviz DOT graph (file→cluster bipartite) to FILE — render with `dot -Tpng FILE -o out.png`')
            ->addOption('plantuml', null, InputOption::VALUE_REQUIRED, 'Write a PlantUML class diagram to FILE — render with `plantuml FILE`')
            ->addOption('refactor-patch', null, InputOption::VALUE_REQUIRED, 'Emit one .patch file per cluster into DIR — heuristic, manual-review-required.')
            ->addOption('refactor-tests', null, InputOption::VALUE_REQUIRED, 'Emit a PHPUnit test skeleton per cluster into DIR (data-provider rows mirror hole observations).')
            ->addOption('diff', null, InputOption::VALUE_REQUIRED, 'Write per-cluster unified diffs into DIR')
            ->addOption('patch', null, InputOption::VALUE_REQUIRED, 'Write a single cumulative patch file containing every cluster diff')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Show at most N clusters in CLI output', 50)
            ->addOption('summary-only', null, InputOption::VALUE_NONE, 'Render only the top banner + final summary line (skip per-cluster details)')
            ->addOption('clusters', null, InputOption::VALUE_NONE, 'Render a one-line-per-cluster table instead of the full per-cluster breakdown')
            ->addOption('pager', null, InputOption::VALUE_REQUIRED, 'Page CLI report through $PAGER (default `less -R`). Modes: auto|never|always. auto pages when stdout is a TTY and the rendered output exceeds ~60 lines.', 'never')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Cluster sort: KEY[:asc|desc]. Keys: impact|members|block-size|lines|similarity|confidence|name|file|id. Aliases: size→members, count→members. Default impact:desc.')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show pipeline statistics');

        // ── Performance / runtime ──────────────────────────────────────────
        $this
            ->addOption('workers', 'j', InputOption::VALUE_REQUIRED, 'Worker count for parallel preprocess + pair scoring (0 = auto, 1 = serial)')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable AST cache for this run')
            ->addOption('no-incremental', null, InputOption::VALUE_NONE, 'Disable per-file index reuse')
            ->addOption('no-lazy-ast', null, InputOption::VALUE_NONE, 'Keep all original ASTs in memory (higher RSS, faster anti-unification)')
            ->addOption('max-memory', null, InputOption::VALUE_REQUIRED, 'Soft memory ceiling in MB. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests --exact-only.')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Run pipeline only up to STAGE (scanning|preprocessing|clustering|refactoring|reporting); useful for incremental debugging');

        // ── Interactive / UI ───────────────────────────────────────────────
        $this
            ->addOption('tui', null, InputOption::VALUE_NONE, 'Show interactive SugarCraft dashboard after analysis completes')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Force plain CLI output (no TUI, no ANSI colours)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'TUI theme: ansi|plain|charm|dracula|nord|catppuccin', 'ansi')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Stay running and re-analyze on file changes (poll-based; Ctrl+C to exit)');
    }

    /**
     * Categorized cheat-sheet shown under `Help:` when running --help.
     * Complements Symfony's auto-generated --help option list (which preserves
     * the addOption() order, so the groups also stay together there).
     */
    private function buildGroupedHelp(): string
    {
        return <<<HELP
Options grouped by category:

 <comment>Configuration</comment>
   --config, --validate-config

 <comment>Detection tuning</comment>
   --min-block-size, --mode, --similarity, --max-df,
   --optional-blocks, --optional-blocks-containment,
   --min-impact, --min-safety, --exact-only, --kinds, --auto-tune,
   --ted-weights,
   --profile

 <comment>Output / reports</comment>
   --html, --json, --sarif, --gitlab-sast, --checkstyle,
   --csv, --prometheus, --timeseries,
   --graphviz, --plantuml,
   --refactor-patch, --refactor-tests,
   --diff, --patch, --limit, --sort, --stats,
   --summary-only, --clusters

 <comment>Performance / runtime</comment>
   --workers (-j), --no-cache, --no-incremental, --no-lazy-ast,
   --max-memory, --stage

 <comment>Interactive / UI</comment>
   --tui, --plain, --theme, --watch

Run <info>phpdup --help</info> for full descriptions of each option.
HELP;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('validate-config')) {
            return $this->validateConfigOnly($input, $output);
        }

        $paths = $input->getArgument('paths');
        if (!$paths) {
            $output->writeln('<error>phpdup: at least one path is required</error>');
            $output->writeln('Run <info>phpdup --help</info> for usage and a grouped option reference.');
            $output->writeln('Example: <info>phpdup src/ --html=build/dup-report</info>');
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
            'sort'                        => $input->getOption('sort'),
            'ted_weights'                 => $input->getOption('ted-weights'),
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
        // Validate --sort eagerly so the user gets a friendly exit 2 instead
        // of an uncaught InvalidArgumentException out of Config::__construct.
        $sortOpt = $input->getOption('sort');
        if ($sortOpt !== null) {
            try {
                \Phpdup\Reporting\ClusterSort::parse((string)$sortOpt);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf(
                    '<error>phpdup: --sort %s. Valid keys: %s. Direction: asc|desc.</error>',
                    $e->getMessage(),
                    implode(', ', \Phpdup\Reporting\ClusterSort::ALL_KEYS),
                ));
                return 2;
            }
        }
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

        // Profile detection (V.A.2): runs before --auto-tune so the
        // tuner sees the framework-aware excludes / kinds. Order:
        //   1. Profile fills in *missing* keys.
        //   2. Auto-tune fills in still-missing keys.
        //   3. ConfigLoader merges JSON config + final $overrides.
        // Explicit --foo CLI flags went into $overrides up top, so
        // they always beat profile + tuner.
        $profileData = [];
        $profileOpt = $input->getOption('profile');
        if ($profileOpt !== null) {
            $registry = ProfileRegistry::bundled();
            $profileName = (string)$profileOpt;
            if ($profileName === 'auto') {
                $profileName = (new ProjectProfileDetector())->detect($paths);
                $output->writeln("<info>phpdup</info> profile auto-detect: {$profileName}");
            } elseif (!in_array($profileName, $registry->listAvailable(), true)) {
                $output->writeln(sprintf(
                    '<error>phpdup: --profile must be one of %s|auto</error>',
                    implode('|', $registry->listAvailable()),
                ));
                return 2;
            }
            try {
                $profileData = $registry->load($profileName);
            } catch (\RuntimeException $e) {
                $output->writeln('<error>phpdup: ' . $e->getMessage() . '</error>');
                return 2;
            }
            // Map profile JSON keys to override-dict keys.
            $profileOverrides = $this->profileToOverrides($profileData);
            foreach ($profileOverrides as $k => $v) {
                if (!array_key_exists($k, $overrides)) {
                    $overrides[$k] = $v;
                }
            }
        }
        $profileExclude = isset($profileData['exclude']) && is_array($profileData['exclude'])
            ? array_values(array_filter($profileData['exclude'], 'is_string'))
            : null;
        unset($profileData);

        $autoTuneExactOnly = false;
        if ($input->getOption('auto-tune')) {
            $base = Config::defaults($paths);
            $tuner = new AutoTuner();
            $suggestion = $tuner->tune($paths, $base->exclude);
            $output->writeln(sprintf(
                '<info>phpdup</info> auto-tune: %s',
                $suggestion->rationale,
            ));
            // Explicit CLI flags win — only fill in keys the user didn't
            // override. 'exact_only' is a synthetic CLI-level switch handled
            // below, not a Config override.
            foreach ($suggestion->overrides as $k => $v) {
                if ($k === 'exact_only') {
                    $autoTuneExactOnly = (bool)$v;
                    continue;
                }
                if (!array_key_exists($k, $overrides)) {
                    $overrides[$k] = $v;
                }
            }
        }

        $config = (new ConfigLoader())->load(
            paths: $paths,
            configFile: $input->getOption('config'),
            overrides: $overrides,
            profileExclude: $profileExclude,
        );

        $useCache  = !$input->getOption('no-cache');
        $exactOnly = (bool)$input->getOption('exact-only') || $autoTuneExactOnly;
        $showStats = (bool)$input->getOption('stats');
        $limit     = (int)$input->getOption('limit');
        $cliVerbosity = match (true) {
            (bool)$input->getOption('summary-only') => \Phpdup\Reporting\CliReporter::VERBOSITY_SUMMARY_ONLY,
            (bool)$input->getOption('clusters')     => \Phpdup\Reporting\CliReporter::VERBOSITY_CLUSTERS,
            default                                 => \Phpdup\Reporting\CliReporter::VERBOSITY_FULL,
        };
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
            'csvFile'        => $input->getOption('csv'),
            'prometheusFile' => $input->getOption('prometheus'),
            'timeseriesFile' => $input->getOption('timeseries'),
            'graphvizFile'   => $input->getOption('graphviz'),
            'plantumlFile'   => $input->getOption('plantuml'),
            'refactorPatchDir' => $input->getOption('refactor-patch'),
            'refactorTestsDir' => $input->getOption('refactor-tests'),
            'cliVerbosity'   => $cliVerbosity,
            'minSafety'      => $input->getOption('min-safety') !== null ? (float)$input->getOption('min-safety') : 0.0,
            'pagerMode'      => (string)($input->getOption('pager') ?? Pager::MODE_NEVER),
        ];
        if (!in_array($reportArgs['pagerMode'], Pager::MODES, true)) {
            $output->writeln('<error>phpdup: --pager must be one of ' . implode('|', Pager::MODES) . '</error>');
            return 2;
        }
        if ($reportArgs['minSafety'] < 0.0 || $reportArgs['minSafety'] > 1.0) {
            $output->writeln('<error>phpdup: --min-safety must be in [0, 1]</error>');
            return 2;
        }
        $buildPipeline = static function (?ProgressListener $listener) use (
            $useCache, $exactOnly, $showStats, $maxMemoryMb, $stopAfter, $reportArgs
        ): Pipeline {
            return new Pipeline(
                stages: [
                    new ScanningStage($listener),
                    new PreprocessStage($useCache, $showStats, $listener, $maxMemoryMb),
                    new ClusterStage($exactOnly, $maxMemoryMb, $listener),
                    new RefactorStage($useCache, $listener),
                    new ReportStage(
                        limit:          $reportArgs['limit'],
                        showStats:      $reportArgs['showStats'],
                        sarifFile:      $reportArgs['sarifFile'],
                        gitlabSastFile: $reportArgs['gitlabSastFile'],
                        diffDir:        $reportArgs['diffDir'],
                        patchFile:      $reportArgs['patchFile'],
                        checkstyleFile: $reportArgs['checkstyleFile'],
                        csvFile:        $reportArgs['csvFile'],
                        prometheusFile: $reportArgs['prometheusFile'],
                        timeseriesFile: $reportArgs['timeseriesFile'],
                        cliVerbosity:   $reportArgs['cliVerbosity'],
                        minSafety:      $reportArgs['minSafety'],
                        graphvizFile:   $reportArgs['graphvizFile'],
                        plantumlFile:   $reportArgs['plantumlFile'],
                        pagerMode:      $reportArgs['pagerMode'],
                        refactorPatchDir: $reportArgs['refactorPatchDir'],
                        refactorTestsDir: $reportArgs['refactorTestsDir'],
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
        SignalHandler::install($state);
        $pipeline = $buildPipeline(null);
        try {
            $pipeline->run($state, $output);
        } finally {
            SignalHandler::uninstall();
        }

        if ($state->cancelled) {
            $output->writeln('<comment>phpdup: cancelled by user — partial report rendered</comment>');
            return 130; // canonical SIGINT exit code
        }
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

    /**
     * Map a profile JSON document onto the override-dict shape that
     * ConfigLoader::load() / Config::withOverrides() understand.
     * 'exclude' is handled separately (it goes through ConfigLoader's
     * profileExclude param so excludes only kick in as a default).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function profileToOverrides(array $data): array
    {
        $out = [];
        foreach (['min_block_size', 'max_block_size', 'normalization_mode',
                  'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
                  'max_df', 'ngram_size', 'sort'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (array_key_exists('kinds', $data)) {
            $out['allowed_kinds'] = $data['kinds'];
        }
        return $out;
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
