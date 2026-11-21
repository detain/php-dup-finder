# CLI reference

Standalone reference for every flag and sub-command in `bin/phpdup`.
The high-level walkthroughs live in the project [README](../README.md);
this file is the look-up table.

```
Usage:
  phpdup analyze <paths...> [options]
  phpdup completion [<shell>]
```

## Sub-commands

| Command       | Description                                                                  |
|---------------|------------------------------------------------------------------------------|
| `analyze`     | Scan paths for duplicate logic. The default command — `bin/phpdup src` runs it. |
| `completion`  | Dump a bash / fish / zsh completion script with commented-out install instructions inline. |
| `help`        | Symfony Console's built-in help. `bin/phpdup help analyze` lists every flag. |
| `list`        | List every registered command.                                               |

## `analyze` — arguments

| Argument | Description                                                                |
|----------|----------------------------------------------------------------------------|
| `paths`  | One or more directories (or files) to scan. Required unless `--validate-config` is set. Accepts repeats: `bin/phpdup analyze src lib`. |

## `analyze` — options

Defaults shown below come from `Phpdup\Cli\Config`. They are overridable
via a `phpdup.json` config file (lower precedence) and CLI flags
(higher precedence). See [Configuration file](#configuration-file)
below for the file format and [`docs/config-schema.json`](config-schema.json)
for the full JSON-Schema-compatible spec.

### Tuning

| Option                                     | Default        | Description                                                                                       |
|--------------------------------------------|----------------|---------------------------------------------------------------------------------------------------|
| `--min-block-size N`                       | `8`            | Minimum AST node count for a block to be considered. Lower = noisy; higher = quieter.             |
| `--mode MODE`                              | `aggressive`   | Normalization mode: `strict` \| `default` \| `aggressive`.                                        |
| `--similarity N`                           | `0.80`         | Jaccard similarity threshold for near-duplicate pairs (`0..1`).                                   |
| `--max-df N`                               | `0.01`         | Maximum document-frequency for n-grams to be candidate-pair seeds (`0..1`). Bump to ~0.5 for tiny corpora; lower for very large codebases. |
| `--candidate-budget N`                     | `60000000`     | Safety cap on candidate pairs emitted during clustering. A pathological clone family halts loudly (`CandidateBudgetException`) instead of exhausting memory. `0` = unlimited (opts out of the guard). The guard can only fire where candidate pairs are actually seeded: on a tiny all-identical corpus the default `--max-df 0.01` rejects the shared n-gram postings as too common, so nothing is seeded and the halt never arms — raise `--max-df` (e.g. `--max-df 1.0`) to exercise it. |
| `--min-impact N`                           | `20`           | Minimum cluster impact (≈ duplicated-line count) to include in output. Quiets reports without changing clustering. |
| `--fail-on-impact N`                       | `0` (off)      | CI gate: exit code 3 when total cluster impact exceeds N. 0 = disabled. |
| `--max-clusters N`                         | `0` (off)      | CI gate: exit code 3 when cluster count exceeds N. 0 = disabled. |
| `--exact-only`                             | off            | Skip the near-duplicate phase. Emits only canonical-hash-equal clusters (~6× faster on large corpora). |
| `--kinds K1,K2,...`                        | all            | Comma-separated block kinds to extract. Allowed: `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`, plus `script` (item 1.3: top-level statement folds — OPT-IN ONLY, never part of the implicit "all"; script clusters cannot be `--apply`-ed yet). |
| `--max-memory MB`                          | `0` (off)      | Soft RSS ceiling. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests `--exact-only`. |
| `--optional-blocks on\|off`                | `on`           | Type-3 / "optional segment" detection master switch. See [Type-3 detection](../README.md#type-3--optional-segment-detection). |
| `--optional-blocks-containment N`          | `0.85`         | Containment-fallback threshold for the type-3 path (`0..1`).                                      |
| `--db-aware`                               | off            | ORM-/DB-aware semantic deduplication: rewrite recognised database calls (Eloquent, Doctrine, query builders, PDO, mysqli, pg_*, raw SQL strings) to canonical `__DB_<OP>__("table")` tokens during normalisation so equivalent ORM/raw-SQL variants of the same operation cluster together. Off by default — opt-in for ORM-heavy codebases. See [`docs/plans/orm-db-semantic-dedup.md`](plans/orm-db-semantic-dedup.md). |
| `--trinity-collapse`                       | off            | Detect the canonical CRUD trinity (read → mutate → save) and rewrite the three-statement sequence as a single `__DB_UPSERT__("entity")` synthetic call so ORM upserts cluster with raw `UPDATE` queries. Composes with `--db-aware`. Off by default. See option 2 of [`docs/plans/orm-db-semantic-dedup.md`](plans/orm-db-semantic-dedup.md). |
| `--scorer MODE`                            | —              | Scoring tier set: `default` (stock AST tiers) or `ir` (option-5 IR-tier fallback). When the flag is absent the config-file `scorer` (or the built-in `default`) applies — it used to be shadowed by an always-injected CLI default (NN1). When `ir`, after AST Jaccard / TED / containment all reject a pair, both blocks are lifted to canonical IR (`Phpdup\Ir\IrLifter`) and their token bags scored by multiset Jaccard; pairs at or above `--ir-threshold` form edges weighted by the IR similarity. Lift failure on either side silently skips the IR tier for that pair. |
| `--ir-threshold N`                         | `0.85`         | Multiset-Jaccard threshold for the option-5 IR tier (`0..1`). Only consulted when `--scorer=ir`. |
| `--ml-pair-url URL`                        | —              | External pair-similarity ML sidecar URL (option 6 of the ORM dedup plan). Empty (the default) disables the tier. When set, the very last clustering tier — runs after structural-hash, AST Jaccard + TED, containment, and IR all reject. POSTs a `PairFeatures` vector to `<URL>/score-pair`; pairs at or above `--ml-pair-threshold` form edges. http(s) only; SSRF-hardened (`0.0.0.0` rejected). Returns null on transport failure so unavailability never breaks the run. |
| `--ml-pair-threshold N`                    | `0.80`         | Similarity threshold (`0..1`) for the option-6 ML pair tier. |
| `--score-mode MODE`                        | `global`       | Scoring stance for cross-repo clones (item 5.1): `global` \| `per-repo` \| `typed`. `global` runs the legacy confidence/safety math byte-identically (every golden, baseline and gate history is stable under it). `per-repo` evaluates the ">3 namespaces" penalty on the namespace spread within the densest member repo, so a subsystem mirrored across packages is no longer double-penalized for its mirroring. `typed` (audit posture) replaces that penalty for cross-repo clusters with an inverse bonus (+0.05 confidence / +0.10 safety): one shared helper erases N repo-local copies. Single-repo clusters score identically under every mode. Repo labels come from the scan roots — analyze multiple repositories by passing one path per repo. Root config only — per-directory `.phpdup.json` files carrying this key fail the load. |
| `--max-hole-coverage N`                    | `1.0`          | Extraction-quality stance (item 5.4): when a cluster's generalized template is MORE than this fraction (`0..1`) hole, the report marks it `re_expressed` and surfaces its longest hole-free statement run (`hole_fragment`) instead of letting a hole-riddled signature pass as a clean abstraction. Default `1.0` disables the rule — coverage can never exceed it, so every historical report is byte-identical. Evidence is annotated, never dropped (the plan's early ">40% holes → discard" draft was retired as a silent-evidence-loss design). Root config only, like `max_ted_size`. |
| `--profile NAME`                           | —              | Apply a project profile (`laravel`, `symfony`, `drupal`, `wordpress`, `generic`, `db-aware-laravel`, `db-aware-doctrine`, `db-aware-cake`, `db-aware-thinkorm`, `db-aware-medoo`, `db-aware-propel`, `db-aware-redbean`, `db-aware-cycle`, `db-aware-phpactiverecord`, or `auto`). Project profiles seed framework-aware excludes + tuning; `db-aware-*` profiles ship symbol-equivalence packs (option 4 of the ORM dedup plan) that extend the stock DB registry with framework-specific call shapes. Explicit CLI flags + `--config` win over profile values. |

### Output

| Option                                     | Default | Description                                                                            |
|--------------------------------------------|---------|----------------------------------------------------------------------------------------|
| `--html DIR`                               | —       | Write the interactive HTML report into DIR.                                            |
| `--json FILE`                              | —       | Write the structured JSON dump to FILE.                                                |
| `--sarif FILE`                             | —       | Write a SARIF 2.1.0 report to FILE (GitHub Code Scanning, GitLab Code Quality).        |
| `--gitlab-sast FILE`                       | —       | Write a GitLab SAST v15.x report to FILE.                                              |
| `--diff DIR`                               | —       | Write one `.diff` per cluster (pairwise from member[0]) into DIR.                      |
| `--patch FILE`                             | —       | Write a single cumulative patch file containing every cluster diff.                    |
| `--checkstyle FILE`                        | —       | Write Checkstyle XML to FILE (Jenkins / Sonar / Bitbucket consumers).                  |
| `--baseline FILE`                          | —       | CI baseline file. If FILE exists: compare and exit 4 if new clusters found. If FILE does not exist: write baseline and exit 0 (first-run auto-baseline). |
| `--baseline-out FILE`                      | —       | Write current clusters as a baseline snapshot to FILE (overwrites existing).           |
| `--limit N`                                | `50`    | Maximum number of clusters to print to the terminal. Doesn't affect file outputs.       |
| `--scope VALUE`                            | `all`   | Repo scope filter (item 5.1): `all` \| `single` \| `multi` \| `<repo-label>`. Every cluster member carries a `repo` label (the basename of the longest-matching scan root) and clusters gain a `scope` of `single` (one repo) or `multi` (members span ≥2 distinct repos). `single`/`multi` keep the matching clusters; any other value is treated as an exact repo label and keeps clusters with at least one member from that repo. Applied after ranking, so `--limit`, `--sort` and the CI gates all measure the filtered slice. A typo'd label matches nothing: the run still exits 0 but prints one stderr hint listing the repo labels present. Cross-repo analysis means one scan path per repository. Root config only — per-directory `.phpdup.json` files carrying this key fail the load. |
| `--sort KEY[:asc\|desc]`                   | `impact:desc` | Cluster sort. Keys: `impact`, `members`, `block-size`, `lines`, `similarity`, `confidence`, `name`, `file`, `id`. Aliases: `size`/`count` → `members`. Direction may also be given as a leading `-` (desc) or `+` (asc), e.g. `-members`, `+lines`. |
| `--stats`                                  | off     | Print pipeline stage timings, block-kind histogram, and worker info after the report.  |

Naming posture for the generated-code surfaces (`--apply`, `--refactor-patch`, `--refactor-tests`): the first cluster to claim a function name keeps it verbatim — including ungainly-but-legal names such as `__construct` — and later collisions gain a suffix derived from their content cluster id.

### What the file counters mean

`analyze` buckets **every scanned file into exactly one** category. The
preprocess summary line, the CLI `files …` accounting line and the JSON
`summary` object (schema 1.2; `JsonSchemaSpec::summaryShape()`) all label
these buckets identically:

| Bucket    | Summary / accounting label | JSON key                          | Meaning                                                                                   |
|-----------|----------------------------|-----------------------------------|-------------------------------------------------------------------------------------------|
| scanned   | `N files` / `N scanned`    | `summary.files` / `files_scanned` | Every file the scanner handed to preprocessing.                                            |
| reused    | `N reused`                 | `summary.files_reused`            | Blocks came from the per-file index cache; the file was not re-parsed.                     |
| processed | `N processed`              | `summary.files_processed`         | Freshly parsed and produced at least one comparable block.                                 |
| no blocks | `N no blocks`              | `summary.files_without_blocks`    | Parsed cleanly but contained no extractable blocks (enums, interfaces, methods-less classes, config arrays). |
| errors    | `N parse errors`           | `summary.parse_errors`            | The file could not be parsed or failed mid-preprocessing.                                  |

**Balance law:** `scanned = reused + processed + no blocks + parse errors`.
The historical `summary.files` key keeps its meaning (scanned); `null` in
the three new counter keys means the producer does not track the bucket
(built-in library path) — distinct from a tracked `0`.

When files skipped extraction or failed to parse, the final verdict is no
longer a bare `clean.`: it reads
`no clusters above threshold — but N of M files produced nothing
comparable.` so a corpus that never entered the comparison cannot report
silent success.

### Runtime

| Option                                     | Default     | Description                                                                                            |
|--------------------------------------------|-------------|--------------------------------------------------------------------------------------------------------|
| `-c, --config FILE`                        | —           | Load settings from a `phpdup.json` config file. CLI flags override file values.                        |
| `-j, --workers N`                          | `0` (auto)  | Worker count for parallel preprocess + pair scoring. `0` = auto-detect from `nproc` / `/proc/cpuinfo`. `1` = serial. |
| `--no-cache`                               | off         | Skip AST **index reuse** for this run: the per-file incremental cache (`IndexStore`) is not read, and the refactor stage does not reload cached ASTs. **Note:** this does not suppress writes — the parser's on-disk AST cache under `cache_dir` is still populated during the run; set `cache_dir` to a throwaway path (e.g. a temp directory) if you need nothing written to disk. |
| `--no-incremental`                         | off         | Disable per-file index-snapshot reuse. Forces re-fingerprinting every file.                            |
| `--no-lazy-ast`                            | off         | Keep all original ASTs in memory throughout the run. Higher RSS, slightly faster anti-unification.     |
| `--low-memory`                             | off         | Use CompactNgramBag (32-bit fingerprint) + CanonicalNodePool interning for lower RSS on large corpora. |
| `--stage NAME`                             | —           | Halt the pipeline after STAGE: `scanning` \| `preprocessing` \| `clustering` \| `refactoring` \| `reporting`. |
| `--debug-log FILE`                        | —           | Append every debug (vvv) pipeline message to FILE. Messages are also emitted to stderr when `-vvv` is set; this flag ensures they are preserved to disk for post-run inspection. |
| `--diff-base REF`                          | —           | Git ref for diff-scoped analysis. When set, scans only files changed in `git diff --name-only <REF>..HEAD` plus their "clone cohort" — all files sharing n-gram fingerprints with the changed files. Useful for per-PR analysis in CI to limit scope to only affected files and their clones. |

### TUI / watch

| Option                                     | Default | Description                                                                                          |
|--------------------------------------------|---------|------------------------------------------------------------------------------------------------------|
| `--tui`                                    | off     | Show the live SugarCraft dashboard while analysis runs. Requires a real TTY.                         |
| `--theme NAME`                             | `ansi`  | TUI theme: `ansi` \| `plain` \| `charm` \| `dracula` \| `nord` \| `catppuccin`.                      |
| `--plain`                                  | off     | Force plain CLI output (no TUI, no ANSI colours). Useful when CI shells report `isatty()=true`.      |
| `--watch`                                  | off     | Stay running and re-analyze on file changes via a poll-based `React\EventLoop` timer (1.5 s default poll). Combines with `--tui`. |

### CI / Baseline

The `--baseline` and `--baseline-out` flags implement an incremental CI gate
workflow: accept the current state of duplication as a baseline, then fail the
CI build only when **new** duplicate clusters appear in subsequent runs.

**How it works:**

- `member_hashes` are the primary identity key. They are SHA-256 fingerprints
  computed from `sha256(file_path + start_line + end_line)` for each block
  in a cluster. They are stable across runs (not dependent on cluster IDs,
  which may change as clustering algorithms evolve).
- A cluster is considered **new** if its member hashes are not a subset of
  any baseline cluster's member hashes. This means a baseline cluster may
  grow (new members added) without triggering a new-duplicate gate.

**Workflow:**

1. **First run — create baseline:**
   ```bash
   bin/phpdup analyze src --baseline baseline.json
   # If baseline.json does not exist: writes baseline, exits 0
   ```

2. **Subsequent runs — detect regressions:**
   ```bash
   bin/phpdup analyze src --baseline baseline.json
   # Exit 0 if no new clusters
   # Exit 4 if new clusters found (CI gate failed)
   ```

3. **Explicit baseline update:**
   ```bash
   bin/phpdup analyze src --baseline-out baseline.json
   # Always writes baseline (overwrites existing), exits 0
   ```

**Exit codes:**

| Code | Condition                                                            |
|------|----------------------------------------------------------------------|
| `0`  | Normal run, OR baseline written (first run / --baseline-out), OR `--baseline FILE` exists and no new clusters detected |
| `4`  | `--baseline FILE` exists and new duplicate clusters were found       |

**Example CI pipeline:**

```bash
# In CI: fail if new duplication introduced since main branch baseline
git fetch origin main
git diff origin/main -- baseline.json > /dev/null 2>&1 && \
    cp baseline.json baseline-branch.json || \
    cp /dev/null baseline-branch.json

bin/phpdup analyze src --baseline baseline-branch.json
STATUS=$?

# Restore main baseline
cp baseline-branch.json baseline.json

exit $STATUS
```

### Validation

| Option                                     | Default | Description                                                                              |
|--------------------------------------------|---------|------------------------------------------------------------------------------------------|
| `--validate-config`                        | off     | Validate the `--config` file against the documented schema and exit without running analysis. |

### Symfony built-ins

| Option                | Description                                                                       |
|-----------------------|-----------------------------------------------------------------------------------|
| `-h, --help`          | Show help for the current command. `bin/phpdup help analyze` works too.           |
| `-V, --version`       | Display application version.                                                      |
| `-q, --quiet`         | Suppress all output except errors.                                                |
| `-v` / `-vv` / `-vvv` | Increase verbosity.                                                               |
| `-n, --no-interaction`| Don't prompt for input.                                                           |
| `--ansi` / `--no-ansi`| Force or disable ANSI output.                                                     |

## `completion` — arguments

| Argument | Description                                                              |
|----------|--------------------------------------------------------------------------|
| `shell`  | One of `bash`, `fish`, `zsh`. When omitted, `$SHELL` is consulted.        |

The output is the standard Symfony Console completion script for the
chosen shell, **prepended with commented-out installation
instructions**. Pipe to a file or eval inline:

```bash
# bash — per-user, XDG-compliant
mkdir -p ~/.local/share/bash-completion/completions
phpdup completion bash > ~/.local/share/bash-completion/completions/phpdup

# fish — auto-loaded on next shell start
mkdir -p ~/.config/fish/completions
phpdup completion fish > ~/.config/fish/completions/phpdup.fish

# zsh — pick a directory on $fpath BEFORE compinit
mkdir -p ~/.zsh/completions
phpdup completion zsh > ~/.zsh/completions/_phpdup
# then in ~/.zshrc, before 'compinit':  fpath=(~/.zsh/completions $fpath)
```

Alternatively, `eval "$(phpdup completion bash)"` (or `fish` / `zsh`)
right inside your shell rc loads it for the current session. The
`#compdef phpdup` directive that zsh autoload requires stays the very
first line of the zsh dump.

## Exit codes

| Code | Meaning                                                                            |
|------|------------------------------------------------------------------------------------|
| `0`  | Successful run. **Note:** `analyze` does NOT exit non-zero when clusters are found (unless CI-gate thresholds are set). Gate CI on the JSON report — an empty `clusters` array means clean. |
| `1`  | Internal error (uncaught exception, reporter failure, etc.).                       |
| `2`  | Invalid input: missing required argument, unknown shell for `completion`, schema-validation failure for `--validate-config`, a non-numeric or out-of-domain value for a numeric option (e.g. `--min-block-size abc`, `--similarity 1.5`), invalid `--theme`, etc. |
| `3`  | CI gate triggered: `--fail-on-impact N` exceeded (total cluster impact > N) or `--max-clusters N` exceeded (cluster count > N). |
| `4`  | CI gate triggered: `--baseline FILE` was provided, FILE exists, and new duplicate clusters were found that were not present in the baseline. |

Numeric option values are trimmed before coercion: surrounding whitespace
is accepted (`--workers ' 4 '` is the same as `--workers 4`). A value that
is still non-numeric — or outside the option's domain — after trimming
exits `2` as above.

## Environment variables

| Variable          | Effect                                                                                  |
|-------------------|-----------------------------------------------------------------------------------------|
| `PHPDUP_WORKERS`  | Override worker count (lower precedence than `-j` / `--workers`).                       |
| `COLUMNS`         | Override terminal-width detection for the CLI report.                                   |
| `SHELL`           | Consulted by `phpdup completion` when no `shell` argument is supplied.                  |

## Examples

Default analysis of one directory:

```bash
bin/phpdup analyze src
```

Multiple directories with both human-readable reports:

```bash
bin/phpdup analyze src lib --html report --json report.json
```

Loose threshold to surface more candidates:

```bash
bin/phpdup analyze src --similarity 0.7 --min-impact 10
```

CI-style fast pass for exact clones only:

```bash
bin/phpdup analyze src --exact-only --min-impact 30 --json clones.json
test "$(jq '.clusters | length' clones.json)" -eq 0
```

Every CI-relevant format in one shot:

```bash
bin/phpdup analyze src \
    --sarif       phpdup.sarif \
    --gitlab-sast phpdup.gitlab.json \
    --diff        ./phpdup-diffs \
    --checkstyle  phpdup.xml \
    --json        phpdup.json \
    --html        phpdup-report
```

Filter to one block kind and gate on impact:

```bash
bin/phpdup analyze src --kinds=method --min-impact=50 --exact-only
```

Live-reload while you refactor (combines with `--tui` for the live dashboard):

```bash
bin/phpdup analyze src --watch
bin/phpdup analyze src --watch --tui --theme=dracula
```

Type-3 / optional-segment detection (the default) on a small fixture:

```bash
bin/phpdup analyze tests/Fixtures/optional --max-df=0.5 --min-block-size=4
```

Disable type-3 detection for a strict run:

```bash
bin/phpdup analyze src --optional-blocks=off
```

Sort by something other than impact:

```bash
bin/phpdup analyze src --sort=members:desc          # most-duplicated first
bin/phpdup analyze src --sort=block-size:desc       # largest blocks first
bin/phpdup analyze src --sort=lines                 # most duplicated lines (desc default)
bin/phpdup analyze src --sort=similarity:asc        # weakest matches first (review marginal ones)
bin/phpdup analyze src --sort=confidence:desc       # safest refactors first
bin/phpdup analyze src --sort=name:asc              # alphabetical by qualified member name
bin/phpdup analyze src --sort=file:asc              # alphabetical by source file
bin/phpdup analyze src --sort=-impact               # leading - = desc shortcut
bin/phpdup analyze src --sort=+lines                # leading + = asc shortcut
```

Performance diagnostic with stage timings:

```bash
bin/phpdup analyze src --stats --no-cache
```

Halt mid-pipeline for debugging:

```bash
bin/phpdup analyze src --stage=preprocessing --stats
```

Validate the config and exit (no analysis):

```bash
bin/phpdup analyze --config phpdup.json --validate-config && echo OK
```

Install bash completion right now:

```bash
mkdir -p ~/.local/share/bash-completion/completions
bin/phpdup completion bash > ~/.local/share/bash-completion/completions/phpdup
exec bash    # or open a new shell
```

## Configuration precedence

Every flat scalar config key follows the same three-tier precedence:

```
CLI flag  (highest)
    ↓
phpdup.json  (medium)
    ↓
Config::defaults()  (lowest)
```

`src/Cli/OverrideResolver` applies this rule for the numeric/flat keys
that share the same precedence (the table of record is the `$flatKeys`
list in `src/Cli/ConfigLoader::load()` plus `ConfigLoader::NUMERIC_KEYS` —
never hard-count it here, the list grows with each flag): `min_block_size`,
`max_block_size`,
`normalization_mode`, `similarity_threshold`, `tree_threshold`,
`min_cluster_impact`, `max_df`, `candidate_budget`, `ngram_size`, `cache_dir`, `parallelism`,
`workers`, `incremental`, `lazy_ast`, `sort`, `ted_weights`, `scorer`,
`fingerprint_id_mode`, `scope`, `score_mode`,
`ir_threshold`, `ml_pair_threshold`, `debug_log`, and `low_memory`.
`max_ted_size` joins this list at item 1.2 but has no CLI flag (config
file only — it sits alongside `fingerprint_id_mode` as a root-only key).
`max_hole_coverage` joins at item 5.4 with a CLI flag
(`--max-hole-coverage`) and the same root-only posture: a cluster spans
members from many directories, so its re-expression threshold is a stance
about the one final report, never a per-subtree tuning knob.

A few keys use different fallback paths:

| Key | Precedence chain |
|-----|-----------------|
| `allowed_kinds` | CLI → config `kinds` → the twelve traversable kinds (everything in `BlockExtractor::ALL_KINDS` except `script` — item 1.3's fold kind is opt-in only and never implicit) |
| `optional_blocks.*` | CLI → config `optional_blocks` → `Config` defaults |
| `db_aware`, `trinity_collapse` | CLI → config → `Config` defaults |
| `exclude` | APPEND: `Config` defaults + profile globs + config-file list + programmatic CLI-dict list (deduped, in that order) — never a replacement; `"exclude_reset": true` drops the inherited layers. A `"exclude": []` in any layer is a pure no-op: an empty list adds nothing, and its mere presence wipes nothing. Per-directory `.phpdup.json` lists accumulate down the tree the same way. |
| `paths` | config `paths` → `Config` defaults (CLI argument is separate) |

### `exclude` stacks — the glob dialect (item 5.2)

Explicit exclude lists APPEND to the stock defaults; a single list no
longer wipes them. The one documented escape hatch is `"exclude_reset":
true` (root config file or any per-directory file): everything inherited
below that layer (defaults, profile, ancestors) is discarded and only the
layer's own list survives.

Patterns are matched against paths relative to the scan root and are
**segment-safe** — no star form crosses `/` by accident:

| Pattern            | Meaning                                                             |
|--------------------|---------------------------------------------------------------------|
| `**/vendor/**`     | any `vendor/` directory at any depth — and never `myvendor/`         |
| `vendor/**`        | the root-level `vendor/` only (deep form needs the leading `**/`)    |
| `vendor` / `/vendor` | prunes NO directory: the pattern compiles to a whole-path match (`/vendor` is `/`-trimmed first) while directory probes carry a trailing `/`, so a bare name can only ever hit a FILE named `vendor` — pruning a directory needs `name/**` at root or `**/name/**` at any depth |
| `**` (trailing)    | everything below the preceding directory                             |
| `*`                | within one path segment (never crosses `/`)                          |
| `?`                | exactly one character in a segment                                   |
| `**` mid-segment   | degenerates to `*` (globstar convention) — `**.php` is one segment;  |
|                    | write `**/*.php` for any depth                                       |

**Defaults erratum (p5a-m1).** The 5.2 landing promised the nine stock
default excludes behave "exactly as before" the compiler rewrite. The
measured truth is eight of nine: the `**/` prefix form gained segment
anchoring, so lookalike-suffix directories (`myvendor/`,
`my.phpdup-cache/`) stop matching where the old `**` → `.*` expansion
let them through. The one stock default whose real-world result set
changes is `**/.phpdup-cache/**` — it intentionally no longer swallows a
sibling `my.phpdup-cache/` directory. The narrowing is a deliberate
false-positive sweep, not drift.

Unreadable directories are skipped with a `phpdup: warning: skipped
unreadable path … (…)` line on stderr (and recorded on the scanner's
skip-notes) instead of aborting the run.

## Configuration file

`phpdup.json` accepts the same settings as the CLI flags, plus a few
that are file-only (`paths`, `exclude`, the nested `optional_blocks`
sub-object, and the `report` sub-object). CLI flags override file
values.

```json
{
  "paths":   ["src", "app", "lib"],
  "exclude": ["**/vendor/**", "**/node_modules/**", "**/*.tpl.php", "**/tests/**"],
  "min_block_size":       8,
  "max_block_size":       800,
  "normalization_mode":   "aggressive",
  "similarity_threshold": 0.80,
  "tree_threshold":       0.85,
  "min_cluster_impact":   20,
  "max_df":               0.01,
  "candidate_budget":     60000000,
  "ngram_size":           5,
  "fingerprint_id_mode":  "numbered",
  "scope":                "all",
  "score_mode":           "global",
  "cache_dir":            ".phpdup-cache",
  "parallelism":          "auto",
  "workers":              0,
  "incremental":          true,
  "lazy_ast":             true,
  "kinds":                ["method", "closure"],
  "sort":                 "impact:desc",
  "optional_blocks": {
    "enabled":             true,
    "containment":         0.85,
    "min_overlap":         0.6,
    "max_per_cluster":     3,
    "min_segment_length":  1
  },
  "report": {
    "html": "phpdup-report",
    "json": "phpdup.json"
  }
}
```

The full machine-readable spec is at
[`docs/config-schema.json`](config-schema.json) — drop-in for any
JSON-Schema 2020-12 validator. `bin/phpdup analyze --config phpdup.json
--validate-config` runs an equivalent validator at load time and exits
with the offending field path on the first violation.

### Field reference

| Field                                  | Type             | Default          | Constraints                                                              |
|----------------------------------------|------------------|------------------|--------------------------------------------------------------------------|
| `paths`                                | list of strings  | (CLI args)       | Non-empty when present; each entry non-empty.                            |
| `exclude`                              | list of strings  | sensible default | Segment-safe glob patterns appended to the defaults; `**`/ anchors on whole path segments (see "exclude stacks" above); `"exclude_reset": true` opts out. |
| `min_block_size`                       | integer          | `8`              | `>= 1` and `<= max_block_size`.                                          |
| `max_block_size`                       | integer          | `800`            | `>= 1` and `>= min_block_size`.                                          |
| `normalization_mode`                   | string           | `"aggressive"`   | One of `strict`, `default`, `aggressive`.                                |
| `similarity_threshold`                 | number           | `0.80`           | `0.0 ≤ x ≤ 1.0`.                                                         |
| `tree_threshold`                       | number           | `0.85`           | `0.0 ≤ x ≤ 1.0`.                                                         |
| `min_cluster_impact`                   | integer          | `20`             | `>= 0`.                                                                  |
| `max_df`                               | number           | `0.01`           | `0.0 ≤ x ≤ 1.0`.                                                         |
| `candidate_budget`                     | integer          | `60000000`       | `>= 0`; `0` = unlimited pair generation (guard opted out).               |
| `ngram_size`                           | integer          | `5`              | `2 ≤ n ≤ 10`.                                                            |
| `fingerprint_id_mode`                  | string           | `"numbered"`     | One of `numbered`, `un-numbered` (item 1.1, plan §5a). Root config only — per-directory `.phpdup.json` files carrying this key fail the load; no CLI flag in v1. `un-numbered` strips the `__V`/`__CALL`/`__PROP`/`__CLASS` occurrence counters from the n-gram bag AND the tree-edit labels together; Jaccard/containment/TED are monotone under the coarsening (no today-accepted edge is lost). |
| `max_ted_size`                         | integer          | `800`            | `>= 1` (item 1.2). Node-count cap for APTED judgment: a pair whose LARGER member exceeds it skips the TED call — accepted on Jaccard alone with edge tier `jaccard-unverified` (weight = Jaccard score), else falls through to containment/IR/ML unchanged. Default equals the `max_block_size` extraction ceiling, so the gate is inert out of the box and every golden stays byte-identical. Root config only — per-directory `.phpdup.json` files carrying this key fail the load (a pair spans two directories); no CLI flag in v1. |
| `max_hole_coverage`                    | number           | `1.0`            | `0..1` (item 5.4). Hole-coverage ceiling for extraction honesty: a cluster whose generalized template is MORE than this fraction hole is marked `re_expressed` in reports and gets its longest hole-free statement run surfaced as `hole_fragment`. Default `1.0` = rule never fires (byte-identical reports). CLI `--max-hole-coverage`. Root config only — per-directory `.phpdup.json` files carrying this key fail the load (a cluster spans directories). |
| `cache_dir`                            | string           | `".phpdup-cache"`| Any path; created on first use.                                          |
| `parallelism`                          | string           | `"auto"`         | One of `auto`, `off`, `manual`.                                          |
| `workers`                              | integer          | `0` (auto)       | `>= 0`.                                                                  |
| `incremental`                          | boolean          | `true`           | Per-file index-snapshot reuse.                                           |
| `lazy_ast`                             | boolean          | `true`           | Drop original ASTs after fingerprinting; reload via `BlockAstLoader`.    |
| `kinds`                                | list of strings  | all              | Each entry one of `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`, `script`. `script` (item 1.3, top-level folds) must be named explicitly — the implicit "all" is the twelve traversable kinds without it. |
| `sort`                                 | string           | `"impact:desc"`  | `KEY[:asc\|desc]`. Keys: `impact`, `members`, `block-size`, `lines`, `similarity`, `confidence`, `name`, `file`, `id`. Aliases: `size`/`count` → `members`. |
| `optional_blocks.enabled`              | boolean          | `true`           | Master switch for type-3 detection.                                      |
| `optional_blocks.containment`          | number           | `0.85`           | `0.0 ≤ x ≤ 1.0`. Containment-fallback threshold.                         |
| `optional_blocks.min_overlap`          | number           | `0.6`            | `0.0 ≤ x ≤ 1.0`. Size-ratio guard against trivially-small overlaps.      |
| `optional_blocks.max_per_cluster`      | integer          | `3`              | `>= 0`. Cap on optional segments before falling back to a whole-array hole. |
| `optional_blocks.min_segment_length`   | integer          | `1`              | `>= 1`. Minimum stmt-array gap length to promote.                        |
| `report.html`                          | string           | —                | Equivalent to `--html DIR`.                                              |
| `report.json`                          | string           | —                | Equivalent to `--json FILE`.                                             |

Unknown top-level keys and unknown nested keys under `optional_blocks`
or `report` are rejected with a clear error.
