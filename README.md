# phpdup — AST-based PHP duplicate-logic detector

> A semantic clone detector and refactoring assistant for PHP codebases.
> Behaves more like an "extract function" advisor than a copy/paste finder.

[![CI](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/REPLACE_WITH_PROJECT_ID)](https://app.codacy.com/gh/detain/php-dup-finder/dashboard)
[![Codacy Coverage](https://app.codacy.com/project/badge/Coverage/REPLACE_WITH_PROJECT_ID)](https://app.codacy.com/gh/detain/php-dup-finder/dashboard)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

`phpdup` indexes a PHP codebase, parses every file into an Abstract Syntax
Tree, normalizes those ASTs into a canonical form, and finds clusters of
**parameterizable duplication** — places where the *shape* of the code
repeats and only literals, identifiers, method names, or table names vary.

For each cluster it doesn't just point at the duplicates, it tells you
**what the abstraction would look like**:

```
═════════════════════════════════════════════════════════════
  Cluster #2   similarity 1.00   impact 44   members 3   EXACT
─────────────────────────────────────────────────────────────
  src/Notify.php:10-15   App\Notify::notifyHigh
  src/Notify.php:17-22   App\Notify::notifyMid
  src/Notify.php:24-29   App\Notify::notifyLow

  Suggested abstraction:
    function notifyByThreshold(
        int $threshold,
        string $value,
    ): mixed

  Holes:
    $threshold   int          observed: 10, 20, 30
    $value       string       observed: 'admin', 'moderator', 'editor'

  Pattern: config-driven
  Confidence: 1.00
═════════════════════════════════════════════════════════════
```

Compare that with classic copy/paste detectors that would only highlight
that the three methods share text. `phpdup` tells you the threshold and
the role string are the parameters of the abstraction, with their
inferred types and observed values, ready to drop into a refactor.

---

## Table of contents

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
- [Output formats](#output-formats)
- [CLI reference](#cli-reference)
- [Programmatic use](#programmatic-use)
- [Examples](#examples)
- [Architecture](#architecture)
- [Testing](#testing)
- [Performance](#performance)
- [FAQ](#faq)
- [Contributing](#contributing)
- [License](#license)

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
  - **N-gram inverted index + Jaccard + bounded tree-edit-distance**
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
- **Three output formats.** Colorized CLI, structured JSON, and a
  static HTML site with side-by-side diffs and hole tables.
- **AST cache.** SHA-1 keyed disk cache (versioned to the parser
  release) so warm-cache runs skip parsing entirely.
- **Configurable thresholds.** Min block size, similarity floor, n-gram
  size, document-frequency cutoff — all tunable per project.
- **Modular architecture.** Scanner, parser, extractor, normalizer,
  fingerprinter, indexer, clusterer, anti-unifier, and reporters are
  independent modules with small, testable interfaces.
- **Production-ready PHP.** Strict types throughout, PSR-4 autoloaded,
  PHPUnit 10 test suite, requires PHP 8.1+.

---

## Installation

### Via Composer

```bash
composer require --dev detain/php-dup-finder
vendor/bin/phpdup analyze src
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
- Composer

---

## Quick start

Scan a single directory and print the top duplicates:

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

Quick exact-clones-only pass for CI:

```bash
bin/phpdup analyze src --exact-only --min-impact 50
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
  "report": {
    "html": "phpdup-report",
    "json": "phpdup.json"
  }
}
```

CLI flags override config values. Run `bin/phpdup analyze --help` for the
full list.

---

## How it works

### Pipeline

```
sources ─► [Scanner] ─► [Parser] ─► [BlockExtractor] ─► [Normalizer]
                                                              │
                                                              ▼
                                                      [Fingerprinter]
                                                              │
                                                              ▼
   [Reports] ◄─ [RefactorSynthesizer] ◄─ [Clusterer] ◄─ [Index]
```

| Stage               | Output                                     |
|---------------------|--------------------------------------------|
| Scanner             | absolute file paths (glob include/exclude) |
| Parser              | annotated AST per file (with line metadata)|
| BlockExtractor      | function/method/closure/loop/if/switch     |
| Normalizer          | canonical AST + hole map                   |
| Fingerprinter       | structural hash + n-gram bag               |
| Index               | hash → blocks, n-gram inverted index       |
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
   on the canonical n-gram multiset; survivors are refined with a
   bounded top-down tree-edit-distance. A union-find merges all pairs
   above the configured thresholds into clusters.

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

---

## Output formats

### CLI

Colorized terminal output (see the box at the top of this README).

### JSON

```json
{
  "phpdup_version": "0.1.0",
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
      --exact-only         Skip near-duplicate detection (faster)
      --limit N            Show at most N clusters in CLI output (default 50)
      --stats              Show pipeline statistics
      --no-cache           Disable AST cache for this run
```

---

## Programmatic use

The pipeline is fully composable from PHP:

```php
use Phpdup\Cli\Config;
use Phpdup\Scanning\FileScanner;
use Phpdup\Parsing\AstParser;
use Phpdup\Extraction\BlockExtractor;
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

$scanner   = new FileScanner(['vendor/**']);
$parser    = new AstParser();
$extractor = new BlockExtractor(minSize: 8);
$normalizer= new Normalizer('aggressive');
$index     = new BlockIndex();

foreach ($scanner->scan('src') as $file) {
    foreach ($extractor->extract($file, $parser->parseFile($file)) as $b) {
        $normalizer->normalize($b);
        $b->structuralHash = (new SubtreeHasher())->hash($b->canonical);
        $b->ngramBag       = (new NgramFingerprint())->fingerprint($b->canonical);
        $b->id             = $b->structuralHash . '_' . $index->size();
        $index->add($b);
    }
}

$clusters = (new Clusterer(
    new JaccardSimilarity(), new TreeEditDistance()
))->cluster($index);

foreach ($clusters as $c) {
    (new AntiUnifier())->unify($c);
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
  Extraction/     Block selection from file ASTs
  Normalization/  Three-pass canonicalization
  Fingerprint/    Structural hash + n-gram bag
  Index/          In-memory + inverted index
  Similarity/     Jaccard + bounded tree edit distance
  Clustering/     Hash-bucket + union-find
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
- Anti-unifier hole discovery on the canonical example, and on
  three-member clusters
- Strategy / config-driven pattern tagging
- End-to-end on a fixture corpus with expected clusters

GitHub Actions runs the full suite on every push and PR, then uploads
Clover coverage to Codacy.

---

## Performance

| Operation                | Complexity                     | Notes                                       |
|--------------------------|--------------------------------|---------------------------------------------|
| File scanning            | O(F)                           | F = file count                              |
| Parsing                  | O(L) per file                  | L = lines; cached on subsequent runs        |
| Block extraction         | O(N)                           | N = AST node count                          |
| Normalization            | O(N)                           |                                             |
| Hashing                  | O(N) per block                 |                                             |
| Hash bucketing           | O(B)                           | B = block count                             |
| Inverted-index candidate | O(B × g̅)                      | g̅ = avg n-grams per block, with rare-gram  |
| Pairwise Jaccard         | candidate-bounded              | only blocks sharing rare grams              |
| Tree edit distance       | bounded by `(1−τ) × max_size`  | aborts as soon as cost exceeds budget       |

Tunable knobs:

- `min_block_size` — kills boilerplate (the biggest noise source)
- `max_block_size` — caps TED work
- `max_df` — rare-gram filter cutoff
- `similarity_threshold` and `tree_threshold` — where to draw the line

The AST cache stores serialized parse trees keyed by `sha1(file) +
parser_version` — re-runs with no source changes skip parsing entirely.

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
nullsafe, `first-class callable syntax`, all supported by
`nikic/php-parser` v5.

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
