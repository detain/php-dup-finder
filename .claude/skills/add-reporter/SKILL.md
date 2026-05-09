---
name: add-reporter
description: Adds a new output format under src/Reporting/ following the writeTo + build pattern used by all 12 existing reporters. Wires it into src/Pipeline/Stages/ReportStage.php and adds a --<name> flag in src/Cli/Command.php with matching grouped-help entry. Use when user says 'add reporter', 'new output format', 'export to <format>' (e.g. junit, xml, yaml, ndjson), or adds a file to src/Reporting/. Capabilities: scaffolding the reporter class, wiring constructor injection, registering CLI flag, adding $reportArgs key, adding the unit test stub. Do NOT use for modifying CliReporter verbosity (use --summary-only/--clusters), HTML/CSS-only tweaks to HtmlReporter, or new sort keys (see ClusterSort).
paths:
  - src/Reporting/**
  - src/Pipeline/Stages/ReportStage.php
  - src/Cli/Command.php
  - tests/Unit/Reporting/**
---
# add-reporter — new output format for phpdup

## Critical

- Every new reporter MUST be a single `final class` under `Phpdup\Reporting` in `src/Reporting/`. No interface, no abstract base — the project deliberately uses duck-typing across reporters.
- Every reporter MUST expose **two public methods**: `writeTo(Report $report, string $file): void` and `build(Report $report): string`. `writeTo` is what `ReportStage` calls; `build` is what tests call. Diff-style multi-file reporters (see `src/Reporting/DiffReporter.php`) use `writeDir`/`writePatch` instead — only deviate from `writeTo` if the format genuinely produces multiple files.
- `writeTo()` MUST `mkdir` its parent directory before `file_put_contents`. The exact 3-line preamble is non-negotiable (see `src/Reporting/CsvReporter.php` lines 28–35).
- `Report` is consumed read-only. Do NOT mutate `$report->clusters`, do NOT call the `Ranker` from inside the reporter — ranking already happened in `ReportStage::run` before reporters fire.
- Wire the new reporter into BOTH `src/Pipeline/Stages/ReportStage.php` (constructor + `run()` block) AND `src/Cli/Command.php` (`addOption` + `$reportArgs` array + `buildPipeline` closure). Missing any one means the flag exists but does nothing, or the flag silently rejects.
- `composer.lock` is gitignored — do not stage it. PHP 8.1 floor (no standalone `:null` returns).

## Instructions

### Step 1 — Pick the reporter name and CLI flag

Use `<Format>Reporter` as the class name (PascalCase) and `--<format>` as the CLI flag (lowercase, hyphenated). Examples that already exist: `src/Reporting/CsvReporter.php`/`--csv`, `src/Reporting/SarifReporter.php`/`--sarif`, `src/Reporting/GitLabSastReporter.php`/`--gitlab-sast`, `src/Reporting/PrometheusReporter.php`/`--prometheus`. Decide whether the output is a single file (default — use `writeTo`) or a directory of files (use `writeDir` like `src/Reporting/DiffReporter.php`).

**Verify before next step:** Confirm the new reporter file does NOT already exist under `src/Reporting/`. If a similar format exists, extend it rather than duplicating.

### Step 2 — Create the reporter class under `src/Reporting/`

Use `src/Reporting/CsvReporter.php` as the template — it is the cleanest example of the pattern. The skeleton:

```php
<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * One-paragraph description of the format and its intended consumer
 * (CI tool, dashboard, BI ingest, etc.). Cite the format spec URL.
 */
final class JunitReporter
{
    public function writeTo(Report $report, string $file): void
    {
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        file_put_contents($file, $this->build($report));
    }

    public function build(Report $report): string
    {
        // walk $report->clusters, each Cluster has:
        //   ->id, ->members (list<Block>), ->similarity, ->confidence,
        //   ->impact, ->signature (?string), ->patternTags (list<string>)
        // each Block has:
        //   ->file, ->range->start, ->range->end, ->kind, ->namespace,
        //   ->class, ->name
        // Top-level: $report->files, ->blocks, ->parseErrors,
        //            ->totalDuplicatedLines(), ->config
    }
}
```

Do NOT inject `Config` separately — `$report->config` is already available. Do NOT add a constructor unless the reporter has tunable knobs (most don't; `src/Reporting/CliReporter.php` is the exception with `$verbosity`).

**Verify before next step:** `vendor/bin/phpstan analyse` and `vendor/bin/psalm` both clean for the new file.

### Step 3 — Wire into `src/Pipeline/Stages/ReportStage.php`

Three edits in this single file (depends on Step 2):

1. Add the `use` import in the top block (alphabetical), e.g. `use Phpdup\Reporting\JunitReporter;`.
2. Add a constructor parameter — copy the shape of an existing single-file reporter parameter:

   ```php
   private readonly ?string $junitFile = null,
   ```

   Place it next to a thematically-related parameter (CSV/Prometheus/Timeseries are grouped together as machine-ingest formats; SARIF/Checkstyle/GitLab-SAST are grouped as static-analysis tooling formats).
3. Inside `run()`, append a guarded block at the bottom of the existing `if ($this->xFile !== null) { ... }` chain (currently lines 88–135):

   ```php
   if ($this->junitFile !== null) {
       (new JunitReporter())->writeTo($report, $this->junitFile);
       $output->writeln("<info>phpdup</info> junit report → {$this->junitFile}");
   }
   ```

**Verify before next step:** `vendor/bin/phpunit --testsuite Unit` still green.

### Step 4 — Add the CLI flag in `src/Cli/Command.php`

Four edits in this single file (depends on Step 3):

1. In the `configure()` method under the Output / reports block (around lines 54–71), add:

   ```php
   ->addOption('junit', null, InputOption::VALUE_REQUIRED, 'Write JUnit XML report to FILE for CI consumption')
   ```

   Order matters for `--help` grouping — keep machine-ingest formats together.
2. In `buildGroupedHelp()` (lines 95–125), add `--junit` to the Output / reports section so the cheat-sheet stays in sync. Example: tuck it next to `--csv` if it's a flat tabular format, or next to `--sarif` if it's CI-tool-targeted.
3. In `execute()` inside the `$reportArgs` array (lines 304–319), add:

   ```php
   'junitFile' => $input->getOption('junit'),
   ```
4. In the `buildPipeline` closure (lines 324–353) inside `new ReportStage(...)`, add the named argument:

   ```php
   junitFile: $reportArgs['junitFile'],
   ```

**Verify before next step:** `bin/phpdup analyze --help` shows `--junit` listed under Output / reports. `bin/phpdup analyze tests/Fixtures --junit=/tmp/out.xml` produces the file.

### Step 5 — Add a unit test under `tests/Unit/Reporting/`

Mirror `tests/Unit/Reporting/CsvReporterTest.php` (depends on Step 2). Two tests at minimum:

1. `testEmptyReportEmitsValidOutput` — call `(new JunitReporter())->build(new Report(0, 0, 0, [], Config::defaults([])))` and assert the result parses/validates as that format (e.g. `json_decode` for JSON, `simplexml_load_string` for XML).
2. `testFixtureReportProducesExpectedShape` — use the fixture-pipeline pattern from `tests/Unit/Reporting/CsvReporterTest.php` `buildReport()` lines 66–87 (run `ScanningStage` → `PreprocessStage` → `ClusterStage(exactOnly: true)` → `RefactorStage` → wrap in `Report`). Use `tests/Fixtures/sql` as the fixture root (small, deterministic, has known clusters).

Namespace MUST be `Phpdup\Tests\Unit\Reporting`. Class MUST be `final` and extend `PHPUnit\Framework\TestCase`.

**Verify before next step:** `vendor/bin/phpunit --testsuite Unit --filter JunitReporterTest` passes.

### Step 6 — Final verification gates

Run all three before declaring done:

```bash
vendor/bin/phpunit                 # all suites green
vendor/bin/phpstan analyse         # level 6, src/ only — must be clean
vendor/bin/psalm                   # errorLevel 6, src/ only — must be clean
bin/phpdup analyze tests/Fixtures/sql --junit=/tmp/out.xml   # smoke test
```

If any golden tests under `tests/Golden/` reference reporter output, refresh with `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden` and inspect the diff before staging.

## Examples

**User says:** "Add a JUnit XML reporter so we can publish duplicate clusters to Jenkins as test failures."

**Actions taken:**
1. Create the JUnit reporter class under `src/Reporting/` with `writeTo` + `build`, building `<testsuite name="phpdup">` with one `<testcase>` per cluster wrapping a `<failure type="duplicate">` containing the member file:line list.
2. Edit `src/Pipeline/Stages/ReportStage.php`: add `use Phpdup\Reporting\JunitReporter;`, constructor param `?string $junitFile = null`, and the `if ($this->junitFile !== null) { ... }` block that calls `writeTo` and prints `<info>phpdup</info> junit report → ...`.
3. Edit `src/Cli/Command.php`: add `->addOption('junit', null, InputOption::VALUE_REQUIRED, 'Write JUnit XML to FILE for CI consumption (Jenkins/GitLab)')`, list `--junit` in `buildGroupedHelp()` under Output / reports, add `'junitFile' => $input->getOption('junit')` to `$reportArgs`, add `junitFile: $reportArgs['junitFile']` to the `new ReportStage(...)` call.
4. Create `tests/Unit/Reporting/JunitReporterTest.php` with the two-test pattern from the CSV reporter test, asserting `simplexml_load_string($xml) !== false` and that `count($xml->testcase) === count($report->clusters)`.
5. Run `vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/psalm`.

**Result:** `bin/phpdup analyze src/ --junit=build/phpdup.junit.xml` writes the file; CI consumes it like any other test report.

## Common Issues

- **`Class "Phpdup\Reporting\JunitReporter" not found`** when running `bin/phpdup`: the autoloader is stale. Run `composer dump-autoload`. PSR-4 root is `Phpdup\\` → `src/` per `composer.json`; the file path MUST be under `src/Reporting/` (case-exact).
- **Flag is accepted but the file is never written**: you forgot one of the four wire-ups in Step 4. Most commonly the `buildPipeline` closure's named-argument call is missing, so the constructor parameter stays at its default `null`. Grep `src/Cli/Command.php` for the existing flag (e.g. `csvFile`) — every reference site needs a sibling for your new one.
- **PHPStan: `Property Phpdup\Pipeline\Stages\ReportStage::$junitFile is never read, only written`**: the `if ($this->junitFile !== null)` block in `run()` is missing. Step 3.3.
- **`InvalidArgumentException` from `Symfony\Component\Console`** at `bin/phpdup --help`: duplicate `addOption('junit')` — you added it twice. Each option name is unique across the command.
- **Test fails with `file_put_contents(...): Failed to open stream: No such file or directory`**: the `mkdir` preamble at the top of `writeTo` is missing or guards the wrong condition. Copy lines 28–35 of `src/Reporting/CsvReporter.php` verbatim.
- **Golden test diff after wiring**: `tests/Golden/*.json` snapshots may include reporter-list output. Run `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`, inspect `git diff tests/Golden/`, stage only if the new lines reference your reporter.
- **`bin/phpdup analyze --validate-config` rejects a config with the new key**: if you also added a config-file knob, update `src/Cli/ConfigLoader.php`'s schema validator. For CLI-flag-only reporters (the common case) no config change is needed — leave `src/Cli/ConfigLoader.php` alone.
