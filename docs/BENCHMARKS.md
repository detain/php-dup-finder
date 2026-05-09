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
| Total impact      | 33,389    |

## Wall-time breakdown (PHP 8.3.6, single thread, cold cache)

| Stage         | Time   | Share |
|---------------|--------|-------|
| Parse         | 0.33 s | 0.9%  |
| Normalize     | 0.99 s | 2.8%  |
| Fingerprint   | 0.96 s | 2.7%  |
| **Cluster**   | **31.03 s** | **88.2%** |
| Refactor      | 0.94 s | 2.7%  |
| Total wall    | 35.13 s |       |
| Peak RSS      | 405 MB  |       |

## Throughput

- 15.1 files/sec
- 93.9 blocks/sec
- 0.66 ms/block per stage outside of clustering

## Cache effectiveness

The disk-backed AST cache (`.phpdup-cache/`) is keyed by `sha1(file) +
parser_version`. Re-running with no source changes:

| Run         | Wall time | Notes                              |
|-------------|-----------|------------------------------------|
| Cold cache  | 35.13 s   | Full parse + normalize             |
| Warm cache  | 35.03 s   | Parse phase essentially free       |

**Conclusion**: the cache helps but parsing was never the bottleneck.
Block extraction + normalization dominate the early pipeline; clustering
dominates everything else.

## Clustering hot path

Clustering is 88% of wall time. The cost has two components:

1. **N-gram inverted index lookup.** For each block, union the postings
   of its rare n-grams to collect candidates. Bounded by the total
   rare-gram cardinality. Empirically ~7% of clustering time.

2. **Pairwise Jaccard + bounded TED.** For each candidate pair, compute
   Jaccard on the n-gram multiset; if it passes the threshold, refine
   with a bounded top-down tree-edit-distance. The TED dominates —
   roughly 81% of clustering time on this corpus.

### Tunable knobs

- `--similarity` (default 0.80) — raising this prunes more pairs
  before TED runs.
- `--exact-only` — skip phase 2 entirely. On this corpus exact-clones
  pass takes ~3 s wall.
- `--min-block-size` (default 8) — fewer blocks, fewer pairs to
  compare. Doubling this typically cuts cluster time more than
  proportionally.
- `--max-df` (default 0.01) — a stricter cutoff drops more common
  n-grams from the candidate-generation phase.

### Recommended settings for very large codebases

For codebases above ~5,000 blocks:

```bash
bin/phpdup analyze src \
  --min-block-size 15 \
  --similarity 0.85 \
  --min-impact 50
```

Or for an exact-clones-only CI gate:

```bash
bin/phpdup analyze src --exact-only --min-impact 30
```

## Known limitations and future work

- **Single-threaded.** A `pcntl_fork`-based worker pool was scoped but
  not delivered in v0.1.0. The parse + normalize + fingerprint phases
  are embarrassingly parallel per file; a ~Nx speedup is plausible.
- **Clustering is not incremental.** Adding one file requires
  re-clustering the whole corpus. An incremental mode that only
  examines blocks whose content hash changed (plus their existing
  cluster neighborhoods) is a future enhancement.
- **TED is the bottleneck.** On very large blocks the bounded DP can
  still be slow. A move to an APTED implementation with proper
  decomposition strategies would likely halve clustering time on
  large corpora.
- **Memory grows with block count.** All blocks plus their
  fingerprints live in RAM during clustering. Streaming clustering
  via on-disk sort + merge would handle multi-million-LOC codebases.

## Reproducing

```bash
composer install
/usr/bin/time -f "%e s wall, %M KB rss" \
  bin/phpdup analyze /path/to/your/code --min-impact 100 --stats
```
