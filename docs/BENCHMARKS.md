# Benchmarks

Benchmark corpus: a real PHP application's `include/Api/` directory —
client API classes for a hosting/billing platform. Representative
mid-sized PHP code: 530 files, 3,295 comparable blocks, mostly methods
and `if` statements.

| Metric            | Value     |
|-------------------|-----------|
| Files             | 530       |
| Blocks            | 3,295     |
| Parse errors      | 0         |
| Clusters reported | 96        |
| Duplicated lines  | 8,641     |
| Total impact      | 33,393    |

Cluster output (count, members, signatures, similarity scores) is
identical across all v0.2 configurations and matches v0.1 within
floating-point rounding — the speedups don't come from skipping work.

## v0.1 vs v0.2

v0.2 closes the four "known limitations" listed in the v0.1 benchmarks
doc: APTED-style correct tree edit distance, parallel `pcntl_fork`
worker pool, per-file incremental indexing, and lazy AST loading.

| Configuration                                          | Wall time | vs v0.1 | Notes                                       |
|--------------------------------------------------------|----------:|--------:|---------------------------------------------|
| **v0.1 — top-down TED, single thread**                | 35.13 s   | 1.00×   | The original baseline.                      |
| v0.2 serial (workers=1), cold cache                    | 61.13 s   | 0.57×   | APTED alone is *slower* than v0.1 — see below. |
| v0.2 4 workers, cold cache                             | 30.39 s   | 1.16×   |                                              |
| v0.2 8 workers, cold cache                             | 21.11 s   | 1.66×   |                                              |
| v0.2 16 workers, cold cache                            | 17.47 s   | 2.01×   | Diminishing returns past ~16 cores.          |
| v0.2 8 workers, warm cache + incremental               | 21.06 s   | 1.67×   | Preprocess phase reuses snapshots; cluster cost unchanged. |
| v0.2 8 workers, `--exact-only`                         |  5.74 s   | 6.12×   | Skips phase 2 entirely (no near-dup detection). |

### Why is serial v0.2 slower than serial v0.1?

The v0.1 TED was a bounded Selkow-style top-down recursion — fast on
average because it aborted early on near-misses, but it was a homebrew
without proof of correctness on pathological tree shapes. v0.2's
APTED-style implementation is a proper Zhang-Shasha forest-distance DP
with heavy-path child ordering: it's correct, but it does more work
per pair before bailing out.

Cluster *output* is the same in both versions because clustering uses
TED only to test "sim ≥ threshold" — if both implementations agree on
that boolean, identical clusters fall out. The honest read is that the
algorithmic correctness gain doesn't show up as a wall-time gain
serially; the user-visible win comes from parallelism stacking on top.

## v0.2 wall-time breakdown (8 workers, cold cache)

| Stage         | Time   | Share | Implementation                                       |
|---------------|-------:|------:|------------------------------------------------------|
| Preprocess    | 1.4 s  |  7%   | Parse + extract + normalize + fingerprint, parallel  |
| Cluster       | 14.3 s | 68%   | APTED + parallel candidate-pair scoring              |
| Refactor      | 4.2 s  | 20%   | Anti-unify + synthesize + pattern-tag (serial)       |
| Reporting/IO  | 1.2 s  |  5%   |                                                      |
| **Total**     | 21.1 s | 100%  | Peak RSS 464 MB                                      |

Clustering still dominates, but the share dropped from 88% in v0.1 to
68% in v0.2 because preprocessing now runs in parallel. The remaining
clustering cost is mostly the parallel TED workload itself.

## What v0.2 added

### 1. Worker pool (`pcntl_fork`)

`Phpdup\Parallel\WorkerPool` partitions a list of items into N batches,
forks one child per batch, runs the closure in the child, returns the
serialized result via a temp file, and reaps the children in the
parent. CPU count is auto-detected from `nproc` / `/proc/cpuinfo` or
overridable via `--workers N` / `PHPDUP_WORKERS` env var.

The pool drives two phases:

- **PreprocessWorker** — each child does parse + extract + normalize +
  hash + n-gram fingerprint for its file batch. Output is a list of
  `Block` objects shipped back through the worker channel.
- **PairScoreWorker** — once candidate pairs are generated from the
  inverted index, the master batches them across workers; each child
  runs Jaccard + bounded TED on its batch and emits surviving edges.
  Edges are merged in the master before union-find.

When `pcntl_*` isn't available (Windows, sandboxed PHP) the pool
detects this at runtime and falls back to a serial code path with the
same closure interface — no caller-side branching.

CLI: `--workers 0` (auto, default) · `--workers N` · `--workers 1`
forces serial.

### 2. APTED-style tree edit distance

`Phpdup\Similarity\AptedDistance` is a Zhang-Shasha forest-distance DP
with APTED's heavy-path child ordering and bounded early termination:

- A single linearisation pass per tree (post-order labels +
  leftmost-leaf descendant + key-roots).
- Forest-distance DP at each pair of key-roots, with bounded early
  termination — if a row's minimum exceeds the budget, the DP aborts.
- APTED's heavy-path ordering (heaviest child last) at flatten time so
  the DP encounters the densest subtree last, exploiting the natural
  Zhang-Shasha asymmetry.

`TreeEditDistance` is now a thin facade so future swaps (full APTED
with strategy table, RTED, etc.) are local changes. Correctness is
covered by `tests/Unit/Similarity/AptedDistanceTest.php`.

### 3. Incremental indexing

`Phpdup\Persistence\IndexStore` snapshots each file's extracted +
normalized + fingerprinted blocks under
`<cache_dir>/<sha1(path)>.idx`. Each snapshot stores:

- `file_hash` — `sha1_file()` of the source.
- `parser_version` — bumped together with the AST cache key.
- `config_key` — sha1 of the relevant config fields (block size,
  normalization mode, n-gram size). Changing any of these invalidates
  the snapshot automatically.
- `blocks` — serialized `Block[]` ready to pour into the index.

On re-runs the master splits files into "reuse" (snapshot hit) and
"process" (snapshot miss) and only the latter goes through the worker
pool. Editing one file leaves the other 529 snapshots intact.

Disable with `--no-incremental` for benchmarking or when paranoid
about cache poisoning.

### 4. Lazy AST loading (streaming memory)

After fingerprinting we drop `Block::$ast` (the original PhpParser
subtree) and reload it on demand inside `AntiUnifier` via
`BlockAstLoader`. The loader walks the file's parse-cached statement
list looking for the unique
(kind, start_line, end_line, declared_name) tuple; matches are
populated back into the Block.

On this corpus the RSS difference is small (~25 MB) because most
weight is in canonical ASTs and n-gram bags, not original ASTs. On
multi-MLOC corpora the win scales with block count. Disable with
`--no-lazy-ast` if you have RAM to spare.

### Honest reporting on what doesn't pay off (yet)

- **APTED alone is a serial regression.** Correct, but slower than the
  v0.1 bounded top-down: 61 s vs 35 s on this corpus, single thread.
  The win has to come from parallelism stacking on top.
- **Diminishing returns past 8 workers.** 16 → 8 saves 4 s; the serial
  sections (final union-find, anti-unification, pattern recognition)
  impose an Amdahl's-law ceiling at ~2× on this corpus. To break past
  that we'd need to parallelize anti-unification too.
- **Lazy AST is currently no faster than full-memory mode at this
  scale** — the ~2 s reload overhead roughly cancels the small RSS
  saving. The default (`lazy_ast: true`) is conservative; if you have
  a large host, set it to `false` in `phpdup.json` for a speed bump.
- **Incremental warm-cache savings are modest** when the cluster phase
  dominates total wall time. Incremental shines when the corpus grows
  by a few files per run; on full re-analysis with no source changes
  the snapshot-load IO roughly cancels the parser savings.

## Cache effectiveness

Re-running with no source changes after a v0.2 cold run:

| Run                 | Wall time | Notes                                            |
|---------------------|----------:|--------------------------------------------------|
| Cold (no caches)    | 21.11 s   | Full pipeline, 8 workers                         |
| Warm AST cache only | ~21 s     | Parser cache hit; preprocess still runs          |
| Warm + incremental  | 21.06 s   | Block snapshots reused; cluster cost unchanged   |

## Tunable knobs

Inherited from v0.1, still relevant:

- `--similarity` (default 0.80) — raising prunes more pairs before TED.
- `--exact-only` — skip phase 2 entirely. **5.74 s wall** on this
  corpus with 8 workers — the fastest "is this clean?" gate.
- `--min-block-size` (default 8) — fewer blocks, fewer pairs.
- `--max-df` (default 0.01) — stricter rare-gram cutoff.

New in v0.2:

- `--workers N` — parallelism level (0 = auto-detect).
- `--no-incremental` — disable per-file index snapshots.
- `--no-lazy-ast` — keep all original ASTs in RAM.

### Recommended settings

For exploratory analysis on any codebase:

```bash
bin/phpdup analyze src --workers $(nproc)
```

Very large codebases (>5,000 blocks):

```bash
bin/phpdup analyze src \
  --workers $(nproc) \
  --min-block-size 15 \
  --similarity 0.85 \
  --min-impact 50
```

CI gate (exact clones only, fastest):

```bash
bin/phpdup analyze src --exact-only --min-impact 30 --workers 8
```

Massive monorepo (low memory budget):

```bash
bin/phpdup analyze src \
  --workers $(nproc) \
  --min-block-size 20 \
  --similarity 0.88
# lazy_ast and incremental are on by default.
```

## Reproducing

```bash
composer install
rm -rf .phpdup-cache  # cold cache
/usr/bin/time -f "%e s wall, %M KB rss" \
  bin/phpdup analyze /path/to/your/code \
    --min-impact 100 --stats --workers 8 --no-cache
```

## Future work (post-v0.2)

The architectural items left open:

- **Parallelise anti-unification + pattern recognition.** Currently
  serial, ~4 s on the benchmark corpus. Each cluster is independent
  so this is straightforward extension of the worker pool — not done
  yet because the gain is small below ~50 clusters.
- **Smarter TED bounding.** APTED's correctness comes at the cost of
  visiting more pairs than the bounded top-down. A two-tier scheme
  (cheap heuristic to reject early, APTED only on survivors) would
  recover some of the serial speed.
- **Streaming clustering for multi-MLOC corpora.** All blocks live in
  RAM during clustering. An on-disk hash-bucket external sort plus
  candidate streaming would handle codebases that don't fit in memory.
- **Persistent cluster cache.** When most files are unchanged, the
  candidate set is largely stable; persisting last-run's edges and only
  re-scoring pairs that touch a changed file would eliminate the
  remaining clustering cost on incremental runs.
- **Parallel TED that shares state via shared memory.** The current
  fork-based pool re-allocates the per-process tree linearisation
  caches in each child. A shared-memory pool (via `shmop` or the SysV
  IPC functions) could reuse them across batches.
