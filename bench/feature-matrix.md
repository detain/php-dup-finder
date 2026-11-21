# Feature comparison: phpdup vs alternatives

Hand-curated comparison of PHP duplicate-detection tools. The matrix
is intentionally honest — phpdup is not the best tool in every cell.

**Numbers policy (once-S85):** every measured figure below cites a raw
artifact committed under `bench/results/` (recording host/PHP/git sha
inside the JSON; single-shot, see its fairness notes). Figures that no
committed run backs are labelled *not measured* — never stated as
numbers. For raw wall-time and memory data see
`bench/results/s85-comparative-tests-fixtures.md` (+ `.json`);
`bench/run-all.sh` regenerates working renderings at
`bench/results/latest.md` / `detection-rate.md` (uncommitted unless
labeled and force-committed with the claims they back).

Tools evaluated:

| Tool        | Origin | Last release (as of 2026-05) | Language       |
|-------------|--------|------------------------------|----------------|
| **phpdup**  | this repo | 0.1.x active                | PHP            |
| phpcpd      | sebastianbergmann | 6.0.3 (archived 2023, no longer maintained) | PHP |
| pmd-cpd     | PMD    | 7.x active                   | Java           |
| jscpd       | kucherenko | 4.x active                | Node/TS        |
| simian      | RedHill | commercial, sporadic         | Java           |

## Capability matrix

Legend: ✓ = full support · ✗ = not supported · partial (note) ·
N/A = not applicable.

| Capability                              | phpdup | phpcpd | pmd-cpd | jscpd | simian |
|-----------------------------------------|:------:|:------:|:-------:|:-----:|:------:|
| Type-1 (exact text) clones              | ✓      | ✓      | ✓       | ✓     | ✓      |
| Type-2 (renamed identifiers / literals) | ✓      | partial (`--fuzzy`) | partial (token stream) | partial | partial |
| Type-3 (gapped, optional segments)      | ✓      | ✗      | ✗       | ✗     | ✗      |
| Type-4 (semantic equivalence)           | partial (heuristic via tags) | ✗ | ✗ | ✗ | ✗ |
| AST-based matching                      | ✓      | ✗ (token) | ✗ (token) | ✗ (token) | ✗ (line) |
| Anti-unification → suggested abstraction| ✓      | ✗      | ✗       | ✗     | ✗      |
| Pattern-tag classification              | ✓ (sql-builder, crud-handler, validation-chain, …) | ✗ | ✗ | ✗ | ✗ |
| Refactor-patch generation               | ✓ (`--refactor-patch`) | ✗ | ✗ | ✗ | ✗ |
| Architectural finding annotations       | ✓ (e.g. primitive-obsession) | ✗ | ✗ | ✗ | ✗ |
| Safety / confidence scoring             | ✓ per-cluster | ✗ | ✗ | partial | ✗ |
| HTML report                             | ✓      | ✗      | ✓       | ✓     | ✗      |
| JSON report                             | ✓      | ✗ (PMD XML only) | ✓ | ✓ | ✗ |
| SARIF (PR annotations)                  | ✓      | ✗      | ✗       | ✗     | ✗      |
| GitLab SAST                             | ✓      | ✗      | ✗       | ✗     | ✗      |
| Checkstyle XML                          | ✓      | ✗      | ✓       | ✓     | ✗      |
| CSV output                              | ✓      | ✗      | ✓ (csv) | ✗     | ✗      |
| Prometheus / metrics                    | ✓      | ✗      | ✗       | ✗     | ✗      |
| Time-series (commit-tagged)             | ✓ (`--timeseries`) | ✗ | ✗ | ✗ | ✗ |
| Graphviz / PlantUML diagrams            | ✓      | ✗      | ✗       | ✗     | ✗      |
| Per-cluster unified diffs               | ✓      | ✗      | ✗       | ✗     | ✗      |
| GitHub Actions integration              | ✓ (composite action) | partial (community) | partial | ✓ (action) | ✗ |
| GitLab CI template                      | ✓ (.gitlab/phpdup-ci.yml) | ✗ | partial | partial | ✗ |
| JetBrains plugin                        | ✗      | ✗      | ✓ (PMD plugin) | ✗ | ✓ |
| Watch mode (auto re-run on change)      | ✓ (`--watch`) | ✗ | ✗ | ✗ | ✗ |
| TUI / live progress                     | ✓ (SugarCraft) | ✗ | ✗ | partial (spinner) | ✗ |
| Parallel scan (multi-core)              | ✓ (`pcntl_fork` worker pool) | ✗ | ✓ | partial | ✓ |
| Incremental indexing                    | ✓ per-file snapshot cache | ✗ | ✗ | ✗ | ✗ |
| ORM-aware dedup                         | ✗ (planned)   | ✗ | ✗ | ✗ | ✗ |
| Configurable normalization (strict/aggr)| ✓      | partial (`--fuzzy`) | partial | partial | partial |
| User-defined normalization plugins      | ✓      | ✗      | ✗       | ✗     | ✗      |
| Per-directory config overrides          | ✓ (.phpdup.json) | ✗ | ✗ | ✗ | ✗ |
| Project profiles (Laravel, Symfony, …)  | ✓      | ✗      | ✗       | ✗     | ✗      |
| Auto-tune by corpus size                | ✓      | ✗      | ✗       | ✗     | ✗      |
| Memory footprint (small corpus) | ~83 MB peak (measured, committed run above) | not measured (no committed run; binary absent on recording host) | medium (JVM warmup) | medium-high (V8 heap) | medium |
| Memory footprint (multi-MLOC)           | medium (lazy AST + incremental) | low | high | high | medium |
| PHP 8.x support                         | ✓ (8.1+) | partial (no PHP 8.2+ tokens beyond v6) | ✓ via Java | ✓ | partial |
| Maintenance status                      | active | **archived 2023** | active | active | sporadic |

## Honest take

**Where phpdup wins:**

- Suggested-refactor output. No other tool produces a parameterised
  function signature, hole inventory, and unified-diff patches. If
  your goal is "I want to refactor the duplicates", phpdup is the
  only practical choice.
- Type-3 detection. `--optional-blocks` finds clones that differ in
  optional segments — phpcpd / pmd-cpd / jscpd require contiguous
  identical token runs and miss this entirely.
- Reporter coverage. phpdup ships 12 output formats including SARIF,
  GitLab SAST, Prometheus, Graphviz, and time-series JSONL. None of
  the others ship more than three.
- Pattern-tag classification. Tagging clusters as `sql-builder`,
  `crud-handler`, `validation-chain`, etc. is unique and lets you
  filter / route findings by type. The other tools give you "lines
  N–M match lines P–Q" and stop.
- Live workflow. `--watch` + TUI is for the inner-loop developer who
  wants feedback as they refactor; nothing else covers this.
- Honesty. phpdup ships a synthetic-fuzz ground-truth scorer
  (`bench/score.php`, family-granular and containment-aware) and an
  audit-truth scorer (`bench/score-audit.php`) that separates
  **line-evidence detections from file-level grazes** and gates
  unverified truth tokens out of every denominator (once-106).
  Comparative rows show `timeout` / `exit-N` / `not installed` as
  themselves — never as a 0.00 measurement (once-107) — and every
  published figure cites a raw JSON committed under `bench/results/`.
  The other tools publish no such numbers at all.

**Where phpdup loses:**

- Pure speed for exact clones. phpcpd's tokenizer is dead simple
  and beats phpdup's AST + APTED pipeline on raw wall time when all
  you want is "is anything copy-pasted verbatim?". Use
  `phpdup --exact-only` to close most of that gap. (The previously
  cited "phpcpd ~120 ms vs phpdup ~380 ms" figures were **removed as
  phantom** — no committed run ever backed them and phpcpd is not
  installed on the recording host. The only measured figure on record
  is phpdup's own cold run: **0.97 s wall, 83.3 MB peak, 18 pipeline
  clusters** on `tests/Fixtures` (20 PHP files) — see
  `bench/results/s85-comparative-tests-fixtures.json`. A like-for-like
  phpcpd column needs a `bench/run-all.sh` run with the phar fetched;
  until such a raw JSON is committed, no phpcpd number belongs here.)
- Cold-start RSS for tiny inputs. phpdup peaks ~83 MB because it loads
  php-parser, Symfony Console, and the whole pipeline scaffolding
  (measured: 83.3 MB on `tests/Fixtures`, committed run above). The
  former "phpcpd peaks ~50 MB; phpdup peaks ~60–80 MB" row was phantom
  and is dropped; measure phpcpd via a committed run before restating.
- Mature IDE integration. PMD has a JetBrains plugin going back over
  a decade; phpdup has none yet. If your team already lives inside
  PhpStorm's PMD inspections, that's a real lock-in.
- Multi-language support. jscpd handles PHP, JS/TS, Python, Java,
  Ruby, Go, Rust, etc. from a single binary. phpdup is PHP-only by
  design.
- Maintenance perception. phpcpd is archived but it's the historical
  default — many CI pipelines still run it. New tools fight history.

**Choose phpdup when:** you want refactor-actionable output, type-3
detection, pattern classification, or any of the unique reporters.

**Choose phpcpd when:** you only need a fast exact-clone gate in CI
and don't care about the long-term lack of maintenance.

**Choose pmd-cpd when:** you already use PMD for static analysis,
want a JetBrains plugin, or have a polyglot codebase that includes
Java/Apex/Salesforce code along with PHP.

**Choose jscpd when:** the codebase is multi-language and you want
one tool to cover everything.

**Choose simian when:** … you probably won't. It's commercial,
language-agnostic, line-based, and has fallen behind the open-source
alternatives in every cell that matters.
