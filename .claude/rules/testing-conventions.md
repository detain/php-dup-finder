---
paths:
  - tests/**
  - phpunit.xml
---

# Testing

- Mirror `src/` layout under `tests/Unit/`. Class name `<Subject>Test`, namespace `Phpdup\Tests\Unit\<Sub>` (PSR-4 root in `composer.json` `autoload-dev`).
- Use `Symfony\Component\Console\Output\NullOutput` when invoking stages or commands.
- Snapshot tests live in `tests/Golden/` (`tests/Golden/GoldenTest.php`). Refresh with `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`. The runner normalizes paths to repo-relative; do not hardcode absolute paths in fixtures.
- Fixtures under `tests/Fixtures/` may be intentionally malformed to exercise parser-error paths — do NOT add them to `psalm.xml` or `phpstan.neon` scope.
- `phpunit.xml` enables `failOnWarning` AND `failOnNotice`. Any deprecation/notice fails the build — fix root cause, do not suppress.
- Reporter tests should build a synthetic `Report` with hand-rolled `Cluster`/`Block` rather than running the pipeline (faster, decoupled).
- Three suites: `Unit`, `Integration`, `Golden` — keep tests in the right directory so `--testsuite` filtering works.
