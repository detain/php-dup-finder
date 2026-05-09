---
name: add-pipeline-stage
description: Adds a new pipeline stage to phpdup's 5-stage cooperative pipeline (src/Pipeline/Stages/). Use when the user says 'add pipeline stage', 'new pipeline phase', 'extend the pipeline', 'add a stage between X and Y', or adds a new Stage enum case. Handles StageInterface vs CooperativeStageInterface choice, Stage enum updates, PipelineState field additions, ProgressListener wiring, and threading the stage into Command::execute's $buildPipeline closure with matching unit tests. Do NOT use for modifying existing stages (edit them in place), adding reporters (those slot into ReportStage), or adding new Clusterer phases (those go inside ClusterStage::iter()).
---
# Add a Pipeline Stage

phpdup runs a fixed-order, cooperative 5-stage pipeline. Adding a stage means: declaring it in the `Stage` enum, writing the stage class under `src/Pipeline/Stages/`, mutating `src/Pipeline/PipelineState.php`, and wiring it into `src/Cli/Command.php`'s `$buildPipeline` closure. This skill replicates the exact patterns used by `src/Pipeline/Stages/ScanningStage.php`, `src/Pipeline/Stages/ClusterStage.php`, and `src/Pipeline/Stages/ReportStage.php`.

## Critical

- **Stage order is load-bearing.** `Pipeline::iter()` walks `$stages` in array order; downstream stages read state written by upstream stages. Never insert a stage that reads `$state->blocks` before `PreprocessStage`, or `$state->clusters` before `RefactorStage`. Confirm prerequisites by reading the upstream stage's writes to `src/Pipeline/PipelineState.php`.
- **Cooperative stages MUST yield `Stage::X` values, never anything else.** The `iter()` return type is `\Generator<int, Stage>` (see `src/Pipeline/CooperativeStageInterface.php`). Yielding a string or int will pass static analysis but break the TUI driver.
- **Stage classes are `final` with `declare(strict_types=1);`** and namespace `Phpdup\Pipeline\Stages`. Match this exactly — Psalm runs at errorLevel 6.
- **Never branch on whether a `ProgressListener` is null.** Always default-construct a `NullProgressListener` in the constructor and call methods on `$this->listener` unconditionally. See `src/Pipeline/Stages/ScanningStage.php` constructor for the canonical pattern.
- **`composer.lock` is gitignored** (line 2 of `.gitignore`) — never `git add` it.
- **PHP 8.1 floor.** No `:null` standalone return type. Use `:void`, `?T`, or union types.

## Instructions

### Step 1 — Decide cooperative vs synchronous

Use `CooperativeStageInterface` (yields mid-stage so the TUI can repaint) when the work is a loop with > ~50ms total runtime per file/unit:

- File walks (`src/Pipeline/Stages/ScanningStage.php`)
- Per-file or per-block work (`src/Pipeline/Stages/PreprocessStage.php`)
- Pair/edge streaming (`src/Pipeline/Stages/ClusterStage.php`)
- Per-cluster synthesis (`src/Pipeline/Stages/RefactorStage.php`)

Use plain `StageInterface` (one synchronous chunk) for fast terminal work that doesn't benefit from a progress repaint, like emitting reports (`src/Pipeline/Stages/ReportStage.php`).

**Verify before proceeding:** open the closest existing analogue under `src/Pipeline/Stages/` and confirm it implements the interface you chose.

### Step 2 — Add the case to the `Stage` enum

Edit `src/Pipeline/Stage.php`. Add the new case in the spot it belongs in the pipeline order, then update **all four** members: the case list, `label()`, `index()` (sequential, no gaps), and `ordered()`.

Example — inserting `Validating` between `Clustering` and `Refactoring`:

```php
case Validating = 'validating';
// label():     self::Validating => 'Validating',
// index():     self::Validating => 3,  (and bump Refactoring to 4, Reporting to 5)
// ordered():   include self::Validating in the right slot
```

**Verify:** run `vendor/bin/phpunit tests/Unit/Pipeline/StageTest.php` — it asserts `index()` matches `ordered()` position. Fix any failures before continuing.

### Step 3 — Add fields to `src/Pipeline/PipelineState.php`

Add public mutable fields the new stage will write and downstream stages will read. Follow the existing field conventions:

- Counters: `public int $fooCount = 0;`
- Collections: typed lists with PHPDoc `/** @var list<Foo> */`
- Optional outputs: `public ?Foo $foo = null;`
- Add a stage timing key to `$timings` if you'll record `microtime(true)` deltas (see `src/Pipeline/Stages/ClusterStage.php`'s `$state->timings['cluster']` pattern).

Do **not** introduce getters/setters — `src/Pipeline/PipelineState.php` is a deliberate plain mutable record.

**Verify:** run `vendor/bin/psalm src/Pipeline/PipelineState.php` — it should be clean.

### Step 4 — Add a `ProgressListener` method (only if you need TUI updates)

If the stage emits per-unit progress the TUI should reflect, add a method to `src/Pipeline/ProgressListener.php` (e.g. `onValidatedBlock(int $validated, int $total): void;`). Then implement it as a no-op in `src/Pipeline/NullProgressListener.php` and as a state mutation in `src/Tui/PhpdupModel.php` (search for an existing `onPairScored` analogue).

Skip this step if the stage only needs `onStageStart`/`onStageEnd` (those fire automatically from `Pipeline::iter()`).

**Verify:** `vendor/bin/phpstan analyse` — it will flag any missing implementation in classes that implement `ProgressListener`.

### Step 5 — Write the stage class

Create the new stage file under `src/Pipeline/Stages/`. Use the cooperative template (drop `iter()` and call work directly in `run()` if synchronous):

```php
<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Pipeline\CooperativeStageInterface;
use Phpdup\Pipeline\NullProgressListener;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Symfony\Component\Console\Output\OutputInterface;

final class ValidatingStage implements CooperativeStageInterface
{
    private const YIELD_INTERVAL = 16;

    private readonly ProgressListener $listener;

    public function __construct(
        private readonly bool $someFlag,
        ?ProgressListener $listener = null,
    ) {
        $this->listener = $listener ?? new NullProgressListener();
    }

    public function name(): Stage
    {
        return Stage::Validating;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        foreach ($this->iter($state, $output) as $_) {
            // synchronous drain — pattern from ScanningStage::run
        }
    }

    public function iter(PipelineState $state, OutputInterface $output): \Generator
    {
        if (!$state->blocks) {
            return; // no-op when prerequisite stage produced nothing — see ClusterStage line 48
        }

        $state->currentTask = 'Validating clusters';
        yield Stage::Validating; // pre-work frame so TUI shows the task label

        $sinceYield = 0;
        foreach ($state->clusters as $i => $cluster) {
            // ... do work, mutate $state ...
            $this->listener->onValidatedBlock($i + 1, count($state->clusters));
            if (++$sinceYield >= self::YIELD_INTERVAL) {
                $sinceYield = 0;
                yield Stage::Validating;
            }
        }

        $output->writeln(sprintf(
            '<info>phpdup</info> validated %d cluster(s)',
            count($state->clusters),
        ));
    }
}
```

Key conventions copied from existing stages:
- Output prefix is exactly `<info>phpdup</info>` (see every existing stage).
- Early-return via plain `return;` when prerequisite state is missing (`src/Pipeline/Stages/ClusterStage.php:48`, `src/Pipeline/Stages/ReportStage.php:52`).
- `currentTask` is set before each long sub-phase, then yielded so the TUI repaints (`src/Pipeline/Stages/ClusterStage.php:54`).
- Time-bracket the work with `microtime(true)` and stash deltas in `$state->timings['<key>']` only if `--stats` should report it.

**Verify:** `vendor/bin/psalm` and `vendor/bin/phpstan analyse` both clean for the new file.

### Step 6 — Wire it into `src/Cli/Command.php`

Find the `$buildPipeline` closure (around line 324). Add the new stage to the `stages: [...]` array in the correct order, mirroring how the surrounding stages are constructed. Pass `$listener` last (after positional config args), matching the signatures of existing stages:

```php
stages: [
    new ScanningStage($listener),
    new PreprocessStage($useCache, $showStats, $listener, $maxMemoryMb),
    new ClusterStage($exactOnly, $maxMemoryMb, $listener),
    new ValidatingStage($someFlag, $listener),  // ← new
    new RefactorStage($useCache, $listener),
    new ReportStage(...),
],
```

If the stage needs new CLI flags or config values, thread them through the command's configure method, `src/Cli/ConfigLoader.php`, and `src/Cli/Config.php` — but only if user-tunable. Hard-coded behaviour goes in the constructor as a literal.

**Verify:** `bin/phpdup analyze tests/Fixtures/sql --no-cache` runs end-to-end without errors and shows your stage's output line.

### Step 7 — Add the unit test

Create the test file under `tests/Unit/Pipeline/Stages/`. Follow the `tests/Unit/Pipeline/Stages/ScanningStageTest.php` shape exactly:

```php
<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ValidatingStage;
use Symfony\Component\Console\Output\NullOutput;

final class ValidatingStageTest extends TestCase
{
    public function testReportsName(): void
    {
        $this->assertSame(Stage::Validating, (new ValidatingStage(false))->name());
    }

    public function testNoOpOnEmptyInput(): void
    {
        $config = Config::defaults([__DIR__ . '/../../../Fixtures/sql']);
        $state  = new PipelineState($config);
        // do not populate $state->clusters

        (new ValidatingStage(false))->run($state, new NullOutput());

        $this->assertSame([], $state->clusters);
    }

    // Add a positive test: hand-build the prerequisite state, run the stage,
    // assert the post-state. Mirror ClusterStageTest for cooperative stages.
}
```

At minimum: a `testReportsName` and an empty-input no-op test. Add positive tests as needed. Use fixtures under `tests/Fixtures/`.

**Verify:** `vendor/bin/phpunit --testsuite Unit --filter ValidatingStage` passes.

### Step 8 — Run the full suite

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/psalm
```

If the new stage changes cluster output, refresh golden snapshots: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`. Diff the regenerated `tests/Golden/*.json` to confirm the changes are intentional before committing.

## Examples

### Example 1 — Adding a `ValidatingStage` between Clustering and Refactoring

**User says:** "Add a pipeline stage between clustering and refactoring that drops clusters whose minimum pairwise similarity is below 0.7."

**Actions taken:**
1. Add `Validating = 'validating'` to `Stage` enum at index 3; bump `Refactoring` to 4, `Reporting` to 5.
2. No new `src/Pipeline/PipelineState.php` fields needed — stage filters `$state->clusters` in place.
3. No new `ProgressListener` method needed — `onStageStart`/`onStageEnd` is enough for filter work.
4. Create the new stage file under `src/Pipeline/Stages/` implementing `StageInterface` (synchronous — filtering is fast). Constructor takes `float $minSimilarity`. `run()` does `$state->clusters = array_values(array_filter($state->clusters, fn($c) => $c->similarity >= $this->minSimilarity));` and writes one `<info>phpdup</info> dropped N cluster(s) below similarity 0.7` line.
5. In `src/Cli/Command.php`, slot `new ValidatingStage(0.7)` between `ClusterStage` and `RefactorStage`.
6. Add a `ValidatingStageTest` with `testReportsName`, `testFiltersBelowThreshold`, `testKeepsAtThreshold`.

**Result:** `bin/phpdup analyze tests/Fixtures/sql` shows the new stage line; `vendor/bin/phpunit` passes; `tests/Golden/*.json` either unchanged (if no fixture cluster fell below threshold) or refreshed deliberately.

## Common Issues

- **`ArgumentCountError: Too few arguments to Pipeline::__construct`** — you forgot a positional arg in `src/Cli/Command.php`'s `new Pipeline(stages: [...])`. Open `src/Pipeline/Pipeline.php`, check the constructor signature; the call uses named args (`stages:`), so a typo in the name fails silently as a missing positional.
- **TUI freezes mid-stage / progress bar doesn't move** — your cooperative stage isn't yielding often enough, or you're yielding the wrong type. Confirm: (a) `iter()` returns `\Generator<int, Stage>`, (b) every `yield` emits `Stage::YourCase`, never a string or int, (c) you yield at least every ~16 units of work. See `src/Pipeline/Stages/ScanningStage.php` `YIELD_INTERVAL`.
- **`StageTest::testIndexMatchesOrderedPosition` fails** — your `index()` values have a gap or duplicate after inserting the new case. Renumber sequentially from 0, and ensure `ordered()` returns the cases in the same order as `index()` claims.
- **`PipelineState::$foo` is undefined when downstream stage runs** — your stage's `run()` returned early (e.g. on empty input) without initialising `$state->foo`. Either initialise the field's default in `src/Pipeline/PipelineState.php`'s declaration (`public array $foo = [];`) or guarantee writes before any return. The `if (!$state->blocks) { return; }` early-return at `src/Pipeline/Stages/ClusterStage.php:48` works because `$state->clusters` defaults to `[]`.
- **Golden snapshot tests fail with diff in clusters JSON** — your stage changed cluster ranking or membership. Audit the diff: if intentional, run `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden` and commit the regenerated `tests/Golden/*.json` alongside the stage code. If unintentional, your stage is mutating shared state it shouldn't.
- **`composer.lock` shows up in `git status`** — `.gitignore` excludes it but a prior `git add -A` may have force-added it. Run `git rm --cached composer.lock` and re-stage the rest of your changes manually.
