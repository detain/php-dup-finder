# phpdup — AST-based PHP duplicate-logic detector

A semantic clone detector and refactoring assistant for PHP codebases.
Behaves more like an "extract function" advisor than a copy/paste finder:
it identifies *parameterizable* duplication, where the variable parts
(literals, identifiers, called methods, table names, field keys) are
isolated as candidate function parameters, and the stable parts become
the body of a suggested abstraction.

This document is the architecture and staged-implementation plan.
It is the first deliverable; no detection code has been written yet.

---

## 1. Mission and non-goals

### Mission
Given one or more PHP source roots, produce a ranked report of
duplicated logic clusters. For each cluster, surface:

- The shared canonicalized AST skeleton.
- The variable "holes" (literals, identifiers, names, etc.) that differ
  between cluster members.
- A suggested function signature whose parameters are exactly those holes.
- Source locations for every cluster member.
- A maintenance-impact score and a refactor-confidence score.

### Non-goals (explicitly out of scope)
- Cross-language detection. PHP only. (Token-level Tree-sitter could
  later add JS/SQL but is not on the roadmap.)
- Automatic refactoring or code rewriting. The tool *suggests* — a
  human or a follow-up step applies.
- Running the analyzed code. Pure static analysis.
- Detecting semantic equivalence beyond AST shape (we don't prove that
  two different ASTs compute the same thing).
- Replacing PHPCPD, PHPMD, Phan, or PHPStan. This is a complement,
  focused narrowly on duplicated *logic blocks* and the abstractions
  they imply.

---

## 2. Pipeline overview

```
 sources ─► [Scanner] ─► [Parser] ─► [BlockExtractor] ─► [Normalizer]
                                                              │
                                                              ▼
                                                       [Fingerprinter]
                                                              │
                                                              ▼
   [Reports] ◄─ [RefactorSynthesizer] ◄─ [Clusterer] ◄─ [Index]
```

Each stage is a pure(-ish) function with a stable input/output contract
so it can be tested in isolation and rerun under different parameters.

| Stage               | Input                          | Output                                     |
|---------------------|--------------------------------|--------------------------------------------|
| Scanner             | roots, include/exclude globs   | list of file paths                         |
| Parser              | file path                      | annotated AST per file                     |
| BlockExtractor      | file AST                       | list of `Block` (function/method/loop/if)  |
| Normalizer          | block AST                      | canonical AST + hole map                   |
| Fingerprinter       | canonical AST                  | structural hash + n-gram bag               |
| Index               | fingerprints                   | hash → blocks, n-gram inverted index       |
| Clusterer           | index                          | clusters of similar blocks                 |
| RefactorSynthesizer | cluster                        | generalized AST + parameter list           |
| Reports             | clusters, suggestions          | CLI / JSON / HTML output                   |

---

## 3. Module layout (composer PSR-4 root `Phpdup\\` → `src/`)

```
src/
  Cli/
    Command.php                  # entry point; argv parsing; orchestration
    Config.php                   # resolved config (paths, thresholds, output)
    ConfigLoader.php             # reads phpdup.json / CLI flags
  Scanning/
    FileScanner.php              # recursive walk + glob filter
    DefaultExcludes.php          # vendor/, node_modules/, cache/, build/
  Parsing/
    AstParser.php                # wraps nikic/php-parser
    AstCache.php                 # on-disk cache keyed by file hash
  Extraction/
    BlockExtractor.php           # picks comparable subtrees
    Block.php                    # value object (file, range, ast, kind)
  Normalization/
    Normalizer.php               # rewrites AST → canonical form
    HoleMap.php                  # original ↔ placeholder bookkeeping
    NameCanonicalizer.php        # variable / param renaming policy
    LiteralCanonicalizer.php     # literal abstraction policy
  Fingerprint/
    SubtreeHasher.php            # Merkle-style hash of canonical AST
    NgramFingerprint.php         # bag of canonical-token n-grams
  Index/
    BlockIndex.php               # in-memory index of all blocks
    NgramInvertedIndex.php       # token n-gram → set of block ids
  Similarity/
    JaccardSimilarity.php        # n-gram set similarity
    TreeEditDistance.php         # bounded Zhang-Shasha refinement
    SimilarityScore.php          # combined score + confidence
  Clustering/
    Clusterer.php                # union-find over similarity edges
    Cluster.php                  # cluster value object
  Refactor/
    AntiUnifier.php              # generalize two ASTs into a template
    ParameterSynthesizer.php     # holes → typed parameter list
    SignatureBuilder.php         # PHP function signature string
    PatternRecognizer.php        # CRUD / validation / SQL templates
  Reporting/
    Report.php                   # in-memory report model
    CliReporter.php              # human-readable terminal output
    JsonReporter.php             # machine-readable
    HtmlReporter.php             # static side-by-side diff site
    Ranker.php                   # impact / similarity ordering
  Util/
    NodeTraverser.php            # convenience over PhpParser visitors
    LineRange.php                # closed-interval line range type
    Hash.php                     # stable hex digest helper

bin/
  phpdup                         # PHP shebang script

tests/
  Unit/...                       # one folder per src/ module
  Integration/                   # end-to-end on Fixtures/
  Fixtures/                      # tiny PHP corpora with known duplicates
  Benchmarks/                    # corpus-scale timing harness

docs/
  ARCHITECTURE.md (this file)
  ALGORITHMS.md                  # written in Stage 2
  CLI.md                         # written in Stage 5
  REFACTOR-PATTERNS.md           # written in Stage 4
```

No file gets written before its stage. The skeleton above is the
target, not the current state.

---

## 4. Core data structures

```php
final class Block {
    string $id;                  // stable hash of (file, startLine, kind)
    string $file;                // absolute path
    LineRange $range;            // {start, end}
    string $kind;                // 'function' | 'method' | 'if' | 'loop' | 'try' | 'closure'
    ?string $namespace;
    ?string $class;
    ?string $name;               // function/method name if any
    Node $ast;                   // original PhpParser node
    Node $canonical;             // post-normalization AST
    HoleMap $holes;              // placeholder index → original token list
    string $structuralHash;      // Merkle hash of $canonical
    NgramBag $fingerprint;       // canonical n-gram multiset
    int $size;                   // node count of $canonical (impact weight)
}

final class Cluster {
    string $id;
    Block[] $members;
    Node $generalizedAst;        // anti-unified template
    Hole[] $holes;               // positions where members differ
    float $similarity;           // min pairwise similarity within cluster
    float $confidence;           // refactor confidence 0..1
    int $impact;                 // sum of member sizes minus template size
}

final class Hole {
    string $placeholder;         // e.g. "$__P0"
    string $kind;                // 'literal' | 'identifier' | 'name' | 'call' | 'subtree'
    array $observedValues;       // original tokens per member
    ?string $inferredType;       // 'int' | 'string' | 'callable' | 'class-string' | null
    string $suggestedName;       // heuristic name e.g. "$threshold", "$role"
}
```

Every stage either consumes or produces one of these. This is the
contract surface that keeps modules independently testable.

---

## 5. Algorithms

A separate `docs/ALGORITHMS.md` will go into proofs and edge cases in
Stage 2. The summary:

### 5.1 Block extraction
Not every AST node is a useful comparison unit. A node is extracted as
a `Block` if it is one of:

- a function or method declaration,
- a closure,
- an `if`/`elseif`/`else` chain,
- a `for`/`foreach`/`while`/`do-while`,
- a `try`/`catch`/`finally`,
- a `switch`,

**and** its node count is at least `min_block_size` (default 8). The
threshold is the primary noise-suppression knob — it's why we don't
flag every two-line getter.

### 5.2 Normalization
Three orthogonal canonicalizations, each toggleable:

1. **Identifier canonicalization.** Local variables renamed in
   first-occurrence order to `$__V0`, `$__V1`, … Parameter names,
   property names, and method names are kept on the first pass to
   preserve "shape" but optionally collapsed in a stricter mode.
2. **Literal canonicalization.** Scalar literals replaced with typed
   placeholders (`__INT`, `__STR`, `__FLOAT`, `__BOOL`, `__NULL`).
   Original values are retained in the `HoleMap` so anti-unification
   can recover them.
3. **Name canonicalization.** Function calls, method calls, class
   constants, and array string keys are replaced with role-typed
   placeholders (`__CALL0`, `__KEY0`, …). This is where the tool earns
   its keep — it's what lets two SQL-builder functions with different
   table names cluster together.

Modes: `strict` (only #1), `default` (#1 + #2), `aggressive` (#1 + #2 + #3).
`aggressive` is the recommended setting and is what produces the most
interesting refactor suggestions.

### 5.3 Fingerprinting
Two fingerprints per block:

- **Structural hash.** Post-order Merkle hash of the canonical AST.
  Equal hashes ⇒ structurally identical canonical trees ⇒ exact clone
  modulo the chosen normalization mode.
- **N-gram fingerprint.** Pre-order traversal emits a token stream;
  we keep all 5-grams as a multiset. Two blocks' Jaccard similarity
  over these multisets is the cheap near-duplicate signal.

### 5.4 Clustering
Two-phase:

1. Hash buckets — blocks with identical structural hash are an exact
   cluster, no similarity computation needed.
2. Near-duplicate edges — for every block, candidates are pulled from
   the n-gram inverted index (any block sharing ≥1 rare n-gram), then
   filtered by Jaccard ≥ `similarity_threshold` (default 0.8). For
   surviving pairs we run a bounded Zhang-Shasha tree edit distance
   to compute `1 - editDistance / max(size_a, size_b)`. Pairs above
   `tree_threshold` (default 0.85) become edges.

A union-find produces clusters from the edges. Per-cluster minimum
pairwise similarity is the cluster's similarity score.

### 5.5 Anti-unification
For each cluster we compute the most specific generalization of its
members' canonical ASTs. The classic algorithm:

```
au(t1, t2):
  if root(t1) == root(t2) and arity(t1) == arity(t2):
    return Node(root(t1), [au(c1_i, c2_i) for i])
  else:
    return Hole(observed=[t1, t2])
```

Extended for clusters of size > 2 by folding pairwise. The resulting
template tree contains `Hole` nodes wherever members disagreed — those
are exactly the candidate parameters.

### 5.6 Parameter synthesis
For each `Hole`:

- Kind is inferred from observed AST node types (literal vs identifier
  vs call vs subtree).
- Type is inferred from observed values where possible (all-ints →
  `int`, all-strings → `string`, all class-strings of an existing
  class → `class-string<T>`, all callable expressions → `callable`).
- A name is heuristically chosen from observed identifiers (longest
  common substring, role-based fallback like `$threshold`, `$role`).
- The signature builder emits a PHP 8.x-style declaration.

### 5.7 Ranking
Each cluster receives an **impact score**:

```
impact = (sum of member sizes) - (template size) - (sum of hole sizes)
```

i.e. how much code disappears if the abstraction is applied. The
report sorts by impact, breaking ties by member count, then by
similarity, then by easiest-refactor heuristic (fewer holes, simpler
hole types).

A separate **confidence score** captures how safe the refactor looks:
penalize clusters whose holes include subtree-level differences,
clusters that span very different namespaces (architectural
crossing), and clusters with low minimum pairwise similarity.

### 5.8 Pattern recognition (Stage 4)
Beyond raw clusters, the recognizer tags clusters that match known
shapes:

- **SQL-builder** — body contains string concatenation feeding into a
  call whose name matches `/query|execute|prepare|fetch/i`.
- **Validation** — body is a chain of conditions each followed by a
  throw/return-error, ending in a success path.
- **CRUD** — block name and body match one of the four canonical
  shapes (insert/select-by-id/update/delete).
- **Strategy** — sibling clusters whose only hole is a method-name
  hole; suggests an interface + implementations.
- **Config-driven** — cluster whose only holes are literals; suggests
  moving the variants to a configuration array.
- **State machine** — switch/match block where each arm has a similar
  structure varying only by next-state; suggests a transition table.

These tags are *advisory*, attached to clusters, and surfaced in the
report. They don't change clustering itself.

---

## 6. Performance plan

- AST cache keyed by `sha1(file_contents) + parser_version`. Stored
  under `.phpdup-cache/` next to the project root by default.
- Block extraction and fingerprinting are CPU-bound and embarrassingly
  parallel per file. Stage 6 introduces a worker pool using `pcntl_fork`
  (fallback: serial). The expected speedup is roughly N-cores on
  cold-cache runs; warm-cache runs are I/O bound.
- The n-gram inverted index uses rare-n-gram pre-filtering: any n-gram
  occurring in more than `max_df` blocks (default 1% of corpus) is
  dropped from candidate generation. This is the standard
  large-corpus duplicate-detection trick and keeps clustering near
  linear in practice.
- Tree edit distance is bounded: we abort the DP once cost exceeds
  `(1 - tree_threshold) * max(size_a, size_b)`, so most pairs reject
  in O(1) extra work.
- Memory: blocks are streamed through the pipeline. Only fingerprints
  and minimal `Block` metadata stay in RAM; the original AST is
  re-loaded from cache when synthesizing the report.

---

## 7. Tech choices

- **Parser:** `nikic/php-parser` ^5.0. Battle-tested, handles PHP 8.x,
  exposes the full node hierarchy with line metadata. Required.
- **CLI:** `symfony/console` ^7.0. Standard.
- **Hashing:** `hash('xxh128', …)` for fingerprints (fast,
  collision-safe at this scale). PHP 8.1+.
- **Templating for HTML report:** plain PHP templates. No Smarty, no
  Twig — keeps the tool dependency-light and easy to embed.
- **Diff for side-by-side view:** `sebastian/diff`.
- **Tests:** `phpunit/phpunit` ^10.
- **Static analysis on the tool itself:** `vimeo/psalm` level 4.
- No tree-sitter. The PHP-parser AST is richer than what tree-sitter
  would give us for PHP, and we don't need cross-language support.

`composer.json` will be created in Stage 1 — not in this doc.

---

## 8. Configuration surface

A `phpdup.json` at the analyzed project's root (or `--config`) drives
behavior. CLI flags override config values.

```json
{
  "paths":   ["include", "scripts", "public_html"],
  "exclude": ["vendor/**", "node_modules/**", "logs/**", "**/*.tpl.php"],
  "min_block_size":       8,
  "normalization_mode":   "aggressive",
  "similarity_threshold": 0.80,
  "tree_threshold":       0.85,
  "min_cluster_impact":   20,
  "max_df":               0.01,
  "cache_dir":            ".phpdup-cache",
  "parallelism":          "auto",
  "report":               { "html": "phpdup-report/", "json": "phpdup.json" }
}
```

Defaults are tuned to be quiet — the user should expect a small,
meaningful report on first run, not a wall of getter/setter noise.

---

## 9. Output expectations

Given two methods like:

```php
public function notifyHigh($user, $score) {
    if ($score > 10) { $this->mailer->send('admin', $user); }
}
public function notifyMid($user, $score) {
    if ($score > 20) { $this->mailer->send('moderator', $user); }
}
```

CLI output should look like:

```
═════════════════════════════════════════════════════════════
  Cluster #3   similarity 0.96   impact 12   members 2
─────────────────────────────────────────────────────────────
  src/Notify.php:14-16   notifyHigh
  src/Notify.php:20-22   notifyMid

  Suggested abstraction:
    function notifyByThreshold(
        User $user,
        int $score,
        int $threshold,
        string $role,
    ): void

  Holes:
    $threshold  int     observed: 10, 20
    $role       string  observed: "admin", "moderator"

  Pattern: threshold-gated notification
  Confidence: 0.93
═════════════════════════════════════════════════════════════
```

JSON output is the same data, machine-readable. HTML output adds a
side-by-side colored diff with hole positions highlighted.

---

## 10. Test strategy

- **Unit tests** per module against fixed AST inputs.
- **Golden-file integration tests**: each fixture under
  `tests/Fixtures/CaseN/` has a `expected.json` with the cluster
  output the tool must produce. Diffing detects regressions.
- **Property tests** (light): the canonicalizer is involutive on
  already-canonical inputs; the n-gram fingerprint is stable under
  variable renaming; anti-unification of a tree with itself is the
  identity.
- **Negative corpus**: fixtures known *not* to be duplicates
  (e.g. a controller and a totally unrelated cron) must not cluster.

---

## 11. Risks and mitigations

| Risk                                                                 | Mitigation                                                                                                                  |
|----------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| Aggressive normalization clusters semantically different code        | `confidence` score + side-by-side diff in HTML report; the user always sees the original code, never just the template     |
| Tree edit distance is O(n³) and blows up on huge functions           | Bounded DP + a hard `max_block_size` (default 800 nodes) above which we fall back to n-gram similarity only                 |
| nikic/php-parser version drift breaks AST-cache keys                 | Cache key includes parser version; cache invalidated automatically                                                          |
| Refactor suggestions look plausible but are wrong (variable scoping) | Tool never rewrites code. Suggestions are explicitly labeled as advisory. Confidence score and diff make review fast.       |
| First-run report is noisy                                            | Default thresholds tuned conservatively; `min_cluster_impact` filters tiny wins; ranking puts the worst offenders first    |
| Parallelism via `pcntl_fork` not available on Windows                | Detect at runtime; fall back to serial. Documented.                                                                         |

---

## 12. Staged implementation plan

Each stage ends in a reviewable, runnable artifact. Nothing is
"finished" until its tests are green. We do not pause between stages for
your review.

### Stage 0 — Architecture (this document)
Status: **delivered with this commit**. Decision points captured;
nothing built yet.

### Stage 1 — Skeleton + scanner + parser
- `composer.json`, autoloader, `bin/phpdup` entry point.
- `Cli/Command.php` with a single `analyze` subcommand that, for now,
  walks files and prints "found N PHP files".
- `Scanning/FileScanner.php` with include/exclude globs and the
  default ignore list.
- `Parsing/AstParser.php` + `Parsing/AstCache.php`.
- `Extraction/BlockExtractor.php` returning `Block[]`.
- PHPUnit set up with one fixture and unit tests for scanner,
  parser, and extractor.

End-of-stage demo: `bin/phpdup analyze /home/sites/mystage/include`
prints the count of PHP files, total blocks, and a histogram of block
kinds. No detection yet.

### Stage 2 — Normalization + fingerprinting + exact-clone clustering
- `Normalization/*` (all three canonicalizers, `aggressive` mode).
- `Fingerprint/SubtreeHasher.php`, `Fingerprint/NgramFingerprint.php`.
- `Index/BlockIndex.php` with hash buckets.
- `Clustering/Clusterer.php` exact-clone-only path.
- `Reporting/CliReporter.php` minimal output.
- Golden fixtures for exact clones and identifier-renamed clones.

End-of-stage demo: tool reports exact and renamed-variable clones on
a real subtree of `mystage/include`.

### Stage 3 — Near-duplicate detection
- `Index/NgramInvertedIndex.php` with rare-n-gram filter.
- `Similarity/JaccardSimilarity.php`.
- `Similarity/TreeEditDistance.php` with bounded DP.
- `Clustering/Clusterer.php` extended to union-find over similarity edges.
- New fixtures for near-duplicates (different literals, different keys).

End-of-stage demo: tool catches the example in §9 (different threshold,
different role) and reports it as one cluster.

### Stage 4 — Anti-unification + refactor synthesis + pattern recognition
- `Refactor/AntiUnifier.php`.
- `Refactor/ParameterSynthesizer.php`, `Refactor/SignatureBuilder.php`.
- `Refactor/PatternRecognizer.php` (SQL/validation/CRUD/strategy/config/state-machine tags).
- `Reporting/CliReporter.php` upgraded to the format in §9.
- `Reporting/Ranker.php` impact + confidence scoring.

End-of-stage demo: end-to-end CLI matching the §9 example output.

### Stage 5 — JSON + HTML reports
- `Reporting/JsonReporter.php`.
- `Reporting/HtmlReporter.php` with side-by-side diff (uses
  `sebastian/diff`) and hole highlighting.
- `docs/CLI.md` written.

End-of-stage demo: a browsable static site under `phpdup-report/`.

### Stage 6 — Performance + caching + parallelism
- `Parsing/AstCache.php` populated and benchmarked.
- Worker pool over `pcntl_fork` with serial fallback.
- `tests/Benchmarks/` harness with timing on the MyAdmin tree.
- Tuning pass on default thresholds based on the benchmark output.

End-of-stage demo: cold-cache and warm-cache wallclock numbers in
`docs/BENCHMARKS.md`, plus a real run against
`/home/sites/mystage/include` with the report committed for posterity.

### Optional Stage 7 — incremental indexing, IDE integration
Out of scope for the initial build. Mentioned only so it's clear what
we're *not* doing in 1–6.

---

## 13. Open design questions

These are the calls I'd like your input on before Stage 1. I have
defaults for each so we can move forward if you don't care:

1. **Default normalization mode.** `aggressive` (most useful refactor
   suggestions, slightly higher false-cluster rate) vs `default`
   (safer, less interesting). My recommendation: ship `aggressive` as
   the default; users who want fewer false positives can drop to
   `default`.

2. **Class/interface aware clustering.** Should two blocks that live
   in classes implementing the same interface be more eagerly
   clustered? I'd skip this in Stage 2–4 and revisit in Stage 7. Lots
   of complexity for marginal value.

3. **Should the HTML report include the original (un-normalized)
   source side-by-side, or the canonical form?** My recommendation:
   original source, with hole positions highlighted. The canonical
   form is for debugging the tool, not for users.

4. **Where to store the AST cache.** Per-project `.phpdup-cache/` is
   the obvious answer; alternative is `~/.cache/phpdup/`. I prefer
   per-project for ease of cleanup. Add to project's `.gitignore`
   automatically? My take: print a one-line notice on first run, let
   the user gitignore it themselves.

5. **Pattern-recognition tags as Stage 4 or Stage 7?** They're
   advisory only and add noise to scope. I'd defer to a "Stage 4.5"
   that we decide on after seeing Stage 4 output on real code.

If you have no opinion on any of the above, the recommendations
stand and I'll proceed with Stage 1.
