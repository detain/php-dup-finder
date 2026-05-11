---
name: add-pipeline-stage
description: Adds a new cooperative stage in src/Pipeline/Stages/ following ScanningStage/PreprocessStage patterns: StageInterface vs CooperativeStageInterface choice, YIELD_EVERY constant, ProgressListener wiring with NullProgressListener fallback, PipelineState field additions, and threading into Command::execute()'s $buildPipeline closure in canonical Scanning→Preprocess→Cluster→Refactor→Report order. Use when user says 'add pipeline stage', 'new pipeline phase', 'extend the pipeline', or 'add a stage between X and Y'. Do NOT use for modifying existing stages (edit them in place), adding reporters (use add-reporter), or adding new Clusterer sub-phases (those go inside ClusterStage::iter()).
---

# add-pipeline-stage

## Critical

- **Cooperative vs synchronous**: implement `CooperativeStageInterface` (extends `StageInterface`) whenever the stage does >1s of work, iterates files/blocks/clusters, or needs live TUI repaints. Pure synchronous stages (`StageInterface` only) are only acceptable for cheap, atomic steps. When in doubt, go cooperative — `run()` becomes a one-line drain of `iter()`.
- **Never branch on `$listener === null`**. Always fall back to `NullProgressListener` in the constructor and call `$this->listener->onXxx(...)` unconditionally. See `src/Pipeline/Stages/ScanningStage.php:22-25`.
- **Yield by yielding `Stage::<Self>`, not an int**. The driver inspects the yielded value (see `src/Pipeline/Pipeline.php:46`).
- **Mutate `PipelineState` directly** — do not store cross-run state on `$this`. Stages are constructed once and may be reused.
- **Honor `$state->cancelled`** in cooperative loops — break out cleanly between yields so a partial report can still be generated. The pipeline's outer loop already skips remaining stages once cancelled, but mid-stage you must respect it yourself.
- **Canonical order is fixed**: Scanning → Preprocessing → Clustering → Refactoring → Reporting. Insertions go *between* existing stages; nothing goes after Reporting.
- **PHP 8.1 floor** — `declare(strict_types=1)`, `final class`, constructor promotion, readonly where applicable. No standalone `:null` return types.

## Instructions

### Step 1 — Decide if this is a new `Stage` enum case or fits an existing one

If the new stage represents a brand-new phase (not a sub-pass of an existing one), edit `src/Pipeline/Stage.php`:

1. Add the case in canonical position, e.g. `case Indexing = 'indexing';` between Preprocessing and Clustering.
2. Add a `label()` arm returning the human name (`'Indexing'`).
3. Add an `index()` arm with the new ordinal and **renumber every later case**.
4. Insert the case into `ordered()` in the same position.

If the new stage is a sub-phase of an existing one (rare — usually belongs inside that stage's `iter()`), STOP and use the existing enum case.

**Verify**: `vendor/bin/phpstan analyse src/Pipeline/Stage.php` is clean before proceeding.

### Step 2 — Add fields to `PipelineState` for this stage's outputs and progress

Edit `src/Pipeline/PipelineState.php`. Mirror existing patterns:

- One field for the stage's primary output (typed list, e.g. `/** @var list<XxxRecord> */ public array $xxxs = [];`).
- Two counters for cooperative progress: `public int $xxxTotal = 0;` and `public int $xxxProcessed = 0;`.
- A timings entry: in the `$timings` array initializer add `'xxx' => 0.0,`.
- Reuse existing TUI fields: `currentTask`, `stageProgress`, `rssBytes`, `peakBytes`, `stageStartTime`, `debugMessages` (via `pushDebugMessage()`).

**Do not** add a `?XxxStage` reference on `PipelineState` — stages communicate only through data fields.

**Verify**: `vendor/bin/phpstan analyse src/Pipeline/PipelineState.php` clean.

### Step 3 — (Optional) Extend `ProgressListener` if existing hooks don't cover this stage

Look at `src/Pipeline/ProgressListener.php`. If an existing hook fits (`onFileScanned`, `onPairScored`, etc.), reuse it.

Only if no existing hook fits:

1. Add an `onXxx(int $done, int $total): void;` method to `ProgressListener`.
2. Add a matching no-op `public function onXxx(int $done, int $total): void {}` to `src/Pipeline/NullProgressListener.php`.
3. Implement it in `src/Tui/PhpdupModel.php` (mutate the model fields the dashboard reads).

**Verify**: `vendor/bin/phpstan analyse src/Pipeline/ src/Tui/` clean.

### Step 4 — Create the stage class in `src/Pipeline/Stages/XxxStage.php`

Use this exact scaffold (modeled on `ScanningStage.php` and `PreprocessStage.php`):

```php
<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Util\MemoryDebug;
use Symfony\Component\Console\Output\OutputInterface;

final class XxxStage implements CooperativeStageInterface
{
    /** Yield to the runtime every N units so the TUI can repaint. */
    private const YIELD_EVERY = 32; // 16 for fast units, 32-64 typical, 256 for tight loops

    private readonly ProgressListener $listener;

    public function __construct(
        // stage-specific config (e.g. bool $useCache, int $maxMemoryMb) FIRST
        ?ProgressListener $listener = null,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    public function name(): Stage
    {
        return Stage::Xxx;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain
        }
    }

    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        $state->stageStartTime = microtime(true);
        $state->currentTask    = 'xxx: starting';
        $t0 = microtime(true);

        $sinceYield = 0;
        $state->xxxTotal = count($state->blocks); // or whatever the input is

        foreach ($state->blocks as $i => $block) {
            if ($state->cancelled) {
                break;
            }

            // … do work, append to $state->xxxs …

            $state->xxxProcessed = $i + 1;
            $state->stageProgress = $state->xxxTotal > 0
                ? $state->xxxProcessed / $state->xxxTotal
                : 1.0;
            $this->listener->onXxx($state->xxxProcessed, $state->xxxTotal);

            if (++$sinceYield >= self::YIELD_EVERY) {
                $sinceYield = 0;
                $state->rssBytes  = memory_get_usage(false);
                $state->peakBytes = memory_get_peak_usage(true);
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                    $msg = sprintf('xxx: %d/%d [%s]', $state->xxxProcessed, $state->xxxTotal, MemoryDebug::getMemoryUsage());
                    $output->writeln($msg);
                    $state->pushDebugMessage($msg);
                }
                yield Stage::Xxx;
            }
        }

        $state->timings['xxx'] = microtime(true) - $t0;

        $output->writeln(sprintf(
            '<info>phpdup</info> xxx %d item(s)',
            $state->xxxProcessed,
        ));
    }
}
```

**`YIELD_EVERY` sizing**: 16 for IO-bound per-item work (Scanning), 32 for medium (Preprocess), 256 for tight CPU loops (Cluster), 4 for heavy per-cluster work (Refactor). Pick the same bucket as the closest neighbor stage.

**Verify**: `vendor/bin/phpstan analyse src/Pipeline/Stages/XxxStage.php` and `vendor/bin/psalm` both clean.

### Step 5 — Thread the stage into `Command::execute()`'s `$buildPipeline` closure

Edit `src/Cli/Command.php`. Find the `$buildPipeline = static function (?ProgressListener $listener) use (...)` (around line 382). Insert the new stage **in canonical order**:

```php
return new Pipeline(
    stages: [
        new ScanningStage($listener),
        new PreprocessStage($useCache, $showStats, $listener, $maxMemoryMb),
        new XxxStage($xxxArg, $listener),          // ← new
        new ClusterStage($exactOnly, $maxMemoryMb, $listener),
        new RefactorStage($useCache, $listener),
        new ReportStage(/* … */),
    ],
    stopAfter: $stopAfter,
    listener:  $listener,
);
```

If the stage needs a CLI flag, add it via the `add-cli-flag` skill in a separate pass — thread it through `ConfigLoader` → `Config` → into the closure's `use(...)` list.

Add `use Phpdup\Pipeline\Stages\XxxStage;` to the imports.

**Verify**: `bin/phpdup analyze tests/Fixtures/exact --no-cache` runs and shows the new stage in verbose output.

### Step 6 — Add a unit test at `tests/Unit/Pipeline/Stages/XxxStageTest.php`

Mirror `tests/Unit/Pipeline/Stages/` neighbors. Use `Symfony\Component\Console\Output\NullOutput` and a hand-built `PipelineState`:

```php
<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\XxxStage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class XxxStageTest extends TestCase
{
    public function testYieldsStageEnumDuringIter(): void
    {
        $state = new PipelineState(new Config(/* minimal */));
        $state->blocks = [/* fixtures */];
        $stage = new XxxStage(/* args */);

        $yielded = iterator_to_array($stage->iter($state, new NullOutput()), false);

        self::assertNotEmpty($yielded);
        self::assertContainsOnly(Stage::class, $yielded);
        self::assertSame(Stage::Xxx, $yielded[0]);
    }

    public function testRespectsCancelled(): void
    {
        $state = new PipelineState(new Config(/* … */));
        $state->cancelled = true;
        (new XxxStage(/* … */))->run($state, new NullOutput());
        self::assertSame(0, $state->xxxProcessed);
    }
}
```

**Verify**: `vendor/bin/phpunit --testsuite Unit --filter XxxStage` is green; `failOnWarning=true` and `failOnNotice=true` are set in `phpunit.xml`, so any notice fails the test.

### Step 7 — Refresh golden snapshots only if output changed

If the new stage alters the final `Report` shape (counts, clusters, etc.), refresh:

```bash
UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden
```

Then review the `tests/Golden/*.json` diff and commit only changes that make sense. If you're confused by what changed, the stage may have a bug.

### Step 8 — Run the full QA gate

```bash
vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/psalm
```

All three must pass cleanly with no new baseline entries. `psalm-baseline.xml` is for legacy debt — never add to it for new code.

## Examples

### User says: "Add an indexing stage between preprocess and cluster that builds a BlockIndex up-front"

Actions:
1. Add `case Indexing = 'indexing';` to `src/Pipeline/Stage.php` with label `'Indexing'`, index `2`, and renumber Clustering→3, Refactoring→4, Reporting→5.
2. Add `public ?BlockIndex $index = null;` already exists — reuse it. Add `'index' => 0.0,` to `$state->timings`.
3. Existing `ProgressListener` has no fitting hook — add `onBlockIndexed(int $indexed, int $total): void;` plus a no-op in `NullProgressListener`.
4. Create `src/Pipeline/Stages/IndexingStage.php` using the scaffold; `YIELD_EVERY = 64`; build the index from `$state->blocks` into `$state->index`.
5. Insert `new IndexingStage($listener),` between `PreprocessStage` and `ClusterStage` in `Command.php`'s `$buildPipeline` closure.
6. Add `tests/Unit/Pipeline/Stages/IndexingStageTest.php` with two cases: yields `Stage::Indexing`, populates `$state->index`.
7. Refresh goldens if cluster ordering changed; run full QA.

Result: A new live-progress phase visible in the TUI; `bin/phpdup analyze --stage indexing src/` halts after building the index.

## Common Issues

- **`Fatal error: Type Phpdup\Pipeline\Stage::Xxx is not defined`** → You forgot Step 1. Add the case to the `Stage` enum and renumber `index()`.
- **TUI freezes during the new stage** → `YIELD_EVERY` is too high or you forgot to `yield Stage::Xxx;` inside the loop. Cut the constant in half and confirm the `yield` is inside the `if (++$sinceYield >= self::YIELD_EVERY)` block, not after it.
- **`InvalidArgumentException: Generator must yield Stage values`** (or similar TUI repaint failure) → You yielded an int or string. Always `yield Stage::Xxx;` — the driver in `Pipeline::iter()` returns the value verbatim to the TUI.
- **Psalm error: `Argument 1 of XxxStage::__construct expects ProgressListener, null provided`** → Constructor param must be `?ProgressListener $listener = null` and assigned via `$listener ?? new NullProgressListener()`. See `ScanningStage.php:22-25`.
- **PHPStan: `Property PipelineState::$xxxs has no specified type`** → Add `/** @var list<XxxRecord> */` PHPDoc above the property. PHPStan level 6 requires it for typed arrays.
- **Golden snapshots diff with no logical reason** → The new stage is non-deterministically ordering output. Sort outputs before writing to `$state->xxxs` (see `ScanningStage` calling `sort($files)` at line 70).
- **`Stop after stage 'xxx' did not halt`** → `Pipeline::iter()` matches on `Stage` enum value. Verify `--stage xxx` maps to the new enum case in `ConfigLoader`'s stage parser.
- **Stage runs twice in tests** → You implemented `run()` to do work directly *and* `iter()` did the same work. `run()` MUST be just `foreach ($this->iter($state, $output) as $_) {}` for cooperative stages.