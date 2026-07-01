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
| `--min-impact N`                           | `20`           | Minimum cluster impact (≈ duplicated-line count) to include in output. Quiets reports without changing clustering. |
| `--fail-on-impact N`                       | `0` (off)      | CI gate: exit code 3 when total cluster impact exceeds N. 0 = disabled. |
| `--max-clusters N`                         | `0` (off)      | CI gate: exit code 3 when cluster count exceeds N. 0 = disabled. |
| `--exact-only`                             | off            | Skip the near-duplicate phase. Emits only canonical-hash-equal clusters (~6× faster on large corpora). |
| `--kinds K1,K2,...`                        | all            | Comma-separated block kinds to extract. Allowed: `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`. |
| `--max-memory MB`                          | `0` (off)      | Soft RSS ceiling. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests `--exact-only`. |
| `--optional-blocks on\|off`                | `on`           | Type-3 / "optional segment" detection master switch. See [Type-3 detection](../README.md#type-3--optional-segment-detection). |
| `--optional-blocks-containment N`          | `0.85`         | Containment-fallback threshold for the type-3 path (`0..1`).                                      |
| `--db-aware`                               | off            | ORM-/DB-aware semantic deduplication: rewrite recognised database calls (Eloquent, Doctrine, query builders, PDO, mysqli, pg_*, raw SQL strings) to canonical `__DB_<OP>__("table")` tokens during normalisation so equivalent ORM/raw-SQL variants of the same operation cluster together. Off by default — opt-in for ORM-heavy codebases. See [`docs/plans/orm-db-semantic-dedup.md`](plans/orm-db-semantic-dedup.md). |
| `--trinity-collapse`                       | off            | Detect the canonical CRUD trinity (read → mutate → save) and rewrite the three-statement sequence as a single `__DB_UPSERT__("entity")` synthetic call so ORM upserts cluster with raw `UPDATE` queries. Composes with `--db-aware`. Off by default. See option 2 of [`docs/plans/orm-db-semantic-dedup.md`](plans/orm-db-semantic-dedup.md). |
| `--scorer MODE`                            | `default`      | Scoring tier set: `default` (stock AST tiers) or `ir` (option-5 IR-tier fallback). When `ir`, after AST Jaccard / TED / containment all reject a pair, both blocks are lifted to canonical IR (`Phpdup\Ir\IrLifter`) and their token bags scored by multiset Jaccard; pairs at or above `--ir-threshold` form edges weighted by the IR similarity. Lift failure on either side silently skips the IR tier for that pair. |
| `--ir-threshold N`                         | `0.85`         | Multiset-Jaccard threshold for the option-5 IR tier (`0..1`). Only consulted when `--scorer=ir`. |
| `--ml-pair-url URL`                        | —              | External pair-similarity ML sidecar URL (option 6 of the ORM dedup plan). Empty (the default) disables the tier. When set, the very last clustering tier — runs after structural-hash, AST Jaccard + TED, containment, and IR all reject. POSTs a `PairFeatures` vector to `<URL>/score-pair`; pairs at or above `--ml-pair-threshold` form edges. http(s) only; SSRF-hardened (`0.0.0.0` rejected). Returns null on transport failure so unavailability never breaks the run. |
| `--ml-pair-threshold N`                    | `0.80`         | Similarity threshold (`0..1`) for the option-6 ML pair tier. |
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
| `--sort KEY[:asc\|desc]`                   | `impact:desc` | Cluster sort. Keys: `impact`, `members`, `block-size`, `lines`, `similarity`, `confidence`, `name`, `file`, `id`. Aliases: `size`/`count` → `members`. Direction may also be given as a leading `-` (desc) or `+` (asc), e.g. `-members`, `+lines`. |
| `--stats`                                  | off     | Print pipeline stage timings, block-kind histogram, and worker info after the report.  |

### Runtime

| Option                                     | Default     | Description                                                                                            |
|--------------------------------------------|-------------|--------------------------------------------------------------------------------------------------------|
| `-c, --config FILE`                        | —           | Load settings from a `phpdup.json` config file. CLI flags override file values.                        |
| `-j, --workers N`                          | `0` (auto)  | Worker count for parallel preprocess + pair scoring. `0` = auto-detect from `nproc` / `/proc/cpuinfo`. `1` = serial. |
| `--no-cache`                               | off         | Don't read or write the AST cache for this run.                                                        |
| `--no-incremental`                         | off         | Disable per-file index-snapshot reuse. Forces re-fingerprinting every file.                            |
| `--no-lazy-ast`                            | off         | Keep all original ASTs in memory throughout the run. Higher RSS, slightly faster anti-unification.     |
| `--low-memory`                             | off         | Use CompactNgramBag (32-bit fingerprint) + CanonicalNodePool interning for lower RSS on large corpora. |
| `--stage NAME`                             | —           | Halt the pipeline after STAGE: `scanning` \| `preprocessing` \| `clustering` \| `refactoring` \| `reporting`. |
| `--debug-log FILE`                        | —           | Append every debug (vvv) pipeline message to FILE. Messages are also emitted to stderr when `-vvv` is set; this flag ensures they are preserved to disk for post-run inspection. |

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
| `2`  | Invalid input: missing required argument, unknown shell for `completion`, schema-validation failure for `--validate-config`, invalid `--theme`, etc. |
| `3`  | CI gate triggered: `--fail-on-impact N` exceeded (total cluster impact > N) or `--max-clusters N` exceeded (cluster count > N). |
| `4`  | CI gate triggered: `--baseline FILE` was provided, FILE exists, and new duplicate clusters were found that were not present in the baseline. |

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

`src/Cli/OverrideResolver` applies this rule for the 19 flat keys that
share the same precedence: `min_block_size`, `max_block_size`,
`normalization_mode`, `similarity_threshold`, `tree_threshold`,
`min_cluster_impact`, `max_df`, `ngram_size`, `cache_dir`, `parallelism`,
`workers`, `incremental`, `lazy_ast`, `sort`, `ted_weights`, `scorer`,
`ir_threshold`, `ml_pair_threshold`, `debug_log`, and `low_memory`.

A few keys use different fallback paths:

| Key | Precedence chain |
|-----|-----------------|
| `allowed_kinds` | CLI → config `kinds` → `BlockExtractor::ALL_KINDS` |
| `optional_blocks.*` | CLI → config `optional_blocks` → `Config` defaults |
| `db_aware`, `trinity_collapse` | CLI → config → `Config` defaults |
| `exclude` | config `exclude` → profile exclude globs → `Config` defaults |
| `paths` | config `paths` → `Config` defaults (CLI argument is separate) |

## Configuration file

`phpdup.json` accepts the same settings as the CLI flags, plus a few
that are file-only (`paths`, `exclude`, the nested `optional_blocks`
sub-object, and the `report` sub-object). CLI flags override file
values.

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
| `exclude`                              | list of strings  | sensible default | Glob patterns; `**` matches recursively.                                 |
| `min_block_size`                       | integer          | `8`              | `>= 1` and `<= max_block_size`.                                          |
| `max_block_size`                       | integer          | `800`            | `>= 1` and `>= min_block_size`.                                          |
| `normalization_mode`                   | string           | `"aggressive"`   | One of `strict`, `default`, `aggressive`.                                |
| `similarity_threshold`                 | number           | `0.80`           | `0.0 ≤ x ≤ 1.0`.                                                         |
| `tree_threshold`                       | number           | `0.85`           | `0.0 ≤ x ≤ 1.0`.                                                         |
| `min_cluster_impact`                   | integer          | `20`             | `>= 0`.                                                                  |
| `max_df`                               | number           | `0.01`           | `0.0 ≤ x ≤ 1.0`.                                                         |
| `ngram_size`                           | integer          | `5`              | `2 ≤ n ≤ 10`.                                                            |
| `cache_dir`                            | string           | `".phpdup-cache"`| Any path; created on first use.                                          |
| `parallelism`                          | string           | `"auto"`         | One of `auto`, `off`, `manual`.                                          |
| `workers`                              | integer          | `0` (auto)       | `>= 0`.                                                                  |
| `incremental`                          | boolean          | `true`           | Per-file index-snapshot reuse.                                           |
| `lazy_ast`                             | boolean          | `true`           | Drop original ASTs after fingerprinting; reload via `BlockAstLoader`.    |
| `kinds`                                | list of strings  | all              | Each entry one of `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`. |
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
