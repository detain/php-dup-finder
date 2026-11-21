---
paths:
  - src/Parallel/**
  - tests/Unit/Parallel/**
---

# Parallel workers

- `WorkerPool::isAvailable()` gates all parallel paths on `pcntl_fork`/`pcntl_waitpid`/`posix_kill`. Always fall back to serial when unavailable or items < 8 (`runStreaming()`) / candidate-pairs < 64 (`ClusterStage`).
- Use `WorkerPool::runStreaming()` (generator yielding per-record) — it is the sole entry point; collect into an array yourself if you need a batch return (`run()` was deleted). Frame header is `pack('N', strlen($payload))` — 4 bytes — never change.
- Worker IPC frames are decoded in `WorkerPool::decodeFrame()` with `@unserialize($payload, ['allowed_classes' => $allowList])`, where `$allowList` is `Phpdup\Persistence\SerializedClassAllowList::blockCacheClasses()` (rows embed `Block`/`Hole`/`LineRange`/PhpParser nodes). Never widen to `true`; a new wire class must be added to the allow-list instead.
- `decodeFrame()` fails loud: a frame where `unserialize()` returns `false`, or one carrying a `__PHP_Incomplete_Class`, throws `\RuntimeException` — never yield it into the results stream. Covered by `tests/Unit/Parallel/WorkerPoolWireTest.php`.
- Worker classes (`PreprocessWorker`, `PairScoreWorker`, `RefactorWorker`) hold no shared state; pass `Config` by value via constructor.
- `PreprocessWorker` stages each file's rows in `$fileRows` and emits them only once the whole file completes; a mid-file `\Throwable` yields a single `['type' => 'error', ...]` row instead of a partial block list — see `tests/Unit/Parallel/PreprocessWorkerIsolationTest.php`.
- `PreprocessWorker::toolFor()` caches `extractor`/`normalizer`/`fp`/`irLifter`/`irPrinter` keyed by every `Config` field that affects normalisation. New normalization-affecting `Config` field → append to the cache-key `sprintf()` or stale tooling will be reused.
- Tests use `PHPDUP_WORKERS=1` env override or directly instantiate `PreprocessWorker` to bypass the pool — see `tests/Unit/Parallel/RefactorWorkerTest.php`.
