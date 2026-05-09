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
| `--exact-only`                             | off            | Skip the near-duplicate phase. Emits only canonical-hash-equal clusters (~6× faster on large corpora). |
| `--kinds K1,K2,...`                        | all            | Comma-separated block kinds to extract. Allowed: `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`. |
| `--max-memory MB`                          | `0` (off)      | Soft RSS ceiling. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests `--exact-only`. |
| `--optional-blocks on\|off`                | `on`           | Type-3 / "optional segment" detection master switch. See [Type-3 detection](../README.md#type-3--optional-segment-detection). |
| `--optional-blocks-containment N`          | `0.85`         | Containment-fallback threshold for the type-3 path (`0..1`).                                      |

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
| `--limit N`                                | `50`    | Maximum number of clusters to print to the terminal. Doesn't affect file outputs.       |
| `--stats`                                  | off     | Print pipeline stage timings, block-kind histogram, and worker info after the report.  |

### Runtime

| Option                                     | Default     | Description                                                                                            |
|--------------------------------------------|-------------|--------------------------------------------------------------------------------------------------------|
| `-c, --config FILE`                        | —           | Load settings from a `phpdup.json` config file. CLI flags override file values.                        |
| `-j, --workers N`                          | `0` (auto)  | Worker count for parallel preprocess + pair scoring. `0` = auto-detect from `nproc` / `/proc/cpuinfo`. `1` = serial. |
| `--no-cache`                               | off         | Don't read or write the AST cache for this run.                                                        |
| `--no-incremental`                         | off         | Disable per-file index-snapshot reuse. Forces re-fingerprinting every file.                            |
| `--no-lazy-ast`                            | off         | Keep all original ASTs in memory throughout the run. Higher RSS, slightly faster anti-unification.     |
| `--stage NAME`                             | —           | Halt the pipeline after STAGE: `scanning` \| `preprocessing` \| `clustering` \| `refactoring` \| `reporting`. |

### TUI / watch

| Option                                     | Default | Description                                                                                          |
|--------------------------------------------|---------|------------------------------------------------------------------------------------------------------|
| `--tui`                                    | off     | Show the live SugarCraft dashboard while analysis runs. Requires a real TTY.                         |
| `--theme NAME`                             | `ansi`  | TUI theme: `ansi` \| `plain` \| `charm` \| `dracula` \| `nord` \| `catppuccin`.                      |
| `--plain`                                  | off     | Force plain CLI output (no TUI, no ANSI colours). Useful when CI shells report `isatty()=true`.      |
| `--watch`                                  | off     | Stay running and re-analyze on file changes via a poll-based `React\EventLoop` timer (1.5 s default poll). Combines with `--tui`. |

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
| `0`  | Successful run. **Note:** `analyze` does NOT exit non-zero when clusters are found. Gate CI on the JSON report — an empty `clusters` array means clean. |
| `1`  | Internal error (uncaught exception, reporter failure, etc.).                       |
| `2`  | Invalid input: missing required argument, unknown shell for `completion`, schema-validation failure for `--validate-config`, invalid `--theme`, etc. |

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
| `optional_blocks.enabled`              | boolean          | `true`           | Master switch for type-3 detection.                                      |
| `optional_blocks.containment`          | number           | `0.85`           | `0.0 ≤ x ≤ 1.0`. Containment-fallback threshold.                         |
| `optional_blocks.min_overlap`          | number           | `0.6`            | `0.0 ≤ x ≤ 1.0`. Size-ratio guard against trivially-small overlaps.      |
| `optional_blocks.max_per_cluster`      | integer          | `3`              | `>= 0`. Cap on optional segments before falling back to a whole-array hole. |
| `optional_blocks.min_segment_length`   | integer          | `1`              | `>= 1`. Minimum stmt-array gap length to promote.                        |
| `report.html`                          | string           | —                | Equivalent to `--html DIR`.                                              |
| `report.json`                          | string           | —                | Equivalent to `--json FILE`.                                             |

Unknown top-level keys and unknown nested keys under `optional_blocks`
or `report` are rejected with a clear error.
