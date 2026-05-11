# phpdup — agent guide

AST-based PHP duplicate-logic detector. Parses PHP, normalizes ASTs, finds parameterizable clones, and proposes refactor signatures. Entry: `bin/phpdup` → `src/Cli/Command.php` (Symfony Console).

## Stack

- PHP 8.1 floor — no standalone `:null` return type.
- `nikic/php-parser` `^5.0`, `symfony/console` `^6.4||^7.0`, `sebastian/diff` `^5.1||^6.0`.
- SugarCraft TUI: `sugarcraft/candy-*` + `sugarcraft/sugar-*` (dev-master, vcs repos block in `composer.json`).
- QA: PHPUnit `^10.5` · Psalm `6.0.0` · PHPStan `^2.1`.
- `composer.lock` is **gitignored** — never stage it.

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
php -d phar.readonly=0 build-phar.php                       # build phpdup.phar via box.json
```

## Architecture

5-stage cooperative pipeline (`src/Pipeline/Pipeline.php`). Stages yield `Stage` between pumps so `src/Tui/PhpdupModel.php` repaints live; `src/Cli/Command.php::execute()` drives them via `iter()`.

**Entry**: `bin/phpdup` → `src/Cli/Command.php` → `src/Cli/ConfigLoader.php` → `Pipeline::iter()` → stages. Sibling commands: `src/Cli/ServeCommand.php` (see `docs/SERVER.md`), `src/Cli/CompletionCommand.php`, `src/Cli/UpdateCommand.php`.

**Stages** (`src/Pipeline/Stages/`): `ScanningStage.php` → `PreprocessStage.php` → `ClusterStage.php` → `RefactorStage.php` → `ReportStage.php`. All implement `src/Pipeline/StageInterface.php`; cooperative ones implement `src/Pipeline/CooperativeStageInterface.php`. Mutable state: `src/Pipeline/PipelineState.php`. Enum: `src/Pipeline/Stage.php`. Progress: `src/Pipeline/ProgressListener.php` + `NullProgressListener.php`.

**Scan**: `src/Scanning/FileScanner.php` honors `Config::$exclude` globs; defaults in `src/Scanning/DefaultExcludes.php`.

**Preprocess**: `src/Parsing/AstParser.php` + `src/Parsing/AstCache.php` + `src/Parsing/TokenCache.php` → `src/Extraction/BlockExtractor.php` (kinds in `BlockExtractor::ALL_KINDS`) → `src/Normalization/Normalizer.php` (modes: `strict`/`default`/`aggressive`) → `src/Fingerprint/SubtreeHasher.php` + `src/Fingerprint/NgramFingerprint.php`. ORM/DB pre-passes: `src/Normalization/DbOpCanonicalizer.php` + `src/Normalization/DbOpRegistry.php` (gated by `--db-aware`); `src/Normalization/TrinityCollapser.php` (gated by `--trinity-collapse`). Per-file cache: `src/Persistence/IndexStore.php`.

**Cluster**: `src/Clustering/Clusterer.php` runs phases via `src/Index/BlockIndex.php` + `src/Index/NgramInvertedIndex.php` — hash buckets → Jaccard (`src/Similarity/JaccardSimilarity.php`) + APTED (`src/Similarity/AptedDistance.php` via `src/Similarity/TreeEditDistance.php` with `src/Similarity/EditCostModel.php`) → Containment (`src/Similarity/ContainmentSimilarity.php`) → IR-tier (`src/Ir/IrLifter.php`, `--scorer=ir`) → ML pair (`src/Ml/MlPairClient.php`, `--ml-pair-url`). Cluster cache: `src/Persistence/ClusterCache.php`. Union-find → `src/Clustering/Cluster.php`.

**Refactor** (`src/Refactor/`): `AntiUnifier.php` LCS over per-stmt structural hashes → typed `Hole.php`s; `ParameterSynthesizer.php` infers types/names; `SignatureBuilder.php` emits `function name(...): mixed`; `PatternRecognizer.php` tags clusters. Lazy AST reload: `src/Extraction/BlockAstLoader.php`.

**Report** (`src/Reporting/`): one reporter per format — `CliReporter.php`, `JsonReporter.php` (+ `JsonSchemaSpec.php`), `HtmlReporter.php`, `SarifReporter.php`, `GitLabSastReporter.php`, `CheckstyleReporter.php`, `CsvReporter.php`, `PrometheusReporter.php`, `TimeseriesReporter.php`, `DiffReporter.php`, `GraphvizReporter.php`, `PlantumlReporter.php`, `RefactorPatchReporter.php`, `RefactorTestReporter.php`. Ordering: `Ranker.php` + `SafetyScorer.php` + `ClusterSort.php`. Coherence: `CoherenceAnalyzer.php`. Architectural post-analysis: `src/Architecture/Analyzers/{SolidAnalyzer,DesignPatternAnalyzer,AntiPatternAnalyzer}.php` + `src/Architecture/Finding.php`. Payload type: `Report.php`.

**Parallelism** (`src/Parallel/`): `WorkerPool.php` uses `pcntl_fork` + `stream_select`-multiplexed socketpairs; `runStreaming()` yields per-record. Workers: `PreprocessWorker.php`, `PairScoreWorker.php`, `RefactorWorker.php`. Falls back to serial when `pcntl_*` unavailable.

**TUI / Watch / Server** (`src/Tui/`, `src/Watch/`, `src/Server/`): `TuiRunner.php` wraps `SugarCraft\Core\Program`; `PhpdupModel.php` implements `Model` + `ProgressListener` and pumps the pipeline on `src/Tui/Msg/StagePumpedMsg.php`. View: `src/Tui/ViewState.php`. `WatchRunner.php` drives re-analysis via `React\EventLoop`. `phpdup serve` backed by `src/Server/Application.php` + `src/Server/JobQueue.php`.

## Conventions

- **PHP 8.1 floor** — no standalone `:null` return; drop return type on `enterNode`/`leaveNode` overrides (`src/Extraction/BlockExtractor.php`).
- **PHP-Parser 5 quirks** — `Node\Stmt\Throw_` does NOT exist; use `Node\Stmt\Expression` with `->expr` as `Node\Expr\Throw_`. `Node\VarLikeIdentifier` extends `Node\Identifier` — check the subclass FIRST.
- **Strict types everywhere** — `declare(strict_types=1);` first line. Final classes by default.
- **Constructor promotion + readonly** — see `src/Cli/Config.php`, `src/Reporting/CliReporter.php`. Range-validate in the constructor body.
- **Enums** — `src/Pipeline/Stage.php` is canonical: cases + `label()` + `index()` + `ordered()`.
- **Static analysis** — keep `psalm-baseline.xml` clean. Tools scoped to `src/` only via `psalm.xml` / `phpstan.neon`; do NOT widen to `tests/Fixtures/`.
- **SugarCraft styling** — gate `Style::new()->foreground(...)` behind `$output->isDecorated()` (`Theme::plain()` doesn't neutralize raw `Style`).
- **Per-directory overrides** — `.phpdup.json` discovered via `ConfigLoader::discoverPerDirectoryOverrides()`; merged longest-prefix-wins.
- **PSR-4** — `Phpdup\` → `src/`, `Phpdup\Tests\` → `tests/`.
- **Mutable Block fields** — `Block::$class/$file/$namespace/$name/$ast/$ngramBag/$id/$rangeHash` are public-mutable by design; do NOT mark `readonly`.
- **Schema bumps** — any `JsonReporter::build()` cluster-shape change requires bumping `JsonSchemaSpec::SCHEMA_VERSION` and regenerating Golden via `UPDATE_SNAPSHOTS=1`.

## Testing

- Mirror `src/` layout under `tests/Unit/<Subns>/<Class>Test.php`. Integration in `tests/Integration/`. Snapshots in `tests/Golden/{notify-exact,sql-exact,optional-near-dups}.json`. Real fixtures in `tests/Fixtures/{exact,notify,optional,sql,unique}/`.
- `tests/Golden/GoldenTest.php` runs the full pipeline. Refresh: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`.
- `phpunit.xml` sets `failOnWarning="true"` AND `failOnNotice="true"` — any deprecation/notice fails the build.
- Use `Symfony\Component\Console\Output\NullOutput` when invoking stages.
- Reporter tests: build a synthetic `Report` with `Cluster` + `Block` instances; do NOT run the pipeline.
- Fuzz suite in `tests/Fuzz/DetectionRateTest.php`.

## CI / profiles

- `.github/workflows/ci.yml` + `.github/actions/phpdup/action.yml` (composite, see `docs/CI.md`).
- `.gitlab/phpdup-ci.yml` — GitLab SAST v15 → `gl-sast-report.json`.
- Framework profiles: `profiles/{generic,laravel,symfony,wordpress,drupal}.json` — auto-applied by `src/Cli/ProjectProfileDetector.php`.
- DB-aware symbol packs: `profiles/db-aware-{laravel,doctrine,cake,thinkorm,medoo,propel,redbean,cycle,phpactiverecord,illuminate,aura,atlas,easydb,dibi,pixie,phalcon,idiorm,yii,laminas,codeigniter,myadmin,myadmin-orm,mongodb,redis,elasticsearch,neo4j,influxdb,couchdb,couchbase}.json` — composer-package detection in `ProjectProfileDetector::detectIn()`.

## Adding things

- **Reporter** — `final class` in `src/Reporting/` with `writeTo(Report, string): void` + pure `build(Report)`; wire into `src/Pipeline/Stages/ReportStage.php` and add `--<name>` flag in `src/Cli/Command.php`.
- **Pipeline stage** — implement `StageInterface` (or `CooperativeStageInterface` with a `YIELD_EVERY` const); append to `Command::execute()`'s `$buildPipeline` closure in canonical order; update `Stage` enum if introducing a new phase.
- **Block kind** — extend `BlockExtractor::ALL_KINDS`, add `BlockVisitor::classifyKind()` case, add `BlockAstLoader::matchesKind()` case.
- **Pattern tag** — add a detector method in `src/Refactor/PatternRecognizer.php`; cover with `tests/Unit/Refactor/PatternRecognizerTest.php`.
- **CLI flag** — six edit sites: `Command::configure()`, grouped-help builder, `Command::execute()` overrides, `ConfigLoader::load()`, `ConfigLoader::validate()`, `Config` constructor. Plus `docs/config-schema.json` + `docs/CLI.md`.
- **DB symbol pack** — `profiles/db-aware-<name>.json` (allowed keys only), register in `ProjectProfileDetector::KNOWN_PROFILES` + `detectIn()`, cover with `tests/Unit/Cli/ProjectProfileDetectorTest.php`.
- **Normalization plugin** — implement `Phpdup\Normalization\NormalizationPlugin::visit(Node, string)`; thread the class through `PreprocessWorker::toolFor()` cache-key AND `PreprocessStage` IndexStore config-key.
- **Architectural analyzer** — implement `Phpdup\Architecture\ArchitecturalAnalyzer::analyze(Cluster)`; instantiate in `ReportStage::run()` analyzers array.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, read `.agents/skills/setup-caliber/SKILL.md` and follow its instructions to install Caliber.
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
