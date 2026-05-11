---
name: add-reporter
description: Adds a new output format under src/Reporting/ as a final class with writeTo(Report, string): void plus a pure build(Report) helper, then wires it through src/Pipeline/Stages/ReportStage.php and adds a matching --<name> flag in src/Cli/Command.php (with grouped-help entry and $reportArgs threading). Use when the user says 'add reporter', 'new output format', 'export to <format>' (e.g. junit, ndjson, yaml, xml), or when adding a file to src/Reporting/. Capabilities: scaffolding the final reporter class, registering ReportStage constructor + run() emit, adding the CLI flag, threading $reportArgs, and creating tests/Unit/Reporting/<Name>ReporterTest.php with a synthetic Report. Do NOT use for CliReporter verbosity tweaks (those go through --summary-only / --clusters / VERBOSITY_* constants), HTML/CSS-only changes inside HtmlReporter, new cluster sort keys (see src/Reporting/ClusterSort.php), or analyzers under src/Reporting/Coherence*/Architecture analyzers.
---

# add-reporter

Scaffolds a new output format reporter under `src/Reporting/`, wires it into the reporting stage, and exposes it via a CLI flag — mirroring the 12 existing reporters (JSON, HTML, SARIF, GitLab SAST, Checkstyle, CSV, Prometheus, Timeseries, Graphviz, PlantUML, RefactorPatch, RefactorTest).

## Critical

- The reporter MUST be `final class <Name>Reporter` in namespace `Phpdup\Reporting`. No inheritance, no abstract base.
- The public entrypoint MUST be `public function writeTo(Report $report, string $file): void`. The actual rendering MUST live in a separate pure method (`build(Report $report): string` for text formats, `build(Report $report): array` for JSON-shaped formats, or `buildLine(Report $report): string` for JSONL/streaming). Tests call the pure method — they do NOT touch the filesystem.
- `writeTo()` MUST create the parent directory with `@mkdir($dir, 0o775, true)` before writing — see `JsonReporter::writeTo`, `CsvReporter::writeTo`.
- After writing, `ReportStage::run()` MUST emit `<info>phpdup</info> <format> report → <file>` to the `OutputInterface`. Keep the wording consistent (lowercase format name, arrow `→`).
- PHP 8.1 floor: every file starts with `declare(strict_types=1);`. Use constructor promotion + `readonly` for any reporter-local config (see `CliReporter`).
- For any ANSI styling in CLI-bound output, gate `Style::new()->foreground(...)` behind `$output->isDecorated()` — `Theme::plain()` does NOT neutralize raw `Style` calls.
- Do NOT modify `composer.lock` (gitignored) and do NOT widen `phpstan.neon` / `psalm.xml` scope beyond `src/`.

## Instructions

### 1. Pick the reporter name and output target

Decide a kebab-case format name (e.g. `junit`, `ndjson`, `yaml`). The class is `<PascalCase>Reporter`. The CLI flag is `--<kebab-case>`. The output target is either a single FILE or a DIR (directory reporters use `writeDir`/`writeTo($report, $dir)` and emit `... → DIR/`; see `DiffReporter`, `HtmlReporter`, `RefactorPatchReporter`).

Verify the name does not already exist: `vendor/bin/phpunit --filter=<Name>Reporter` returns no matches and `ls src/Reporting/<Name>Reporter.php` returns no file.

### 2. Create `src/Reporting/<Name>Reporter.php`

Copy the structural skeleton from a comparable existing reporter:

- Pure text output → mirror `src/Reporting/CsvReporter.php`.
- Structured JSON/array output → mirror `src/Reporting/JsonReporter.php` (has both `build(): array` and `writeTo()` that json_encodes).
- XML → mirror `src/Reporting/CheckstyleReporter.php` (uses `DOMDocument`).
- Append-only JSONL → mirror `src/Reporting/TimeseriesReporter.php` (`buildLine` + append).

Required shape:

```php
<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

final class <Name>Reporter
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
        // Iterate $report->clusters → $cluster->members ($member is a Block).
        // Use $cluster->id, $cluster->similarity, $cluster->confidence,
        // $cluster->impact, $cluster->signature, $cluster->patternTags,
        // $cluster->outlierMemberIds, $cluster->architecturalFindings.
        // For each $member: $member->file, $member->range->start/->end,
        // $member->kind, $member->namespace, $member->class, $member->name.
    }
}
```

Verify before next step: `vendor/bin/phpstan analyse src/Reporting/<Name>Reporter.php` passes at level 6, and `vendor/bin/psalm src/Reporting/<Name>Reporter.php` is clean (no new entries in `psalm-baseline.xml` — fix typed-array PHPDoc instead).

### 3. Wire into `src/Pipeline/Stages/ReportStage.php`

Three edits, in order:

a) Add the import alphabetically among the other reporters near the top of the file:

```php
use Phpdup\Reporting\<Name>Reporter;
```

b) Add a constructor parameter following the existing nullable-string-file convention. Append to the constructor parameter list (keep `refactorPatchDir`/`refactorTestsDir` last if you are adding a single-file reporter):

```php
private readonly ?string $<name>File = null,
```

(For DIR reporters use `$<name>Dir` and emit `... → {$dir}/`.)

c) Add the emit block inside `run()` near the bottom — right above the closing brace, matching the existing pattern:

```php
if ($this-><name>File !== null) {
    (new <Name>Reporter())->writeTo($report, $this-><name>File);
    $output->writeln("<info>phpdup</info> <name> report → {$this-><name>File}");
}
```

Verify: `vendor/bin/phpunit --testsuite Unit --filter=ReportStage` passes (no test exists per stage, but the stage is exercised transitively by Golden).

### 4. Add the CLI flag in `src/Cli/Command.php`

Three edits, in order:

a) Inside `configure()` under the `// ── Output / reports ──` block, append:

```php
->addOption('<name>', null, InputOption::VALUE_REQUIRED, 'Write <Format> report to FILE')
```

Keep the description style consistent with neighbours (terse, capital format name, mention FILE/DIR target, mention any rendering hint where appropriate — e.g. `--render with X`).

b) Update the grouped-help heredoc in `buildGroupedHelp()` — add `--<name>` to the `<comment>Output / reports</comment>` list (around line 123-128), preserving line-wrapping with `, ` separators.

c) Inside `execute()`, add to the `$reportArgs` array (matches the constructor parameter name you chose in step 3b):

```php
'<name>File'      => $input->getOption('<name>'),
```

Then add to the `new ReportStage(...)` call in the `$buildPipeline` closure with the matching named-argument syntax used by neighbours:

```php
<name>File:      $reportArgs['<name>File'],
```

Verify: `bin/phpdup analyze --help | grep -- --<name>` shows the flag and `bin/phpdup analyze src --<name> /tmp/out.x` produces a file at `/tmp/out.x` and prints the `<info>phpdup</info> <name> report → /tmp/out.x` line.

### 5. Add a unit test at `tests/Unit/Reporting/<Name>ReporterTest.php`

Namespace `Phpdup\Tests\Unit\Reporting`. Build a synthetic `Report` with hand-rolled `Cluster` + `Block` instances — do NOT run the pipeline. Use `Symfony\Component\Console\Output\NullOutput` if any code path needs an `OutputInterface`. Mirror `tests/Unit/Reporting/CsvReporterTest.php`:

- One test for the empty-report case (`new Report(0, 0, 0, [], Config::defaults([]))`) — assert the minimal valid output (header line, empty JSON array, etc.).
- One test for a populated report — assert the shape and field counts.
- One test for any escaping/round-tripping invariants the format has (e.g. CSV quoting, XML entity escaping, JSON encoding).

Verify: `vendor/bin/phpunit --testsuite Unit --filter=<Name>ReporterTest` green. `phpunit.xml` has `failOnWarning` AND `failOnNotice` ON — any deprecation fails the build; fix the root cause, do not suppress.

### 6. Run the full QA gate before claiming done

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse
vendor/bin/psalm
UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden   # only if reporter is invoked by default
vendor/bin/phpunit --testsuite Golden                       # confirm no drift
```

Golden snapshots are only affected if your reporter is wired into the default CLI run (the current ones are NOT — they only fire when their flag is set). If you added a default-on reporter, refresh `tests/Golden/*.json` with `UPDATE_SNAPSHOTS=1` and review the diff before committing.

### 7. Update docs (when adding a public-facing flag)

- `docs/CLI.md` — add the flag under the Output / reports section.
- `docs/config-schema.json` — only if the flag is also exposed via `phpdup.json` config (most reporters are CLI-flag-only and do NOT need a config-schema entry; check whether `Config` has a matching property first).

Skip if the reporter is CLI-only and `src/Cli/Config.php` has no corresponding field.

## Examples

**User says:** "Add a JUnit XML reporter so we can post phpdup runs to Jenkins."

**Actions taken:**
1. Create `src/Reporting/JunitReporter.php` — `final class JunitReporter` with `writeTo(Report, string)` building XML via `DOMDocument` (mirroring `CheckstyleReporter`), plus pure `build(Report): string` returning the serialized XML.
2. Add `use Phpdup\Reporting\JunitReporter;` to `src/Pipeline/Stages/ReportStage.php`, add `private readonly ?string $junitFile = null,` to the constructor, and add the conditional emit block inside `run()` printing `<info>phpdup</info> junit report → {$file}`.
3. In `src/Cli/Command.php` `configure()`, add `->addOption('junit', null, InputOption::VALUE_REQUIRED, 'Write JUnit XML report to FILE')`. Update `buildGroupedHelp()` to list `--junit` under Output / reports. Add `'junitFile' => $input->getOption('junit'),` to `$reportArgs` and thread it as `junitFile: $reportArgs['junitFile'],` into the `new ReportStage(...)` named-args call.
4. Create `tests/Unit/Reporting/JunitReporterTest.php` with three cases: empty report → `<testsuites/>`, populated report → one `<testsuite>` per cluster, escaping → `&`/`<`/`>` round-trip safely.
5. Run `vendor/bin/phpunit --testsuite Unit --filter=JunitReporterTest` (green), `vendor/bin/phpstan analyse` (clean), `vendor/bin/psalm` (clean), then `bin/phpdup analyze src --junit /tmp/junit.xml` to confirm the integration end-to-end.

**Result:** New flag `--junit FILE` available; `bin/phpdup analyze src --junit /tmp/j.xml` writes JUnit XML, prints `phpdup junit report → /tmp/j.xml`, and is covered by 3 unit tests with no PHPStan/Psalm regressions.

## Common Issues

- **`Error: Cannot use Phpdup\Reporting\Report as Report because the name is already in use`** — `Report` is already imported indirectly inside `Phpdup\Reporting` namespace. Drop the `use Phpdup\Reporting\Report;` line; you are already in the same namespace.
- **`Class Phpdup\Reporting\Report not found`** when added from a test** — your test sits in `Phpdup\Tests\Unit\Reporting` (different namespace), so it DOES need `use Phpdup\Reporting\Report;` and `use Phpdup\Reporting\<Name>Reporter;` explicitly.
- **`Notice: Undefined property: Cluster::$<x>`** — see `src/Clustering/Cluster.php` for the canonical property list. Common ones: `id`, `members`, `similarity`, `confidence`, `impact`, `signature`, `patternTags`, `outlierMemberIds`, `architecturalFindings`. Anything else does not exist.
- **PHPUnit fails with `failOnNotice`** — `phpunit.xml` treats notices as failures. The usual culprits are `@` swallowing real errors (don't suppress except for `@mkdir`/`@is_dir` per existing pattern) and accessing array keys without checks. Fix the root cause.
- **`--<name>` option set but no file written** — confirm step 3c added the emit block AND step 4c added BOTH the `$reportArgs` array entry AND the `<name>File: $reportArgs['<name>File'],` named-arg in the `new ReportStage(...)` call. The Golden test will not exercise the flag, so manual `bin/phpdup analyze src --<name> /tmp/out.x` is required.
- **`Psalm: PossiblyNullPropertyAccess on Cluster::$signature`** — `$cluster->signature` is `?string`; coalesce with `?? ''` like `CsvReporter::row` does.
- **`Theme::plain()` still emits ANSI escapes in CLI tests** — wrap `Style::new()->foreground(...)` in `if ($output->isDecorated()) { ... }`. See `CliReporter::renderTags`.
- **`SugarCraft` styling rendered in pipe output** — same fix: check `$output->isDecorated()` before applying any `Style::new()` calls. Tests using `BufferedOutput` default `isDecorated()` to false.