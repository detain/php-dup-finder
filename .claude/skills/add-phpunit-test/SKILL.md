---
name: add-phpunit-test
description: Generates a PHPUnit 10.5 unit test under tests/Unit/<Subns>/<Class>Test.php that mirrors src/ layout, declares namespace Phpdup\Tests\Unit\<Sub>, uses Symfony NullOutput when invoking pipeline stages, and synthesizes Cluster/Block fixtures for reporter/ranker tests. Honors phpunit.xml's failOnWarning=true and failOnNotice=true. Use when the user says 'add test', 'write a unit test', 'cover this with phpunit', 'add phpunit coverage', or after creating a new src/ class with no matching test. Do NOT use for: golden snapshot tests under tests/Golden/ (those are regenerated with UPDATE_SNAPSHOTS=1), integration tests under tests/Integration/, or fixture .php files under tests/Fixtures/.
---
# Add a PHPUnit unit test

Generates a focused unit test that compiles cleanly with PHPUnit 10.5's strict failOnWarning/failOnNotice gates and mirrors the layout already used throughout `tests/Unit/`.

## Critical

- Test path **must** mirror the source path under `src/`: a class under `src/<Sub>/` gets a test under `tests/Unit/<Sub>/` with the suffix `Test` appended to the class name. Do not flatten or invent new directories.
- Namespace **must** be `Phpdup\Tests\Unit\<Subnamespace>` — the project does not use a `Tests\` PSR-4 root other than this prefix. Mismatched namespace causes PHPUnit to skip the class silently.
- Every test file starts with `<?php` then `declare(strict_types=1);` and the class **must** be declared `final`. All existing tests follow this — see `tests/Unit/Reporting/RankerTest.php`.
- `phpunit.xml` sets `failOnWarning="true"` and `failOnNotice="true"`. Any deprecation, undefined-index notice, or PHPUnit warning fails the suite. Cover all array keys you read; do not rely on `@` suppression.
- Do **not** add tests for golden-snapshot output. Snapshot diffs belong in `tests/Golden/` and are refreshed with `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`.
- Never stage `composer.lock` (gitignored).

## Instructions

1. **Locate the source class.** Read the target file under `src/` and note its namespace, public constructor signature, public methods you are testing, and any value objects it consumes (look at the imports). Verify the class exists before proceeding.

2. **Compute the destination path.** Strip `src/` and append the test suffix, then place under `tests/Unit/`:
   - `src/Reporting/JsonReporter.php` → `tests/Unit/Reporting/JsonReporterTest.php`
   - `src/Cli/ProjectProfileDetector.php` → `tests/Unit/Cli/ProjectProfileDetectorTest.php`
   Verify the parent directory exists with `Glob`; create it via `Write` if absent. Do not place tests anywhere other than under `tests/Unit/`.

3. **Write the file header verbatim** (no blank line between `<?php` and `declare`):
   ```php
   <?php
   declare(strict_types=1);

   namespace Phpdup\Tests\Unit\Reporting;

   use PHPUnit\Framework\TestCase;
   ```
   Then `use` the class under test (`Phpdup\<Sub>\<Class>`) and any collaborators. Declare the class as `final class <Class>Test extends TestCase`. Verify the namespace matches the directory before continuing.

4. **Pick the fixture strategy** based on what the class needs:

   - **Pure value/logic class** (no pipeline state): construct it directly with literals. Example pattern: `tests/Unit/Cli/ProjectProfileDetectorTest.php` — uses a private `mkproject()` helper that writes files into `sys_get_temp_dir() . '/phpdup-<feature>-' . uniqid()` and a `tearDown()` that removes them.

   - **Reporter/Ranker that consumes `Cluster[]`**: synthesize clusters with a private factory. Mirror `tests/Unit/Reporting/RankerTest.php` exactly — construct `Block` with:

     ```php
     new Block(
         file: 'test.php',
         range: new LineRange(1, 5),
         kind: 'method',
         namespace: null,
         class: null,
         name: 'm',
         ast: new Node\Stmt\Nop()
     )
     ```

     then assign `$b->size = $blockSize;`, then `new Cluster($id, $blocks, $similarity, $exact)`.

   - **End-to-end behavior over a fixture corpus**: drive the pipeline through `ScanningStage`, `PreprocessStage(useCache: false)`, `ClusterStage(exactOnly: true|false)`, `RefactorStage(useCache: false)` against a `tests/Fixtures/<name>/` directory. Mirror `tests/Unit/Reporting/JsonReporterTest.php` — copy the `buildReport()` helper as-is.

   Verify the chosen pattern matches an existing test before writing new helper code.

5. **Always pass `NullOutput` (or a `BufferedOutput` you assert on) to stages**:
   ```php
   use Symfony\Component\Console\Output\NullOutput;
   $out = new NullOutput();
   (new ScanningStage())->run($state, $out);
   ```
   For tests that assert on rendered output, use `BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false)` — see `tests/Unit/Reporting/CliReporterVerbosityTest.php`. Never pass `null`; the stages type-hint `OutputInterface`.

6. **Build `Config` correctly.** For defaults call `Config::defaults([$path])`. For overrides, use named arguments and fill **only** what you need; the constructor uses property promotion with defaults. The minimum override pattern (from `tests/Unit/Reporting/JsonReporterTest.php` `buildReport`):
   ```php
   new Config(
       paths: [$path],
       exclude: Config::defaults([])->exclude,
       minBlockSize: 8,
       maxDocumentFrequency: 0.01,
       minClusterImpact: 1,
       lazyAst: false,
   );
   ```
   Always pass `lazyAst: false` in tests so blocks retain their AST for downstream assertions.

7. **Name test methods `testXxxYyy()` describing observable behavior**, not implementation. Match the existing voice: `testEmptyReportProducesEmptyClustersArray`, `testRespectsExplicitSortByMembersAsc`, `testLaravelMarkerDetectsLaravel`. One assertion per behavior; multiple `assert*` calls per method are fine when they verify one logical claim.

8. **Use `assertSame` for scalars/arrays, `assertEquals` only for object equality** that needs `==`. Use `assertStringContainsString` for rendered text. Use `expectException(\RuntimeException::class)` + `expectExceptionMessageMatches('/regex/')` for error paths — see `tests/Unit/Cli/ProjectProfileDetectorTest.php` `testRegistryRejectsUnknownProfile`.

9. **Clean up tempfiles in `tearDown()` or `try/finally`.** Pattern A (multi-file): track paths in `private array $cleanup = []` and recursively unlink in `tearDown()` — see `tests/Unit/Cli/ProjectProfileDetectorTest.php`. Pattern B (single file): wrap in `try { ... } finally { @unlink($tmp); }` — see `tests/Unit/Reporting/JsonReporterTest.php` `testWriteToCreatesParseableJsonFile`.

10. **Run the new test in isolation, then the whole Unit suite**:
    ```bash
    vendor/bin/phpunit --testsuite Unit --filter <ClassName>Test
    vendor/bin/phpunit --testsuite Unit
    ```
    Verify both exit 0. A warning (e.g., undefined array key) will fail the run because of `failOnWarning=true`. Do not claim done until both commands pass.

11. **Run the static analyzers before reporting completion**:
    ```bash
    vendor/bin/phpstan analyse
    vendor/bin/psalm
    ```
    Both are configured for `src/` only, but added test code that touches src signatures will surface mismatches — verify clean output.

## Examples

**Example 1 — reporter test against a fixture corpus**

User: "Add a unit test for the new `MarkdownReporter` I just wrote in `src/Reporting/`."

Actions:
1. Read `src/Reporting/MarkdownReporter.php`; confirm it exposes `build(Report): string` or `writeTo(Report, string): void`.
2. Create `tests/Unit/Reporting/MarkdownReporterTest.php` with namespace `Phpdup\Tests\Unit\Reporting`.
3. Copy the `buildReport()` helper from `tests/Unit/Reporting/JsonReporterTest.php`.
4. Add `testEmptyReportRendersHeader()` (uses `new Report(0, 0, 0, [], Config::defaults([]))`) and `testFixtureReportListsClusters()` (uses `tests/Fixtures/sql` with `exactOnly: true`).
5. Run `vendor/bin/phpunit --filter MarkdownReporterTest` — green.

**Example 2 — pure value-object test**

User: "Cover `Phpdup\Util\LineRange` with phpunit."

Actions:
1. Read `src/Util/LineRange.php`.
2. Create `tests/Unit/Util/LineRangeTest.php`, namespace `Phpdup\Tests\Unit\Util`.
3. Tests construct `new LineRange(1, 5)` directly; assert on `start`, `end`, formatting. No stages, no NullOutput.
4. Run `vendor/bin/phpunit --filter LineRangeTest` — green.

## Common Issues

- **"No tests found in class"** — the class namespace does not start with `Phpdup\Tests\Unit\`, or the file is outside `tests/Unit/`. Fix the namespace and path so they mirror `src/` exactly.

- **"Test failed: PHPUnit\Framework\Error\Notice"** — `failOnNotice=true` triggered. Usually an undefined array key in the asserted payload. Print `var_export($payload, true)` once to see the real shape, add `assertArrayHasKey` before the index access, then remove the debug line.

- **"Cannot instantiate Block: too few arguments"** — `Block::__construct` requires named arguments `file, range, kind, namespace, class, name, ast`. The `$size` property is set **after** construction (`$b->size = ...;`), not in the constructor. See `tests/Unit/Reporting/RankerTest.php` `cluster()` for the canonical pattern.

- **"Argument #2 ($output) must be of type OutputInterface, null given"** — a stage was called without an output. Always pass `new NullOutput()` (`use Symfony\Component\Console\Output\NullOutput;`).

- **`Config::defaults()` returns config without paths** — pass `Config::defaults([$path])` or build `new Config(paths: [...], exclude: Config::defaults([])->exclude, ...)`.

- **Pipeline produced 0 clusters in a test that expects some** — the fixture is below `minBlockSize` (default 8) or `maxDocumentFrequency` filtered the n-grams. Lower `minBlockSize` to 4 and `maxDocumentFrequency` to 0.5 in the test config (see `tests/Unit/Reporting/JsonReporterTest.php` `testOptionalBlockHolesIncludePresentInMembers`).

- **Test files left under `/tmp` after a failed run** — switch the test to the `try/finally` cleanup pattern, or move tracked paths into a `tearDown()` recursive remover. Do not rely on `__destruct`.

- **"Class Phpdup\\X not found" when running phpunit** — composer autoloader is stale. Run `composer dump-autoload` (no `composer install` needed).
