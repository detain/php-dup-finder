---
paths:
  - src/Parallel/**
  - tests/Unit/Parallel/**
---

# Parallel workers

- `WorkerPool::isAvailable()` gates all parallel paths on `pcntl_fork`/`pcntl_waitpid`/`posix_kill`. Always fall back to serial when unavailable or items < 8 (`run()`) / candidate-pairs < 64 (`ClusterStage`).
- Use `WorkerPool::runStreaming()` (generator yielding per-record) for live progress; `run()` only when you need a batch return. Frame header is `pack('N', strlen($payload))` — 4 bytes — never change.
- Worker classes (`PreprocessWorker`, `PairScoreWorker`, `RefactorWorker`) hold no shared state; pass `Config` by value via constructor.
- `PreprocessWorker::toolFor()` caches `extractor`/`normalizer`/`fp`/`irLifter`/`irPrinter` keyed by every `Config` field that affects normalisation. New normalization-affecting `Config` field → append to the cache-key `sprintf()` or stale tooling will be reused.
- Tests use `PHPDUP_WORKERS=1` env override or directly instantiate `PreprocessWorker` to bypass the pool — see `tests/Unit/Parallel/RefactorWorkerTest.php`.
