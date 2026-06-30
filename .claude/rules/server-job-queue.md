---
paths:
  - src/Server/**
  - tests/Unit/Server/**
---

# Server job queue

- `JobQueue` is bounded: `MAX_JOBS` (100) cap + `JOB_TTL_SECONDS` (3600) TTL on completed/failed jobs. Keep both as `public const` so `Application` and tests can reference them.
- `evictStale()` runs at the top of every mutating op (`enqueue`, `markRunning`, `markCompleted`, `markFailed`); when at capacity `enqueue()` also calls `evictOldestCompletedOrFailed()`. Pending/running jobs are NEVER auto-evicted — only terminal-state (`STATUS_COMPLETED`/`STATUS_FAILED`) entries are.
- Store result **summaries only** — `markCompleted()` receives `{files, blocks, clusters, config}` built by `Application::buildSummary()` from the decoded `JsonReporter` payload; never store the full report.
- Use the `protected now(): float` clock seam (`microtime(true)`) for time — override it in tests to exercise TTL eviction deterministically; do not call `microtime()`/`time()` directly inside queue logic.
- `JobQueue::cleanup()` force-evicts all terminal jobs regardless of TTL. It is method-only — there is no `/jobs/cleanup` HTTP route; the route table in `Application` is a fixed allow-list (`GET /healthz`, `POST /analyze`, `POST /jobs`, `GET /jobs/{id}`).
- Server behaviour is documented in `docs/SERVER.md`; cover queue invariants in `tests/Unit/Server/JobQueueTest.php` and route/summary wiring in `tests/Unit/Server/ApplicationTest.php`.
