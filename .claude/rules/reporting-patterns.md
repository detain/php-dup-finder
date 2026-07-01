---
paths:
  - src/Reporting/**
  - tests/Unit/Reporting/**
---

# Reporting conventions

- One reporter per file: `<Format>Reporter.php`, `final class`, no inheritance.
- Public `writeTo(Report $report, string $file): void` is the entrypoint. `use WritesReportFile;` and call `$this->writeFile($file, $this->build($report))` — the trait handles parent-dir creation (`@mkdir($dir, 0o775, true)`) + write (see `src/Reporting/WritesReportFile.php`).
- Provide a separate pure `build(Report)` (or `buildLine(Report)` for streaming) returning the in-memory payload (array/string) so tests don't touch the filesystem — see `JsonReporter`, `SarifReporter`, `CheckstyleReporter`, `TimeseriesReporter`.
- Neutralize CSV formula injection (S6): any cell whose first char is `=`, `+`, `-`, `@`, tab, or CR must be defused (prefix with `'`) before emission. Applies to every reporter that writes user-derived text into spreadsheet- or patch-consumable output — `CsvReporter`, `DiffReporter`, `RefactorPatchReporter`, `RefactorTestReporter`; cover the escaping in `tests/Unit/Reporting/CsvReporterTest.php`.
- Register the reporter in `src/Pipeline/Stages/ReportStage.php` and gate it behind a flag added in `src/Cli/Command.php::configure()`. Wire the option through `$reportArgs` in `Command::execute()`.
- After writing, emit `<info>phpdup</info> <name> report → <file>` to the `OutputInterface`.
- Tests under `tests/Unit/Reporting/<Format>ReporterTest.php` build a `Report` with synthetic `Cluster`/`Block` instances; do not run the pipeline.
- For ANSI-emitting CLI output, gate `Style::new()->foreground(...)` calls behind `$output->isDecorated()` — `Theme::plain()` does NOT neutralize raw `Style` calls.
- Severity labels are centralized in `src/Reporting/Severity.php` — call `Severity::forImpact(int)` (impact → `High`/`Medium`/`Low`/`Info`, SARIF/GitLab-aligned) or `Severity::forScore(float)` (0.0–1.0 confidence → `High`/`Medium`/`Low`, clamped) instead of inlining threshold `match (true)` blocks. Consumers: `GitLabSastReporter`, `GraphvizReporter`, `CliReporter`.
