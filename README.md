# phpdup — AST-based PHP duplicate-logic detector

> A semantic clone detector and refactoring assistant for PHP codebases.
> Behaves more like an "extract function" advisor than a copy/paste finder.

[![CI](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/php-dup-finder/branch/master/graph/badge.svg)](https://app.codecov.io/gh/detain/php-dup-finder)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

`phpdup` indexes a PHP codebase, parses every file into an Abstract Syntax
Tree, normalizes those ASTs into a canonical form, and finds clusters of
**parameterizable duplication** — places where the *shape* of the code
repeats and only literals, identifiers, method names, or table names vary.

For each cluster it doesn't just point at the duplicates, it tells you
**what the abstraction would look like**:

```
╭─────────────────────────────────────────────────────────────────╮
│  phpdup                                                         │
│  4 files · 20 blocks · 6 clusters · 84 dup lines · 194 impact   │
╰─────────────────────────────────────────────────────────────────╯

ℹ 6 cluster(s); showing top 1 (sorted by impact)

── Cluster #2   similarity 1.00   impact 44   members 3   EXACT ──
╭────────────────────────────────────────┬────────┬──────────────────────╮
│ LOCATION                               │ KIND   │ QUALIFIED NAME       │
├────────────────────────────────────────┼────────┼──────────────────────┤
│ src/Notify.php:10-15                   │ method │ App\Notify::high     │
│ src/Notify.php:17-22                   │ method │ App\Notify::mid      │
│ src/Notify.php:24-29                   │ method │ App\Notify::low      │
╰────────────────────────────────────────┴────────┴──────────────────────╯

── Suggested abstraction ─────────────────────────────────────────
┌──────────────────────────────┐
│  function notifyByThreshold( │
│      int $threshold,         │
│      string $value,          │
│  ): mixed                    │
└──────────────────────────────┘

── Holes ─────────────────────────────────────────────────────────
╭────────────┬────────┬─────────┬───────────────────────────────╮
│ PARAM      │ TYPE   │ KIND    │ OBSERVED                      │
├────────────┼────────┼─────────┼───────────────────────────────┤
│ $threshold │ int    │ literal │ 10, 20, 30                    │
│ $value     │ string │ literal │ 'admin', 'moderator', 'editor'│
╰────────────┴────────┴─────────┴───────────────────────────────╯

  patterns  config-driven
  ✓ confidence 1.00
```

Compare that with classic copy/paste detectors that would only highlight
that the three methods share text. `phpdup` tells you the threshold and
the role string are the parameters of the abstraction, with their
inferred types and observed values, ready to drop into a refactor.

---

## Table of contents

- [What's new in v0.2](#whats-new-in-v02)
- [Features](#features)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
  - [Pipeline](#pipeline)
  - [Normalization modes](#normalization-modes)
  - [Clustering](#clustering)
  - [Anti-unification](#anti-unification)
  - [Pattern recognition](#pattern-recognition)
  - [Ranking](#ranking)
  - [Parallelism](#parallelism)
  - [Incremental indexing](#incremental-indexing)
  - [Lazy AST loading](#lazy-ast-loading)
- [Output formats](#output-formats)
- [CLI reference](#cli-reference)
- [Programmatic use](#programmatic-use)
- [Examples](#examples)
- [Benchmarks](#benchmarks)
- [Architecture](#architecture)
- [Testing](#testing)
- [Performance](#performance)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)

---

## What's new in v0.2

v0.2 closes the four "known limitations" listed in v0.1's BENCHMARKS.md.
The headline change for users: phpdup is now multi-process by default
and re-runs reuse work from earlier runs.

| Area                      | v0.1                            | v0.2                                                                                       |
|---------------------------|---------------------------------|--------------------------------------------------------------------------------------------|
| Concurrency               | Single-threaded                 | `pcntl_fork` worker pool. Auto-detects CPU count. Serial fallback when pcntl unavailable. |
| Tree edit distance        | Bounded top-down (homebrew)     | Zhang-Shasha forest DP with APTED-style heavy-path child ordering. Correctness-tested.     |
| Re-run cost               | Full re-analysis every time     | Per-file index snapshots; only changed/added files are re-processed.                       |
| Memory                    | All ASTs held in RAM throughout | Original ASTs dropped after fingerprinting; reloaded on demand inside the anti-unifier.    |
| Terminal output           | Plain ANSI                      | SugarCraft (lipgloss-port) styled banner, tables, status lines, pattern-tag chips.         |
| New CLI flags             | —                               | `--workers/-j`, `--no-incremental`, `--no-lazy-ast`.                                       |
| Tests                     | 19                              | 31 (added APTED correctness, worker-pool semantics, IndexStore round-trip).                |

Cluster output (count, members, signatures, impact, similarity) is
byte-identical between v0.1 and v0.2 within rounding — the speedups
don't come from skipping work.

For raw numbers and an honest discussion of where the wins came from
(spoiler: parallelism, not APTED itself) see [docs/BENCHMARKS.md](docs/BENCHMARKS.md).

---

## Features

- **Semantic, not textual.** Compares AST structure, not source text — so
  whitespace, comments, and identifier renames don't fool it.
- **Parameter discovery.** For every cluster, identifies the literals,
  identifiers, method names, and class names that vary, and proposes
  them as parameters of a suggested abstraction with inferred types
  and named placeholders.
- **Three normalization modes.** From `strict` (variable rename
  tolerant only) to `aggressive` (also collapses literal values, method
  names, property names, and class names) — pick the precision/recall
  trade-off you want.
- **Two-phase clustering.**
  - **Hash buckets** for exact canonical matches — O(N) work.
  - **N-gram inverted index + Jaccard + APTED-style tree-edit-distance**
    for near-duplicates — never quadratic in practice.
- **Anti-unification.** Computes the most-specific generalization of a
  cluster's members and turns disagreements into typed parameter holes.
- **Pattern recognition.** Tags clusters that match well-known refactor
  archetypes:
  - `sql-builder`        — string concat feeding `query`/`prepare`/`exec`/`fetch`
  - `crud-handler`       — names contain `create`/`read`/`update`/`delete` or `select`/`insert`/`fetch`/`find`
  - `validation-chain`   — short-circuit `if`-then-throw/return chains
  - `strategy`           — single hole on a method/function name
  - `config-driven`      — only literal holes
  - `state-machine`      — switch/match block
- **Impact-ranked output.** Clusters sorted by how many lines disappear
  if the abstraction is applied, with a separate confidence score that
  flags risky refactors (subtree-level holes, cross-namespace spans).
- **Three output formats.** SugarCraft-styled colorized CLI, structured
  JSON, and a static HTML site with side-by-side diffs and hole tables.
- **Parallelized preprocessing and pair scoring.** `pcntl_fork` worker
  pool batches files for parse + extract + normalize + fingerprint, and
  candidate pairs for Jaccard + tree-edit-distance scoring. Auto CPU
  detection, serial fallback when pcntl is unavailable.
- **APTED-style tree edit distance.** Zhang-Shasha forest-distance DP
  with heavy-path child ordering and bounded early termination —
  correct on all tree shapes.
- **Incremental indexing.** Per-file block snapshots keyed by content
  hash + parser version + config key. Editing one file leaves the
  other 999 snapshots intact.
- **Lazy AST loading.** Original ASTs are dropped after fingerprinting
  and reloaded on demand only for blocks that end up in clusters. RSS
  scales sub-linearly with corpus size.
- **AST cache.** SHA-1 keyed disk cache (versioned to the parser
  release) so warm-cache runs skip parsing entirely.
- **Configurable thresholds.** Min block size, similarity floor, n-gram
  size, document-frequency cutoff — all tunable per project.
- **Modular architecture.** Scanner, parser, extractor, normalizer,
  fingerprinter, indexer, clusterer, anti-unifier, refactor synthesizer,
  pattern recognizer, and reporters are independent modules with
  small, testable interfaces.
- **Production-ready PHP.** Strict types throughout, PSR-4 autoloaded,
  PHPUnit 10 test suite (31 tests / 99 assertions), requires PHP 8.1+.

---

## Installation

### Via Composer

```bash
composer require --dev detain/php-dup-finder
vendor/bin/phpdup analyze src
```

The package isn't on Packagist yet — declare the GitHub repo manually:

```json
{
    "require-dev": {
        "detain/php-dup-finder": "dev-master"
    },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/detain/php-dup-finder" }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

### From source

```bash
git clone https://github.com/detain/php-dup-finder.git
cd php-dup-finder
composer install
bin/phpdup analyze /path/to/your/code
```

Requirements:
- PHP 8.1 or newer
- ext-hash (for `xxh128`)
- ext-pcntl + ext-posix (optional, for parallelism — without them
  phpdup runs serially with no other change)
- Composer

---

## Quick start

Scan a single directory and print the top duplicates (auto-parallelized):

```bash
bin/phpdup analyze src
```

Scan multiple directories with both JSON and HTML reports:

```bash
bin/phpdup analyze src lib \
    --json    duplicates.json \
    --html    duplicates-report \
    --min-impact 30
```

Use a config file for repeatable runs:

```bash
bin/phpdup analyze --config phpdup.json
```

Quick exact-clones-only pass for CI (very fast, ~6 s on a 3,300-block corpus):

```bash
bin/phpdup analyze src --exact-only --min-impact 50
```

Force serial execution (debugging or constrained env):

```bash
bin/phpdup analyze src --workers 1
```

Pin a specific worker count:

```bash
bin/phpdup analyze src --workers 8       # or -j 8
```

---

## Configuration

Drop a `phpdup.json` next to your code, or pass `--config`:

```json
{
  "paths":   ["src", "app", "lib"],
  "exclude": ["vendor/**", "node_modules/**", "**/*.tpl.php", "tests/**"],
  "min_block_size":       8,
  "max_block_size":       800,
  "normalization_mode":   "aggressive",
  "similarity_threshold": 0.80,
  "tree_threshold":       0.85,
  "min_cluster_impact":   20,
  "max_df":               0.01,
  "ngram_size":           5,
  "cache_dir":            ".phpdup-cache",
  "workers":              0,
  "incremental":          true,
  "lazy_ast":             true,
  "report": {
    "html": "phpdup-report",
    "json": "phpdup.json"
  }
}
```

Keys added in v0.2:

- `workers` — parallelism level. `0` (default) auto-detects from
  `nproc` / `/proc/cpuinfo`. `1` forces serial.
- `incremental` — `true` (default) reuses per-file block snapshots
  across runs.
- `lazy_ast` — `true` (default) drops original ASTs after
  fingerprinting; reloads them only for blocks in clusters.

CLI flags override config values. Run `bin/phpdup analyze --help` for the
full list.

---

## How it works

### Pipeline

```
sources ─► [Scanner] ─► [Parser] ─► [BlockExtractor] ─► [Normalizer]
                                                              │
            ┌─────────────────────────────────────────────────┘
            │            (parallelized per file batch)
            ▼
     [Fingerprinter] ─► [IndexStore snapshot]
            │
            ▼
   [BlockIndex] ─► [NgramInvertedIndex]
                          │
                          ▼
                  [candidate pairs]
                          │
                          ▼   (parallelized per pair batch)
                 [Jaccard + APTED]
                          │
                          ▼
                 [union-find clusters]
                          │
                          ▼
   [Reports] ◄─ [RefactorSynthesizer] ─► [BlockAstLoader]
```

| Stage               | Output                                     |
|---------------------|--------------------------------------------|
| Scanner             | absolute file paths (glob include/exclude) |
| Parser              | annotated AST per file (with line metadata)|
| BlockExtractor      | function/method/closure/loop/if/switch     |
| Normalizer          | canonical AST + hole map                   |
| Fingerprinter       | structural hash + n-gram bag               |
| IndexStore          | per-file snapshot (incremental cache)      |
| BlockIndex          | hash → blocks                               |
| NgramInvertedIndex  | n-gram → block ids                          |
| Clusterer           | clusters with similarity scores            |
| RefactorSynthesizer | generalized AST, holes, signature, tags    |
| Reports             | CLI / JSON / HTML output                   |

### Normalization modes

| Mode          | Variable rename | Literal collapse | Name collapse |
|---------------|:---------------:|:----------------:|:-------------:|
| `strict`      | yes             | no               | no            |
| `default`     | yes             | yes              | no            |
| `aggressive`  | yes             | yes              | yes           |

In `aggressive` mode, two functions with different table names, different
method names, and different literal values can still cluster together.

### Clustering

Two phases:

1. **Exact canonical clones.** All blocks sharing the same Merkle hash
   over the canonical AST land in the same bucket. O(N) work.
2. **Near-duplicates.** For each block, candidates are pulled from a
   rare-n-gram inverted index (ignoring n-grams that occur in more than
   `max_df` × N blocks). Each candidate is scored by Jaccard similarity
   on the canonical n-gram multiset; survivors are refined with APTED-style
   bounded tree-edit-distance. A union-find merges all pairs above the
   configured thresholds into clusters.

### Anti-unification

For every cluster `phpdup` computes the most-specific generalization of
its members. The classic recursion:

```
au(t1, t2) =
    if root(t1) == root(t2) and arity matches:
        Node(root(t1), [au(c1_i, c2_i) for i in children])
    else:
        Hole(observed=[t1, t2])
```

Extended for clusters of size > 2 by sequential pairwise folding. The
resulting template has Hole markers at exactly the positions where
members disagreed — those are the suggested parameters. Each hole tracks
its observed values across all members so the report can show
`threshold ∈ {10, 20, 30}` and `role ∈ {'admin', 'moderator', 'editor'}`.

### Pattern recognition

After anti-unification, each cluster is checked against a small catalog
of refactor archetypes (sql-builder, crud-handler, validation-chain,
strategy, config-driven, state-machine). Tags are advisory; they don't
change clustering, just label the cluster in the report.

### Ranking

Each cluster gets two scores:

- **Impact** ≈ `(members - 1) × avgBlockSize - holesPenalty`. How many
  lines of code disappear if the abstraction is applied.
- **Confidence** in `[0,1]`. Cluster similarity, penalized for
  subtree-level holes (large variable subtrees) and cross-namespace
  spans, bumped for same-class cohesion.

Clusters below `min_cluster_impact` are dropped. Survivors are sorted by
descending impact, breaking ties by member count and similarity.

### Parallelism

`Phpdup\Parallel\WorkerPool` partitions a list of items into N batches,
forks one child per batch via `pcntl_fork`, runs the closure in the
child, returns the serialized result via a temp file, and reaps the
children in the parent.

Two phases use it:

- **`PreprocessWorker`** — each child does parse + extract + normalize
  + hash + n-gram fingerprint for its file batch.
- **`PairScoreWorker`** — once candidate pairs are generated from the
  inverted index, the master batches them across workers; each child
  runs Jaccard + bounded TED on its batch and emits surviving edges.

CPU count is auto-detected (`nproc` / `/proc/cpuinfo`) or overridable
via `--workers N` / `PHPDUP_WORKERS=N`. When `pcntl_*` is unavailable
(Windows, sandboxed PHP), the pool detects this at runtime and falls
back to a serial code path with the same closure interface — callers
don't branch.

### Incremental indexing

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
"process" (snapshot miss) buckets and only the latter goes through the
worker pool. Editing one file leaves the other snapshots intact.

Disable with `--no-incremental` for benchmarking or when paranoid
about cache poisoning.

### Lazy AST loading

After fingerprinting we drop `Block::$ast` (the original PhpParser
subtree) and reload it on demand inside `AntiUnifier` via
`BlockAstLoader`. The loader walks the file's parse-cached statement
list looking for the unique
(kind, start_line, end_line, declared_name) tuple; matches are
populated back into the Block.

The AST cache is consulted first so on warm runs no parsing happens at
all. Disable with `--no-lazy-ast` if you have RAM to spare and want
maximum speed (the reload overhead in v0.2 is roughly equal to the
RSS savings on small corpora — see BENCHMARKS.md).

---

## Output formats

### CLI

SugarCraft-styled colorized terminal output (see the box at the top of
this README). Powered by:

- `SugarCraft\Kit\Banner` for the bordered summary header.
- `SugarCraft\Kit\Section` for cluster and sub-section rules.
- `SugarCraft\Kit\StatusLine` for ✓/✗/⚠/ℹ status messages.
- `SugarCraft\Sprinkles\Table` for member and hole tables.
- `SugarCraft\Sprinkles\Style` + `Border` for the suggested-signature
  box and pattern-tag chips.

Honors `--no-ansi` / non-TTY: switches to `Theme::plain()` and skips
the styled box / chips, producing clean ASCII the same code path can
emit.

### JSON

```json
{
  "phpdup_version": "0.2.0",
  "summary": { "files": 1888, "blocks": 12340, "clusters": 87, ... },
  "clusters": [
    {
      "id": "Xaeb0e34a",
      "exact": true,
      "similarity": 1.0,
      "confidence": 1.0,
      "impact": 74,
      "pattern_tags": ["config-driven", "crud-handler", "sql-builder"],
      "signature": "function findById(\n    string $value,\n): mixed",
      "members": [ ... ],
      "holes": [
        {
          "placeholder": "__P0",
          "kind": "literal",
          "inferred_type": "string",
          "suggested_name": "$value",
          "observed": [
            "'SELECT * FROM users WHERE id = ?'",
            "'SELECT * FROM products WHERE id = ?'",
            "'SELECT * FROM orders WHERE id = ?'"
          ]
        }
      ]
    }
  ]
}
```

### HTML

A static-site report with:

- Index page sorted by impact
- Per-cluster page with member sources side-by-side
- Holes table showing placeholder, suggested name, type, and observed values
- Sebastian-Bergmann unified diff between the first two members
- Pure CSS, no JavaScript or build step

---

## CLI reference

```
Usage: phpdup analyze <paths...> [options]

Arguments:
  paths                    One or more paths to scan

Options:
  -c, --config FILE        Path to phpdup.json
      --min-block-size N   Minimum AST node count for a block (default 8)
      --mode MODE          Normalization mode: strict|default|aggressive
      --similarity N       Jaccard similarity threshold (0..1, default 0.80)
      --min-impact N       Minimum cluster impact to report (default 20)
      --html DIR           Write HTML report to this directory
      --json FILE          Write JSON report to this file
      --exact-only         Skip near-duplicate detection (very fast)
      --limit N            Show at most N clusters in CLI output (default 50)
      --stats              Show pipeline statistics + worker info
      --no-cache           Disable AST cache for this run
  -j, --workers N          Worker count for parallel preprocess + pair scoring
                           (0 = auto-detect CPU count, 1 = serial)
      --no-incremental     Disable per-file index snapshot reuse
      --no-lazy-ast        Keep all original ASTs in memory throughout the run
                           (higher RSS, slightly faster anti-unification)
```

### Exit codes

| Code | Meaning                                              |
|------|------------------------------------------------------|
| `0`  | Analysis ran. **Note:** phpdup does NOT exit non-zero |
|      | when clusters are found. Use the JSON report to gate |
|      | CI; an empty `clusters` array means clean.           |
| `1`  | Internal error.                                      |
| `2`  | Missing required argument.                           |

### Environment variables

| Variable          | Effect                                                |
|-------------------|-------------------------------------------------------|
| `PHPDUP_WORKERS`  | Override worker count (lower precedence than `-j`).   |
| `COLUMNS`         | Override terminal width detection for the CLI report. |

---

## Programmatic use

The pipeline is fully composable from PHP:

```php
use Phpdup\Cli\Config;
use Phpdup\Scanning\FileScanner;
use Phpdup\Parsing\AstParser;
use Phpdup\Parsing\AstCache;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Normalization\Normalizer;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Index\BlockIndex;
use Phpdup\Clustering\Clusterer;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\SignatureBuilder;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Parallel\PreprocessWorker;
use Phpdup\Parallel\PairScoreWorker;

$config = new Config(
    paths: ['src'],
    exclude: ['vendor/**'],
);

// Phase 1: parallel preprocessing.
$scanner = new FileScanner($config->exclude);
$files = [];
foreach ($scanner->scan('src') as $f) { $files[] = $f; }

$worker = new PreprocessWorker($config);
$pool = new WorkerPool(workers: 0);                      // auto
$rows = $pool->run($files, fn(array $batch) => $worker->process($batch));

$index = new BlockIndex();
foreach ($rows as $row) {
    if ($row['type'] !== 'block') continue;
    $b = $row['block'];
    $b->id = $b->structuralHash . '_' . $index->size();
    $index->add($b);
    $b->unloadAst();   // free RAM; we'll reload lazily later
}

// Phase 2: cluster (with parallel pair scoring).
$clusterer = new Clusterer(
    new JaccardSimilarity(), new TreeEditDistance(),
);
$pairs = $clusterer->generateCandidatePairs($index);
$scoreWorker = new PairScoreWorker($index, 0.80, 0.85);
$edges = $pool->run($pairs, fn(array $batch) => $scoreWorker->score($batch));
$clusters = $clusterer->cluster($index, $edges);

// Phase 3: refactor synthesis with lazy AST reload.
$loader = new BlockAstLoader(new AstCache('.phpdup-cache'), new AstParser());
$au = new AntiUnifier($loader);
foreach ($clusters as $c) {
    $au->unify($c);
    (new ParameterSynthesizer())->synthesize($c);
    (new SignatureBuilder())->buildSignature($c);
    echo "{$c->size()} members, signature: {$c->signature}\n";
}
```

---

## Examples

### Threshold-gated notification

Input:

```php
public function notifyHigh($user, int $score): void {
    if ($score > 10) { $this->mailer->send('admin', $user); }
}
public function notifyMid($user, int $score): void {
    if ($score > 20) { $this->mailer->send('moderator', $user); }
}
```

Output:

```
Suggested abstraction:
  function notifyByThreshold(int $threshold, string $value): mixed

Holes:
  $threshold   int    observed: 10, 20
  $value       string observed: 'admin', 'moderator'

Pattern: config-driven
```

### Repository CRUD

Input: three classes with `findById($db, $id)` differing only in table name.

Output:

```
Suggested abstraction:
  function findById(string $value): mixed

Holes:
  $value  string  observed: 'SELECT * FROM users WHERE id = ?',
                            'SELECT * FROM products WHERE id = ?',
                            'SELECT * FROM orders WHERE id = ?'

Pattern: config-driven, crud-handler, sql-builder
```

### Strategy dispatch

Input: a chain of `if (...)` calls each invoking a different validator,
all with the same shape.

Output: cluster tagged `strategy`, single hole on the call name, with the
list of method names as observed values — a clear hint to extract an
interface and an array of strategies.

---

## Benchmarks

Same corpus, same config, on a real PHP application's `include/Api/`
directory: 530 files, 3,295 comparable blocks, 96 clusters reported.

| Configuration                                          | Wall time | vs v0.1 |
|--------------------------------------------------------|----------:|--------:|
| **v0.1 — top-down TED, single thread**                | 35.13 s   | 1.00×   |
| v0.2 — serial (`--workers 1`), cold cache              | 61.13 s   | 0.57×   |
| v0.2 — 4 workers, cold cache                           | 30.39 s   | 1.16×   |
| v0.2 — 8 workers, cold cache                           | 21.11 s   | 1.66×   |
| v0.2 — 16 workers, cold cache                          | 17.47 s   | 2.01×   |
| v0.2 — 8 workers, `--exact-only`                       |  5.74 s   | 6.12×   |

Cluster output is byte-identical across configurations — the speedups
don't come from skipping work. APTED alone is *slower* than the v0.1
top-down (it does correct Zhang-Shasha work where v0.1 was a bounded
heuristic); the user-facing win is parallelism stacking on top.

For a full breakdown including stage timings, the honest discussion of
diminishing returns past 8 workers, and tuning recommendations for
codebases >5,000 blocks, see [docs/BENCHMARKS.md](docs/BENCHMARKS.md).

---

## Architecture

See [`ARCHITECTURE.md`](ARCHITECTURE.md) for the full design document
including data structures, algorithm details, and the staged
implementation plan.

The project is organized as:

```
src/
  Cli/            CLI entry point and config loader
  Scanning/       File walking and glob filtering
  Parsing/        nikic/php-parser wrapper + AST cache
  Extraction/     Block selection from file ASTs + lazy AST loader
  Normalization/  Three-pass canonicalization
  Fingerprint/    Structural hash + n-gram bag
  Index/          In-memory + inverted index
  Persistence/    IndexStore (per-file block snapshots)
  Similarity/     Jaccard + APTED tree-edit-distance
  Clustering/     Hash-bucket + union-find
  Parallel/       WorkerPool + Preprocess/PairScore workers
  Refactor/       Anti-unification + parameter/signature synth + patterns
  Reporting/      CLI / JSON / HTML reporters + ranker
  Util/           AST serializer, hash helpers, line range
```

Modules have small, documented surfaces. New normalization rules,
similarity metrics, or pattern recognizers plug in without touching the
rest of the pipeline.

---

## Testing

```bash
composer test                  # full suite
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
composer coverage:html         # writes tests/phpunit/coverage-html/
```

The test suite covers:

- Scanner glob semantics
- Normalizer canonicalization (renamed-variable / literal / name modes)
- N-gram fingerprint determinism and Jaccard floor on unrelated code
- APTED correctness on identical, renamed, and unrelated trees, plus
  bounded short-circuit behavior
- WorkerPool serial path, parallel path (skipped without pcntl), empty
  input, and CPU-count detection
- IndexStore round-trip, file-change invalidation, config-key invalidation
- Anti-unifier hole discovery on the canonical example, and on
  three-member clusters
- Strategy / config-driven pattern tagging
- End-to-end on a fixture corpus with expected clusters

GitHub Actions runs the full suite on every push and PR across PHP
8.1, 8.2, 8.3, 8.4, then uploads Clover coverage to Codecov.

---

## Performance

### Asymptotic complexity

| Operation                | Complexity                          | Notes                                                                  |
|--------------------------|-------------------------------------|------------------------------------------------------------------------|
| File scanning            | O(F)                                | F = file count                                                         |
| Parsing                  | O(L) per file                       | L = lines; cached on subsequent runs; **parallelized** in v0.2         |
| Block extraction         | O(N)                                | N = AST node count                                                     |
| Normalization            | O(N)                                | parallelized                                                           |
| Hashing                  | O(N) per block                      | parallelized                                                           |
| Hash bucketing           | O(B)                                | B = block count                                                        |
| Inverted-index candidate | O(B × g̅)                            | g̅ = avg n-grams per block, with rare-gram pre-filter                  |
| Pairwise Jaccard         | candidate-bounded                   | only blocks sharing rare grams; **parallelized** in v0.2               |
| Tree edit distance       | bounded by `(1−τ) × max(\|a\|,\|b\|)` | APTED-style Zhang-Shasha forest DP with heavy-path order; aborts early |
| Anti-unification         | O(\|cluster\| × N) per cluster      | currently serial                                                       |

### Tunable knobs

- `min_block_size` — kills boilerplate (the biggest noise source).
- `max_block_size` — caps TED work; blocks above this are dropped.
- `max_df` — rare-gram filter cutoff for candidate generation.
- `similarity_threshold` and `tree_threshold` — where to draw the
  near-duplicate line.
- `workers` (v0.2) — parallelism level.
- `incremental` / `lazy_ast` (v0.2) — re-run reuse and memory budget.

### Caches

- **AST cache** (`<cache_dir>/parser-v5_<sha1>.cache`) — serialized parse
  trees keyed by `sha1(file) + parser_version`. Re-runs with no source
  changes skip parsing entirely.
- **Index store** (v0.2, `<cache_dir>/<sha1(path)>.idx`) — per-file
  block snapshots keyed by content hash + parser version + config key.
  Re-runs reuse blocks for unchanged files.

Both live under `.phpdup-cache/` next to the project root and are safe
to delete at any time.

### Throughput on the reference corpus

(530 files, 3,295 comparable blocks; 8 workers, cold cache)

- 35.4 files/sec
- 220 blocks/sec
- 2.1× the v0.1 baseline

See [docs/BENCHMARKS.md](docs/BENCHMARKS.md) for the full table
including 1/4/8/16-worker sweeps, warm-cache vs cold-cache, and the
honest discussion of what *didn't* pay off (lazy AST at small scale,
diminishing returns past 8 workers).

---

## FAQ

**How is this different from PHPCPD?**
PHPCPD finds duplicated *tokens* — long runs of identical lexer output.
`phpdup` works on the AST after canonicalization, so renamed variables,
different literals, different method names, and different table names
can all cluster together. More importantly, `phpdup` tells you what the
abstraction would *look like* — its parameter list, types, and a
suggested function name — not just where the duplication is.

**Will it rewrite my code?**
No. `phpdup` is advisory only. It surfaces opportunities; humans decide.
Auto-rewriting was an explicit non-goal — see ARCHITECTURE.md §1.

**Does the parallel mode work on Windows / sandboxed PHP?**
The worker pool detects `pcntl_*` availability at runtime and falls
back to a serial code path automatically. The CLI still accepts
`--workers N` so config files don't have to branch — the value is
ignored when pcntl is missing.

**My CI box only has 2 cores; should I disable parallelism?**
No need — auto-detect picks 2 and parallelism still helps. Below ~32
candidate pairs the pool runs serially anyway (the overhead of forking
isn't worth it on tiny inputs).

**How much RAM does the cache use?**
The AST cache stores serialized parser output keyed by file hash;
typical PHP file → ~5–50 KB on disk. The index store (incremental
snapshots) is ~10–100 KB per file. Both live under
`.phpdup-cache/` next to your project root by default and can be
nuked at any time.

**Why don't I get the threshold/role example as cleanly on my code?**
Try `--mode aggressive --min-impact 30`. The defaults are tuned for
quiet output on first run. Lowering `min-impact` and switching to
`aggressive` exposes more candidates. Conversely, dropping to `default`
mode reduces false positives if you're getting noise.

**Does it support PHP 7.x?**
The tool itself requires PHP 8.1+ (uses `xxh128` and constructor
property promotion). It can analyze codebases written in older PHP
versions — the parser handles 5.x and up.

**Does it handle modern PHP 8.x syntax?**
Yes — match expressions, enums, readonly, named arguments, attributes,
nullsafe, first-class callable syntax — all supported by
`nikic/php-parser` v5.

**Why is `--workers 1` slower than v0.1's serial mode?**
v0.1's TED was a bounded top-down heuristic — fast on average but not
provably correct. v0.2's APTED implementation is correct Zhang-Shasha
which is slower per pair. Cluster output is the same; the user-visible
win comes from the parallel scoring, not APTED itself. See
BENCHMARKS.md for the details.

---

## Contributing

PRs welcome. Please:

1. Run `composer test` and add tests for new functionality.
2. Match the existing PHP coding style (`declare(strict_types=1)`, PSR-4,
   constructor property promotion, narrow public surfaces).
3. For new normalization rules, similarity metrics, or pattern
   recognizers, document the rule in the relevant module's docblock.

For larger architectural changes, open an issue first to discuss the
design.

---

## License

MIT — see [LICENSE](LICENSE).
