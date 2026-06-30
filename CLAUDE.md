# phpdup — agent guide

AST-based PHP duplicate-logic detector. Parses PHP, normalizes ASTs, finds parameterizable clones, and proposes refactor signatures. Entry: `bin/phpdup` → `src/Cli/Command.php` (Symfony Console).

## Stack

- PHP 8.1 floor — no standalone `:null` return; see `[gotcha]` in `CALIBER_LEARNINGS.md`
- SugarCraft TUI: `sugarcraft/candy-*` + `sugarcraft/sugar-*` (dev-master via vcs repos in `composer.json`)
- QA: PHPUnit · Psalm `6.0.0` · PHPStan
- `composer.lock` is **gitignored** (`.gitignore` line 2) — never stage it

## Commands

```bash
composer install --no-interaction
```

```bash
vendor/bin/phpunit                                # all suites
vendor/bin/phpunit --testsuite Unit               # tests/Unit
vendor/bin/phpunit --testsuite Integration        # tests/Integration
vendor/bin/phpunit --testsuite Golden             # tests/Golden
UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden  # refresh tests/Golden/*.json
vendor/bin/phpstan analyse                        # phpstan.neon, level 6, src/ only
vendor/bin/psalm                                  # psalm.xml, errorLevel 6, src/ only
```

```bash
bin/phpdup analyze src --html report --json out.json
bin/phpdup analyze --config phpdup.json --validate-config   # exit 0=ok, 2=invalid
bin/phpdup completion bash > ~/.local/share/bash-completion/completions/phpdup
php -d phar.readonly=0 build-phar.php             # build phpdup.phar via box.json
```

## Architecture

5-stage cooperative pipeline (`src/Pipeline/Pipeline.php`). Stages yield `Stage` between pumps so `src/Tui/PhpdupModel.php` can repaint live; `src/Cli/Command.php::execute()` drives them via `iter()`.

**Entry**: `bin/phpdup` → `src/Cli/Command.php` → `src/Cli/ConfigLoader.php` → `Pipeline::iter()` → stages. Sibling commands: `src/Cli/ServeCommand.php` (`phpdup serve`, see `docs/SERVER.md`), `src/Cli/CompletionCommand.php`, `src/Cli/UpdateCommand.php`.

**Stages** (`src/Pipeline/Stages/`): `ScanningStage.php` → `PreprocessStage.php` → `ClusterStage.php` → `RefactorStage.php` → `ReportStage.php`. Implement `src/Pipeline/StageInterface.php`; live ones implement `src/Pipeline/CooperativeStageInterface.php`. Shared mutable state: `src/Pipeline/PipelineState.php`. Stage enum: `src/Pipeline/Stage.php`. Progress: `src/Pipeline/ProgressListener.php` + `NullProgressListener.php`. Debug sink: `src/Pipeline/DebugLogger.php` (wired via `PipelineState::setDebugLogger()`, gated by `--debug-log`).

**Scan**: `src/Scanning/FileScanner.php` honors `Config::$exclude` globs; defaults in `src/Scanning/DefaultExcludes.php`.

**Preprocess**: `src/Parsing/AstParser.php` + `src/Parsing/AstCache.php` + `src/Parsing/TokenCache.php` → `src/Extraction/BlockExtractor.php` (kinds in `BlockExtractor::ALL_KINDS`) → `src/Normalization/Normalizer.php` (modes: `strict`/`default`/`aggressive`) → `src/Fingerprint/SubtreeHasher.php` + `src/Fingerprint/NgramFingerprint.php`. ORM/DB pre-passes: `src/Normalization/DbOpCanonicalizer.php` + `src/Normalization/DbOpRegistry.php` (gated by `--db-aware`); `src/Normalization/TrinityCollapser.php` (gated by `--trinity-collapse`). Per-file results cached in `src/Persistence/IndexStore.php` keyed by `sha1_file()` + parser version + config-key. Plugin extensions: `src/Normalization/NormalizationPlugin.php` + `src/Normalization/PluginRegistry.php`.

**Cluster**: `src/Clustering/Clusterer.php` runs phases via `src/Index/BlockIndex.php` + `src/Index/NgramInvertedIndex.php` — (1) hash buckets for exact, (2) Jaccard (`src/Similarity/JaccardSimilarity.php`) + APTED (`src/Similarity/AptedDistance.php`, facade in `src/Similarity/TreeEditDistance.php` with `src/Similarity/EditCostModel.php`) for near-dups, (3) Containment (`src/Similarity/ContainmentSimilarity.php`) for type-3, (4) IR-tier fallback when `--scorer=ir` (`src/Ir/IrLifter.php`, `src/Ir/IrPrinter.php`), (5) ML pair sidecar when `--ml-pair-url` set (`src/Ml/MlPairClient.php`, `src/Ml/PairFeatures.php`). Cluster cache: `src/Persistence/ClusterCache.php`. Union-find → `src/Clustering/Cluster.php`.

**Refactor** (`src/Refactor/`): `AntiUnifier.php` does LCS over per-stmt structural hashes → typed `Hole.php`s; `ParameterSynthesizer.php` infers types/names; `SignatureBuilder.php` emits `function name(...): mixed`; `PatternRecognizer.php` tags (`config-driven`, `sql-builder`, `crud-handler`, `validation-chain`, `strategy`, `state-machine`, `optional-segments`, `controller-action`, `migration`, …). Lazy AST reload via `src/Extraction/BlockAstLoader.php`.

**Report** (`src/Reporting/`): one reporter per format — `CliReporter.php`, `JsonReporter.php` (+ `JsonSchemaSpec.php`), `HtmlReporter.php`, `SarifReporter.php`, `GitLabSastReporter.php`, `CheckstyleReporter.php`, `CsvReporter.php`, `PrometheusReporter.php`, `TimeseriesReporter.php`, `DiffReporter.php`, `GraphvizReporter.php`, `PlantumlReporter.php`, `RefactorPatchReporter.php`, `RefactorTestReporter.php`. Ordering via `Ranker.php` + `SafetyScorer.php` + `ClusterSort.php`; coherence outlier marking via `CoherenceAnalyzer.php`; payload type `Report.php`. Architectural post-analysis: `src/Architecture/Analyzers/{SolidAnalyzer,DesignPatternAnalyzer,AntiPatternAnalyzer}.php` + `src/Architecture/Finding.php`.

**Parallelism** (`src/Parallel/`): `WorkerPool.php` uses `pcntl_fork` + `stream_select`-multiplexed socketpairs; `runStreaming()` yields per-record. Workers: `PreprocessWorker.php`, `PairScoreWorker.php`, `RefactorWorker.php`. Falls back to serial when `pcntl_*` unavailable or items < 8.

**TUI / Watch / Server** (`src/Tui/`, `src/Watch/`, `src/Server/`): `TuiRunner.php` wraps `SugarCraft\Core\Program`; `PhpdupModel.php` implements `Model` + `ProgressListener` and pumps the pipeline generator on `src/Tui/Msg/StagePumpedMsg.php`. View state: `src/Tui/ViewState.php`. `WatchRunner.php` drives re-analysis via `React\EventLoop` poll. `src/Server/Application.php` + `src/Server/JobQueue.php` back `phpdup serve`.

## Conventions

- **PHP 8.1 floor** — no standalone `:null` return type. For `enterNode`/`leaveNode` overrides drop the return type entirely (see `src/Extraction/BlockExtractor.php`).
- **PHP-Parser 5 quirks** — `Node\Stmt\Throw_` does NOT exist; use `Node\Stmt\Expression` with `->expr` as `Node\Expr\Throw_`. `Node\VarLikeIdentifier` extends `Node\Identifier` — check the subclass FIRST.
- **Strict types everywhere** — every file starts `declare(strict_types=1);`. Final classes by default.
- **Constructor promotion + readonly** — see `src/Cli/Config.php`, `src/Reporting/CliReporter.php`. Range-validate in the constructor body and throw `\InvalidArgumentException`.
- **Enums** — `src/Pipeline/Stage.php` is the canonical pattern: cases + `label()` + `index()` + `ordered()`.
- **Static analysis** — keep `psalm-baseline.xml` clean; new errors must be **fixed**, not baselined. Both tools scoped to `src/` only via `psalm.xml` / `phpstan.neon`; do NOT widen to `tests/Fixtures/` (intentionally malformed).
- **SugarCraft styling** — `Style::new()->foreground()` emits ANSI even with `Theme::plain()`; gate behind `$output->isDecorated()` (see `CliReporter::renderTags`).
- **Per-directory overrides** — `.phpdup.json` files discovered via `ConfigLoader::discoverPerDirectoryOverrides()`; merged longest-prefix-wins.
- **PSR-4** — `Phpdup\` → `src/`, `Phpdup\Tests\` → `tests/` (`composer.json` `autoload` / `autoload-dev`).
- **Mutable Block fields** — `Block::$class/$file/$namespace/$name/$ast/$ngramBag/$id` are intentionally public-mutable; tests overwrite them post-construction. Do NOT mark `readonly`.
- **Schema bumps** — any change to `JsonReporter::build()`'s cluster shape requires bumping `Phpdup\Reporting\JsonSchemaSpec::SCHEMA_VERSION` and regenerating Golden via `UPDATE_SNAPSHOTS=1`. See `docs/JETBRAINS_PLUGIN.md` for consumer rules.

## Testing

- Layout: `tests/Unit/<Subns>/<Class>Test.php` mirrors `src/`. Integration in `tests/Integration/`. Snapshots in `tests/Golden/{notify-exact,sql-exact,optional-near-dups}.json`. Real fixtures in `tests/Fixtures/{exact,notify,optional,sql,unique}/`.
- `tests/Golden/GoldenTest.php` runs the full pipeline against fixtures and diffs against `tests/Golden/*.json`. Refresh: `UPDATE_SNAPSHOTS=1`.
- Bootstrap is `vendor/autoload.php` (`phpunit.xml`); `failOnWarning="true"` and `failOnNotice="true"` — any deprecation/notice fails the build.
- Use `Symfony\Component\Console\Output\NullOutput` when invoking stages.
- For reporter tests, build a synthetic `Report` with `Cluster` + `Block` instances; do NOT run the pipeline.
- Fuzz suite under `tests/Fuzz/DetectionRateTest.php` is corpus-driven (`bench/corpora/synthetic-fuzz/`).

## CI / profiles

- `.github/workflows/ci.yml` — primary CI. `.github/actions/phpdup/action.yml` is the reusable composite action (see `docs/CI.md`).
- `.github/workflows/release.yml` — signed-release + PHAR publish workflow; `phpdup self-update` (`src/Cli/UpdateCommand.php`) verifies the published artifact before swapping. See `docs/RELEASE.md`.
- `.gitlab/phpdup-ci.yml` — GitLab include emitting GitLab SAST v15 to `gl-sast-report.json`.
- `profiles/{generic,laravel,symfony,wordpress,drupal}.json` — framework auto-applied by `src/Cli/ProjectProfileDetector.php`.
- `profiles/db-aware-{laravel,doctrine,cake,thinkorm,medoo,propel,redbean,cycle,phpactiverecord,illuminate,aura,atlas,easydb,dibi,pixie,phalcon,idiorm,yii,laminas,codeigniter,myadmin,myadmin-orm,mongodb,redis,elasticsearch,neo4j,influxdb,couchdb,couchbase}.json` — DB symbol packs for `--db-aware` (option 4 of the ORM-dedup plan); composer-package detection lives in `ProjectProfileDetector::detectIn()`.

## Adding things

- **Reporter** — implement `writeTo(Report, string): void` + pure `build(Report)` in `src/Reporting/`; wire in `src/Pipeline/Stages/ReportStage.php` ctor + `run()`; add `--<name>` option in `src/Cli/Command.php` and thread `$reportArgs`.
- **Pipeline stage** — implement `StageInterface` (or `CooperativeStageInterface` with `YIELD_EVERY` const) in `src/Pipeline/Stages/`; append to `Command::execute()`'s `$buildPipeline` closure in canonical Scanning→Preprocess→Cluster→Refactor→Report order; update `src/Pipeline/Stage.php` enum if introducing a new phase.
- **Block kind** — extend `BlockExtractor::ALL_KINDS`, add `BlockVisitor::classifyKind()` case, add matching case in `BlockAstLoader::matchesKind()`.
- **Pattern tag** — add detector method in `src/Refactor/PatternRecognizer.php`; cover with `tests/Unit/Refactor/PatternRecognizerTest.php`.
- **CLI flag** — add option in `src/Cli/Command.php` (six edit sites — see `.claude/skills/add-cli-flag/`), thread through `src/Cli/ConfigLoader.php` (`load` + `validate`), validate range in `src/Cli/Config.php`, update `docs/config-schema.json` and `docs/CLI.md`.
- **Language/DB profile** — add `profiles/<name>.json` (allowed keys only — see `docs/config-schema.json`); register in `ProjectProfileDetector::KNOWN_PROFILES` + `detectIn()`; cover with `tests/Unit/Cli/ProjectProfileDetectorTest.php`.
- **Normalization plugin** — implement `Phpdup\Normalization\NormalizationPlugin::visit(Node, string)`; register via `Config::$normalizationPlugins`; thread the new class through `PreprocessWorker::toolFor()`'s cache-key sprintf and `PreprocessStage`'s IndexStore config-key hash.
- **Architectural analyzer** — implement `Phpdup\Architecture\ArchitecturalAnalyzer::analyze(Cluster): array<Finding>`; instantiate in `ReportStage::run()`'s analyzers array.

## Session learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns extracted from prior sessions — treat as project-specific rules.

## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."

## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.