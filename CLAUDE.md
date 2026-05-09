# phpdup — agent guide

AST-based PHP duplicate-logic detector. Parses PHP, normalizes ASTs, finds parameterizable clones, and proposes refactor signatures. Entry: `bin/phpdup` → `src/Cli/Command.php` (Symfony Console).

@./ARCHITECTURE.md

## Stack

- PHP (target floor — no standalone `:null` return; see `[gotcha]` in `CALIBER_LEARNINGS.md`)
- SugarCraft TUI: `sugarcraft/candy-*` + `sugarcraft/sugar-*` (dev-master, vcs repos block in `composer.json`)
- QA: PHPUnit · Psalm `6.0.0` · PHPStan
- `composer.lock` is **gitignored** (`.gitignore` line 2) — never stage it

## Commands

```bash
composer install
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
```

## Architecture

5-stage cooperative pipeline (`src/Pipeline/Pipeline.php`). Stages yield `Stage` between pumps so `src/Tui/PhpdupModel.php` can repaint live; `Command::execute()` drives them via `iter()`.

**Entry path**: `bin/phpdup` → `src/Cli/Command.php` → `src/Cli/ConfigLoader.php` → `Pipeline::iter()` → stages.

**Stages** (`src/Pipeline/Stages/`): `ScanningStage.php` → `PreprocessStage.php` → `ClusterStage.php` → `RefactorStage.php` → `ReportStage.php`. All implement `src/Pipeline/StageInterface.php`; cooperative ones implement `src/Pipeline/CooperativeStageInterface.php`. Shared mutable state: `src/Pipeline/PipelineState.php`. Stage enum: `src/Pipeline/Stage.php`.

**Scan**: `src/Scanning/FileScanner.php` honors `Config::$exclude` globs.

**Preprocess**: `src/Parsing/AstParser.php` + `src/Parsing/AstCache.php` → `src/Extraction/BlockExtractor.php` (kinds in `BlockExtractor::ALL_KINDS`) → `src/Normalization/Normalizer.php` (modes: `strict`/`default`/`aggressive`) → `src/Fingerprint/SubtreeHasher.php` + `src/Fingerprint/NgramFingerprint.php`. Per-file results cached in `src/Persistence/IndexStore.php` keyed by `sha1_file()` + parser version + config-key.

**Cluster**: `src/Clustering/Clusterer.php` runs three phases via `src/Index/BlockIndex.php` + `src/Index/NgramInvertedIndex.php` — (1) hash buckets for exact, (2) Jaccard (`src/Similarity/JaccardSimilarity.php`) + APTED (`src/Similarity/AptedDistance.php`, facade in `src/Similarity/TreeEditDistance.php`) for near-dups, (3) Containment (`src/Similarity/ContainmentSimilarity.php`) fallback for type-3. Union-find merges edges into `src/Clustering/Cluster.php`.

**Refactor** (`src/Refactor/`): `AntiUnifier.php` does LCS over per-stmt structural hashes → typed `Hole.php`s; `ParameterSynthesizer.php` infers types/names; `SignatureBuilder.php` emits `function name(...): mixed`; `PatternRecognizer.php` tags clusters (`config-driven`, `sql-builder`, `crud-handler`, `validation-chain`, `strategy`, `state-machine`, `optional-segments`). Lazy AST reload via `src/Extraction/BlockAstLoader.php`.

**Report** (`src/Reporting/`): one reporter per format — `CliReporter.php`, `JsonReporter.php`, `HtmlReporter.php`, `SarifReporter.php`, `GitLabSastReporter.php`, `CheckstyleReporter.php`, `CsvReporter.php`, `PrometheusReporter.php`, `TimeseriesReporter.php`, `DiffReporter.php`, `GraphvizReporter.php`, `PlantumlReporter.php`. Ordering via `Ranker.php` + `SafetyScorer.php` + `ClusterSort.php`; payload type `Report.php`.

**Parallelism** (`src/Parallel/`): `WorkerPool.php` uses `pcntl_fork` + `stream_select`-multiplexed socketpairs; `runStreaming()` yields per-record. Workers: `PreprocessWorker.php`, `PairScoreWorker.php`, `RefactorWorker.php`. Falls back to serial when `pcntl_*` unavailable.

**TUI / Watch** (`src/Tui/`, `src/Watch/`): `TuiRunner.php` wraps `SugarCraft\Core\Program`. `PhpdupModel.php` implements `Model` + `ProgressListener` and pumps the pipeline generator on `StagePumpedMsg`. View state: `src/Tui/ViewState.php`. `WatchRunner.php` drives re-analysis via `React\EventLoop` poll.

## Conventions

- **PHP 8.1 floor** — no standalone `:null` return type. For `enterNode`/`leaveNode` overrides drop the return type entirely (see `src/Extraction/BlockExtractor.php`).
- **PHP-Parser 5 quirks** — `Node\Stmt\Throw_` does NOT exist; use `Node\Stmt\Expression` with `->expr` as `Node\Expr\Throw_`. `Node\VarLikeIdentifier` extends `Node\Identifier` — check the subclass FIRST.
- **Strict types** everywhere — every file starts `declare(strict_types=1);`. Final classes by default.
- **Constructor promotion + readonly** — see `src/Cli/Config.php`, `src/Reporting/CliReporter.php`. Range-validate in the constructor body.
- **Enums** — `src/Pipeline/Stage.php` is the canonical pattern: cases + `label()` + `index()` + `ordered()`.
- **Static analysis** — keep `psalm-baseline.xml` clean; new errors must be **fixed**, not baselined. Both tools scoped to `src/` only; do NOT widen to `tests/Fixtures/` (intentionally malformed).
- **SugarCraft styling** — `Style::new()->foreground()` emits ANSI even with `Theme::plain()`; gate behind `$output->isDecorated()` (see `CliReporter::renderTags`).
- **Per-directory overrides** — `.phpdup.json` files discovered via `ConfigLoader::discoverPerDirectoryOverrides()`; merged longest-prefix-wins.
- **Codebase namespace** — `Phpdup\` → `src/`, `Phpdup\Tests\` → `tests/` (PSR-4, see `composer.json`).

## Testing

- Layout: `tests/Unit/<Subns>/<Class>Test.php` mirrors `src/`. Integration in `tests/Integration/`. Snapshots in `tests/Golden/`. Real fixtures in `tests/Fixtures/{exact,notify,optional,sql,unique}/`.
- `tests/Golden/GoldenTest.php` runs the full pipeline against fixtures and diffs against `tests/Golden/*.json`. Refresh: `UPDATE_SNAPSHOTS=1`.
- Bootstrap is `vendor/autoload.php` (`phpunit.xml`); `failOnWarning` and `failOnNotice` are ON — treat warnings as failures.
- Use `Symfony\Component\Console\Output\NullOutput` when invoking stages.
- For reporter tests, build a synthetic `Report` with `Cluster` + `Block` instances; do NOT run the pipeline.

## CI / profiles

- `.github/workflows/ci.yml` — primary CI. `.github/actions/phpdup/action.yml` is the reusable composite action.
- `.gitlab/phpdup-ci.yml` — GitLab include emitting GitLab SAST v15 to `gl-sast-report.json`.
- `profiles/{generic,laravel,symfony,wordpress,drupal}.json` — auto-applied by `src/Cli/ProjectProfileDetector.php` when project markers exist.
- See `docs/CI.md` (platform wiring), `docs/CLI.md` (full flag reference), `docs/BENCHMARKS.md` (v0.1→v0.2 perf), `docs/config-schema.json` (machine-readable config schema).

## Adding things

- **Reporter** — implement `writeTo(Report, string)` + a pure `build(Report)` in `src/Reporting/`; register in `src/Pipeline/Stages/ReportStage.php` and add a `--<name>` flag in `src/Cli/Command.php`.
- **Pipeline stage** — implement `StageInterface` (or `CooperativeStageInterface` for live progress) in `src/Pipeline/Stages/`; append to `src/Cli/Command.php`'s `$buildPipeline` closure in canonical Scanning→Preprocessing→Clustering→Refactoring→Reporting order.
- **Block kind** — extend `BlockExtractor::ALL_KINDS` (`src/Extraction/BlockExtractor.php`), add a `BlockVisitor::classifyKind()` case, and add the matching case in `BlockAstLoader::matchesKind()`.
- **Pattern tag** — add a detector method in `src/Refactor/PatternRecognizer.php`.
- **CLI flag** — add option in `src/Cli/Command.php`, thread through `src/Cli/ConfigLoader.php`, validate in `src/Cli/Config.php`, then update `docs/config-schema.json` and `docs/CLI.md`.
- **Language profile** — add `profiles/<framework>.json` + a marker check in `src/Cli/ProjectProfileDetector.php`; cover with `tests/Unit/Cli/`.

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
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
