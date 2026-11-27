# CLI reference

Standalone reference for every flag and sub-command in `bin/phpdup`.
The high-level walkthroughs live in the project [README](../README.md);
this file is the look-up table.

The flag rows in this file are kept in mechanical parity with the live argv roster by `tests/Unit/Cli/DocFlagParityTest.php`: a registered flag with no row here, or a token here that never registers, fails the test.

```
Usage:
  phpdup analyze <paths...> [options]
  phpdup <paths...> [options]
  phpdup init
  phpdup serve [--host H] [--port P]
  phpdup self:update | upgrade | self-update
  phpdup completion [<shell>]
  phpdup list
  phpdup help [<command>]
```

## Sub-commands

| Command       | Description                                                                  |
|---------------|------------------------------------------------------------------------------|
| `analyze`     | Scan paths for duplicate logic. The default command — `bin/phpdup src` runs it. A first non-flag token that is not a registered sub-command name or alias is routed to `analyze`, so `phpdup src/` and `phpdup analyze src/` are the same invocation (this is also the form the exit-2 missing-path hint teaches). A directory literally named like a sub-command is addressed with path separation: `./init`, `init/`. |
| `completion`  | Dump a bash / fish / zsh completion script with commented-out install instructions inline. |
| `help`        | Symfony Console's built-in help. `bin/phpdup help analyze` lists every flag. |
| `init`        | Scaffold a `phpdup.json` in the current directory. |
| `list`        | List every registered command. Also what a bare `phpdup` (no arguments) runs — it prints this listing and exits `0` instead of tripping analyze's missing-path door. |
| `self:update` | Replace this phpdup phar with the latest release from GitHub. Aliases: `upgrade`, `self-update`. |
| `serve`       | Run phpdup as a minimal HTTP analysis server. Binds `127.0.0.1:8080` by default (`--host`, `--port`). |

## `analyze` — arguments

| Argument | Description                                                                |
|----------|----------------------------------------------------------------------------|
| `paths`  | One or more directories (or files) to scan. Required unless `--validate-config` is set. Accepts repeats: `bin/phpdup analyze src lib`. Spellings — relative, `./x/`, `..`, `.`, absolute — are anchored on the invocation CWD and absolutized at admission, so a relative root resolves against where you run phpdup, never against the phar archive inside a shipped `.phar`. Per-directory `.phpdup.json` discovery matches realpath keys, so the chosen spelling cannot shift which override wins. |

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
| `--candidate-budget N`                     | `60000000`     | Safety cap on candidate pairs emitted during clustering. A pathological clone family halts loudly instead of exhausting memory. `0` = unlimited (opts out of the guard). The guard can only fire where candidate pairs are actually seeded: on a tiny all-identical corpus the default `--max-df 0.01` rejects the shared n-gram postings as too common, so nothing is seeded and the halt never arms — raise `--max-df` (e.g. `--max-df 1.0`) to exercise it. |
| `--min-impact N`                           | `20`           | Minimum cluster impact (≈ duplicated-line count) to include in output. Quiets reports without changing clustering. |
| `--min-safety N`                           | `0` (off)      | Drop clusters whose refactor-safety score (`0..1`) is below N. `0` reports all. A non-numeric or out-of-domain value (e.g. `2.0`, which would silently drop every cluster) is fatal: exit 2 before the pipeline runs. |
| `--fail-on-impact N`                       | `0` (off)      | CI gate: exit code 3 when any single cluster's impact is ≥ N — the per-cluster maximum, compared `>=` (canonical formula unified at once-S15: the shipped GitHub action's `fail-on-impact` input enforces the identical rule on the same JSON report). 0 = disabled. |
| `--max-clusters N`                         | `0` (off)      | CI gate: exit code 3 when cluster count exceeds N. 0 = disabled. |
| `--exact-only`                             | off            | Skip the near-duplicate phase. Emits only canonical-hash-equal clusters (~6× faster on large corpora). |
| `--kinds K1,K2,...`                        | all            | Comma-separated block kinds to extract. Allowed: `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`, plus `script` (top-level statement folds — OPT-IN ONLY, never part of the implicit "all"; script clusters cannot be `--apply`-ed yet). |
| `--auto-tune`                            | off            | Probe the corpus before analysis and pick `min-block-size`/`max-df`/`min-impact` based on its size; `--exact-only` is forced on for very large trees. The probe counts the tree the run will actually scan (profile and `--config` excludes applied, `exclude_reset` honored). Suggestions fill gaps only: a knob explicitly set on the command line, by `--profile`, or in the `--config` file is never overwritten, and the printed rationale claims only what was applied. |
| `--max-memory MB`                          | `0` (off)      | Soft RSS ceiling. When peak RSS exceeds this mid-pipeline, phpdup logs a warning and suggests `--exact-only`. Non-integer values are fatal (exit 2) — garbage no longer silences the ceiling. |
| `--optional-blocks on\|off`                | `on`           | Type-3 / "optional segment" detection master switch. See [Type-3 detection](../README.md#type-3--optional-segment-detection). |
| `--optional-blocks-containment N`          | `0.85`         | Containment-fallback threshold for the type-3 path (`0..1`). A non-numeric or out-of-domain value is fatal (exit 2) — the old blind cast turned `hot` into a silent `0.0`. |
| `--db-aware`                               | off            | ORM-/DB-aware semantic deduplication: rewrite recognised database calls (Eloquent, Doctrine, query builders, PDO, mysqli, pg_*, raw SQL strings) to canonical `__DB_<OP>__("table")` tokens during normalisation so equivalent ORM/raw-SQL variants of the same operation cluster together. Off by default — opt-in for ORM-heavy codebases. |
| `--trinity-collapse`                       | off            | Detect the canonical CRUD trinity (read → mutate → save) and rewrite the three-statement sequence as a single `__DB_UPSERT__("entity")` synthetic call so ORM upserts cluster with raw `UPDATE` queries. Composes with `--db-aware`. Off by default. |
| `--scorer MODE`                            | —              | Scoring tier set: `default` (stock AST tiers) or `ir` (IR-tier fallback). When the flag is absent the config-file `scorer` (or the built-in `default`) applies. When `ir`, after AST Jaccard / TED / containment all reject a pair, both blocks are lifted to canonical IR (`Phpdup\Ir\IrLifter`) and their token bags scored by multiset Jaccard; pairs at or above `--ir-threshold` form edges weighted by the IR similarity. Lift failure on either side silently skips the IR tier for that pair. |
| `--ir-threshold N`                         | `0.85`         | Multiset-Jaccard threshold for the IR tier (`0..1`). Only consulted when `--scorer=ir`. |
| `--ted-weights MODE`                     | `default`      | Tree-edit-distance cost model for the APTED tier: `default` \| `semantic`. `semantic` weights method calls and control flow heavier than literals. Root config key `ted_weights`. |
| `--ml-pair-url URL`                        | —              | External pair-similarity ML sidecar URL. Empty (the default) disables the tier. When set, the very last clustering tier — runs after structural-hash, AST Jaccard + TED, containment, and IR all reject. POSTs a `PairFeatures` vector to `<URL>/score-pair`; pairs at or above `--ml-pair-threshold` form edges. http(s) only; SSRF-hardened **at admission by shape, never by DNS**: no resolver call while validating config — names are resolved once, fail-closed, at dial time. Canonical IP literals are bit-checked against the refused space (0.0.0.0/8, loopback, RFC 1918, CGNAT, link-local, 192.0.0.0/24, 198.18.0.0/15, multicast/reserved, IPv6 `::1`/ULA/fe80::/10/ff00::/8 and the IPv4 embedded in `::ffff:0:0/96`, `::/96` and NAT64 `64:ff9b::/96`), numeric shorthands (`2130706433`, `0x7f000001`, `127.1`) and `file://`/`gopher://` refuse, `localhost` refuses by name. Returns null on transport failure so unavailability never breaks the run. |
| `--ml-pair-allow-private`                  | off            | Admit a `--ml-pair-url` sidecar living in the **private** address families — loopback, RFC 1918, CGNAT 100.64.0.0/10, link-local, ULA, and their IPv6/embedded (incl. NAT64) spellings — the documented local-sidecar topology (once-S54). Off by default; root config key `ml_pair_allow_private` (subtree `.phpdup.json` files are refused this key) takes effect today; the argv spelling registers with the next `Command.php` option-roster leg (once-S20's live lane). Reserved non-private spaces (0.0.0.0/8, 192.0.0.0/24, 198.18.0.0/15, multicast) stay refused even with this on. |
| `--ml-pair-threshold N`                    | `0.80`         | Similarity threshold (`0..1`) for the ML pair tier. |
| `--score-mode MODE`                        | `global`       | Scoring stance for cross-repo clones: `global` \| `per-repo` \| `typed`. `global` runs the legacy confidence/safety math byte-identically (every golden, baseline and gate history is stable under it). `per-repo` evaluates the ">3 namespaces" penalty on the namespace spread within the densest member repo, so a subsystem mirrored across packages is no longer double-penalized for its mirroring. `typed` (audit posture) replaces that penalty for cross-repo clusters with an inverse bonus (+0.05 confidence / +0.10 safety): one shared helper erases N repo-local copies. Single-repo clusters score identically under every mode. Repo labels come from the scan roots — analyze multiple repositories by passing one path per repo. Root config only — per-directory `.phpdup.json` files carrying this key fail the load. |
| `--max-hole-coverage N`                    | `1.0`          | Extraction-quality stance: when a cluster's generalized template is MORE than this fraction (`0..1`) hole, the report marks it `re_expressed` and surfaces its longest hole-free statement run (`hole_fragment`) instead of letting a hole-riddled signature pass as a clean abstraction. Default `1.0` disables the rule — coverage can never exceed it, so every historical report is byte-identical. Evidence is annotated, never dropped. Root config only, like `max_ted_size`. |
| `--profile NAME`                           | —              | Apply a project profile (`laravel`, `symfony`, `drupal`, `wordpress`, `myadmin`, `generic`, `db-aware-aura`, `db-aware-atlas`, `db-aware-cake`, `db-aware-codeigniter`, `db-aware-couchbase`, `db-aware-couchdb`, `db-aware-cycle`, `db-aware-dibi`, `db-aware-doctrine`, `db-aware-easydb`, `db-aware-elasticsearch`, `db-aware-idiorm`, `db-aware-influxdb`, `db-aware-illuminate`, `db-aware-laminas`, `db-aware-laravel`, `db-aware-medoo`, `db-aware-mongodb`, `db-aware-myadmin`, `db-aware-myadmin-orm`, `db-aware-neo4j`, `db-aware-phalcon`, `db-aware-phpactiverecord`, `db-aware-pixie`, `db-aware-propel`, `db-aware-redis`, `db-aware-redbean`, `db-aware-thinkorm`, `db-aware-yii`, or `auto`). Project profiles seed framework-aware excludes + tuning; `db-aware-*` profiles ship symbol-equivalence packs that extend the stock DB registry with framework-specific call shapes. Explicit CLI flags + `--config` win over profile values. |

### Output

| Option                                     | Default | Description                                                                            |
|--------------------------------------------|---------|----------------------------------------------------------------------------------------|
| `--html DIR`                               | —       | Write the interactive HTML report into DIR.                                            |
| `--json FILE`                              | —       | Write the structured JSON dump to FILE.                                                |
| `--sarif FILE`                             | —       | Write a SARIF 2.1.0 report to FILE (GitHub Code Scanning, GitLab Code Quality).        |
| `--gitlab-sast FILE`                       | —       | Write a GitLab SAST v15.x report to FILE.                                              |
| `--diff DIR`                               | —       | Write one `.diff` per cluster (pairwise from member[0]) into DIR.                      |
| `--patch FILE`                             | —       | Write a single cumulative patch file containing every cluster diff.                    |
| `--apply`                                  | off     | Apply posture: extract each cluster's generalized function and rewrite member call sites — generated names follow the naming posture below. Posture only; what lands on disk is decided by `--dry-run` (below): by default a preview `refactored/apply.diff` is written and member files stay untouched; `--dry-run=false` rewrites the sources in place and lands `refactored/apply-summary.md` instead. Config-file equivalent: root `"apply": true`. Clusters containing `script` blocks cannot be applied (see `--kinds`) — refused with a typed error. The apply directory anchors to the directory the command is LAUNCHED from: a relative `refactored` is absolutized against the launch working directory before the pipeline is built; scan roots never re-anchor it. An apply directory that already exists as a SYMBOLIC LINK is refused before any work — in both postures, exit `3`, the refusal names the link and its target (once-S29). |
| `--checkstyle FILE`                        | —       | Write Checkstyle XML to FILE (Jenkins / Sonar / Bitbucket consumers).                  |
| `--csv FILE`                               | —       | Write flat CSV (one row per cluster member) to FILE.                                   |
| `--prometheus FILE`                        | —       | Write Prometheus text-format metrics to FILE (for pushgateway / CI dashboards).       |
| `--timeseries FILE`                        | —       | Append a JSONL summary record (commit-tagged) to FILE for tracking duplicate debt over time. |
| `--graphviz FILE`                          | —       | Write a Graphviz DOT graph (file→cluster bipartite) to FILE — render with `dot -Tpng FILE -o out.png`. |
| `--plantuml FILE`                          | —       | Write a PlantUML class diagram to FILE — render with `plantuml FILE`.                  |
| `--refactor-patch DIR`                     | —       | Emit one `.patch` file per cluster into DIR — heuristic, manual-review-required; generated names follow the naming posture below. |
| `--refactor-tests DIR`                     | —       | Emit a PHPUnit test skeleton per cluster into DIR (data-provider rows mirror the hole observations). |
| `--baseline FILE`                          | —       | CI baseline file. If FILE exists: compare and exit 4 if new clusters found. If FILE does not exist: write baseline and exit 0 (first-run auto-baseline). |
| `--baseline-out FILE`                      | —       | Write current clusters as a baseline snapshot to FILE (overwrites existing).           |
| `--limit N`                                | `50`    | Maximum number of cluster detail cards printed to the terminal. `N` is an integer ≥ 0; a negative or non-numeric value is fatal (exit 2). Doesn't affect file outputs — `--json`/`--html`/etc. always carry every cluster. `0` prints NO detail cards: unlike the `--candidate-budget` / `max_clusters` family (where `0` = unlimited), `--limit 0` is not a show-all sentinel — the totals banner and summary line still describe the full set while the card list stays empty. To see every cluster, pass a large `N`. |
| `--summary-only`                           | off     | Render only the top banner and the final summary line — the per-cluster detail cards are skipped (banner totals still describe the full set). |
| `--clusters`                               | off     | Render a one-line-per-cluster table instead of the full per-cluster breakdown.         |
| `--scope VALUE`                            | `all`   | Repo scope filter: `all` \| `single` \| `multi` \| `<repo-label>`. Every cluster member carries a `repo` label (the basename of the longest-matching scan root) and clusters gain a `scope` of `single` (one repo) or `multi` (members span ≥2 distinct repos). `single`/`multi` keep the matching clusters; any other value is treated as an exact repo label and keeps clusters with at least one member from that repo. Applied after ranking, so `--limit`, `--sort` and the CI gates all measure the filtered slice. A typo'd label matches nothing: the run still exits 0 but prints one stderr hint listing the repo labels present. Cross-repo analysis means one scan path per repository. Root config only — per-directory `.phpdup.json` files carrying this key fail the load. |
| `--sort KEY[:asc\|desc]`                   | `impact:desc` | Cluster sort. Keys: `impact`, `members`, `block-size`, `lines`, `similarity`, `confidence`, `name`, `file`, `id`. Aliases: `size`/`count` → `members`. Direction may also be given as a leading `-` (desc) or `+` (asc), e.g. `-members`, `+lines`. |
| `--pager MODE`                             | `never`   | Page the CLI report through `$PAGER` (default `less -R`). Modes: `auto` \| `never` \| `always`. `auto` pages when stdout is a TTY and the rendered output exceeds ~60 lines. An unknown mode is fatal (exit 2). |
| `--dry-run [=BOOL]`                        | `true`    | With an apply posture (`--apply` or config `"apply": true`): `true` (bare flag or default) previews — the refactor patch lands as `refactored/apply.diff` and member files are untouched; `--dry-run=false` runs the real apply — member files are rewritten in place and `refactored/apply-summary.md` lands instead of `apply.diff`. Accepted boolean spellings: `true`/`false`/`1`/`0`/`yes`/`no`/`on`/`off`; any other value is fatal (exit 2) at admission. Inert without an apply posture. The exit code of the run is unchanged by either posture. The posture is announced before the pipeline runs: a preview prints `phpdup: dry-run apply posture … no member file is rewritten`, real apply prints `phpdup: real apply armed …` instead. |
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

#### Scan signals: skips, exclude typos, parse failures

The verdict line above refuses to launder a degraded *corpus* (once-S24);
the scan stage now refuses to launder degraded *inputs* (once-S37):

- **Skipped paths.** Every path the scanner could not use — an unreadable
  file or directory, a symlink whose target escapes the tree — earns one
  `phpdup: warning:` line on STDERR and lands on the scan-notes channel
  (`PipelineState::$scanNotes`; plan §37 books the same channel for later
  report consumers). After the walk, one census line at DEFAULT verbosity
  names the totals — `phpdup N path(s) skipped (unreadable, missing, or
  symlink containment)` — so a partial scan is visible without `--verbose`.
  A clean run emits neither the notes nor the census line.
- **Nonexistent paths are refused up front.** A scan path that is neither a
  directory nor a file exits `2` before the pipeline starts with one
  `phpdup: path not found:` line (see *Exit codes*); the silent-zero shape
  is gone. A path that exists but cannot be read stays a *noted skip* — the
  run legitimately continues with the paths that do work.
- **Exclude typos.** Every exclude glob the user owns — config-file,
  framework-profile and CLI-override-dict globs, not the built-in defaults,
  which most trees legitimately never match — is watched across the walk: a
  pattern that matched no path anywhere in the scan is reported once,
  `exclude pattern X matched no path in this scan (typo?)`, and counted in
  the census line. Per-directory `.phpdup.json` exclude lists keep their own
  ownership story and are not typo-censused here.
- **Parse failures.** The summary counts them (`N parse errors`); under
  `--verbose` the run additionally lists each failing file with the parser's
  real message — up to 50 lines, then one `… and N more parse failure(s).`
  fold — instead of discarding the *why* at the worker boundary.

The JSON summary fields for these channels
(`summary.scan_notes[]`, `summary.parse_failures[]`,
`summary.clusters_replayed`) ride the schema-1.8 carrier threading and are
not in the payload yet; the CLI surface above is what ships. A warm run that
replayed whole clusters from the cluster cache now says so at default
verbosity (`phpdup: clusters replayed from the cluster cache — …`), because
such a report's member line ranges came from the saving run's bytes.

### Runtime

| Option                                     | Default     | Description                                                                                            |
|--------------------------------------------|-------------|--------------------------------------------------------------------------------------------------------|
| `-c, --config FILE`                        | —           | Load settings from a `phpdup.json` config file. CLI flags override file values.                        |
| `-j, --workers N`                          | `0` (auto)  | Worker count for parallel preprocess + pair scoring. `0` = auto-detect the CPU count (see *Worker auto-detection* below; `PHPDUP_WORKERS` seeds it). `1` = serial. A value above the pool ceiling (`4 × detected CPUs`) is admitted with a `phpdup: warning:` note on STDERR — the pool clamps every run to the ceiling instead of failing admission. |
| `--no-cache`                               | off         | Silence the per-file disk caches for this run, **reads and writes alike**: the AST parser blob cache under `cache_dir` is neither read nor written (every file parses fresh in the workers), and the per-file incremental index (`IndexStore`) is not reused, so the refactor stage has no cached ASTs to reload. **Not** governed by this flag: the cluster cache (`clusters.list` / `clusters.idx` under `cache_dir`) is still written — that is controlled by `--no-incremental` / the `incremental` key, not by `--no-cache`. |
| `--no-incremental`                         | off         | Disable per-file index-snapshot reuse. Forces re-fingerprinting every file.                            |
| `--no-lazy-ast`                            | off         | Keep all original ASTs in memory throughout the run. Higher RSS, slightly faster anti-unification.     |
| `--low-memory`                             | off         | Use CompactNgramBag (int-keyed n-gram fingerprints — the xxh64 digest held as a positive 63-bit key) + CanonicalNodePool interning for lower RSS on large corpora, at the cost of some speed. **May reduce detection sensitivity**: gram-key merging fakes overlap between distinct digests and inflates document frequencies past the `--max-df` cutoff, so candidate pairs drop out. Disclosed measurement (php-dup-finder-once-027): a 169-file corpus reported 159 clusters with the flag off vs 92 with it on under the earlier 32-bit `hi ^ lo` fold scheme; the current 63-bit masked key narrows the merge class to digests differing only in bit 63 — birthday-wise negligible for real corpora, but the mode still changes the comparison input and is not a pure speed switch. The node-pool interning leg is output-invisible (engine-S04 rider). The mode rides both on-disk cache keys, so a warmed low-memory cache never replays into a default run and vice versa (`tests/Unit/Cli/CacheKeyKnobReplayTest.php` is the fence). |
| `--stage NAME`                             | —           | Halt the pipeline after STAGE: `scanning` \| `preprocessing` \| `clustering` \| `refactoring` \| `reporting`. An unknown name is fatal: the run is refused with exit 2 before the pipeline starts. |
| `--debug-log FILE`                        | —           | Append every debug (vvv) pipeline message to FILE. Messages are also emitted to stderr when `-vvv` is set; this flag ensures they are preserved to disk for post-run inspection. |
| `--diff-base REF`                          | —           | Git ref for diff-scoped analysis. When set, scans only files changed in `git diff --name-only <REF>..HEAD` plus their "clone cohort" — all files sharing n-gram fingerprints with the changed files. Useful for per-PR analysis in CI to limit scope to only affected files and their clones. |
| `--trust-in-root-cache`                    | off         | Read the on-disk AST cache even when `cache_dir` resolves **inside** a scan root. Because the default `cache_dir` is the relative `.phpdup-cache`, a normal run stores blobs inside the tree it scans — so cloning an untrusted repository and running `phpdup analyze .` would otherwise serve the repo author's committed cache entries as freshly parsed code (a cache-poisoning / fabricated-duplicate vector, engine-006). Off by default: an untrusted run **refuses those reads**, reports real code (one cold parse pass) and prints a notice. Pass this flag only for trees you control and whose cache you deliberately committed for reuse. CLI-only (no config-file key); cache writes are unaffected, and the gate holds on the `--workers` fork path too. The default cache posture is otherwise unchanged — `cache_dir` still defaults to `.phpdup-cache`. |

#### Cache retention and pruning

phpdup ships no prune subcommand and no TTL knob — deliberately (once-S64,
php-dup-finder-once-073, owner ruling: documented removal over an
implemented flag). Every entry under `cache_dir` is content-addressed: a
snapshot replays only while its `file_hash`, parser stamp and config key
all still match, so a stale entry is a correctness non-event — it can
only waste disk, never serve wrong results. The pruning policy is
therefore **documented rm**: to reclaim space or clear suspect state,
delete the cache directory (`rm -rf .phpdup-cache`, or the configured
`cache_dir`) and rerun; the next analysis rebuilds every slot it needs.
The stores self-heal around a removed or poisoned tree — a vanished dir
is recreated private (0700, blobs 0600) by the next construction, a
group-writable dir is write-tightened on adoption, a symlinked one is
refused outright with the store degraded to plain misses — and
`--no-cache` silences the per-file stores' reads and writes for a single run
(the cluster cache is governed by `--no-incremental`, see its option row) —
without deleting anything.

### TUI / watch

| Option                                     | Default | Description                                                                                          |
|--------------------------------------------|---------|------------------------------------------------------------------------------------------------------|
| `--tui`                                    | off     | Show the live SugarCraft dashboard while analysis runs. Requires a real TTY.                         |
| `--theme NAME`                             | `ansi`  | TUI theme: `ansi` \| `plain` \| `charm` \| `dracula` \| `nord` \| `catppuccin`. An invalid name is fatal (exit 2) even without `--tui`. |
| `--plain`                                  | off     | Force plain CLI output (no TUI, no ANSI colours). Useful when CI shells report `isatty()=true`.      |
| `--watch`                                  | off     | Stay running and re-analyze on file changes via a poll-based `React\EventLoop` timer (1.5 s default poll). Combines with `--tui`. |

### CI / Baseline

The `--baseline` and `--baseline-out` flags implement an incremental CI gate
workflow: accept the current state of duplication as a baseline, then fail the
CI build only when **new** duplicate clusters appear in subsequent runs.

**How it works:**

- `member_hashes` are the primary identity key. A block's identity is
  `sha256(underRoots(file) . '|' . structuralHash)` — the block's file
  path reduced to its root-relative, separator-folded form, `|`, and
  the block's normalized structural hash. Line numbers are NOT in the
  recipe (v1's `sha256(file + start_line + end_line)` churned every
  entry whenever an unrelated edit shifted lines above a block), and
  neither are cluster IDs, which may change as clustering algorithms
  evolve. Each entry carries one hash per member block, duplicates
  included, sorted; the subset check below counts those raw members —
  a current cluster is covered only when its whole member multiset fits
  inside the list of ONE baseline cluster.
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
   # Replaces baseline.json atomically (temp + fsync + rename under
   # LOCK_EX: readers never see a half-written file), exits 0
   ```

**Exit codes:**

| Code | Condition                                                            |
|------|----------------------------------------------------------------------|
| `0`  | Normal run, OR baseline written (first run / --baseline-out), OR `--baseline FILE` exists and no new clusters detected |
| `2`  | `--baseline FILE` exists but is empty or corrupt (zero bytes, whitespace, a bare `[]`/`{}`, or invalid/truncated JSON): the run names the repair (delete the file, or re-accept with `--baseline-out`) instead of analyzing against a broken gate input |
| `4`  | `--baseline FILE` exists and new duplicate clusters were found       |

**Example CI pipeline:**

```bash
# In CI: fail if new duplication introduced since main branch baseline
git fetch origin main
# Stage main's baseline in a scratch file. Never `cp` onto or over the
# tracked baseline.json: a copy interrupted mid-write hands the next job
# a truncated gate input (empty/corrupt baselines exit 2 above), and a
# careless restore overwrites the accepted state with scratch bytes.
git show origin/main:baseline.json > .phpdup-baseline-ci.json 2>/dev/null \
    || rm -f .phpdup-baseline-ci.json

bin/phpdup analyze src --baseline .phpdup-baseline-ci.json
STATUS=$?
rm -f .phpdup-baseline-ci.json

exit $STATUS
```

If `main` carries no baseline yet, the scratch file is removed and
`--baseline` sees a missing file: first-run auto-baseline, exit 0 — the
gate starts once main has an accepted baseline. To re-accept the current
state deliberately, refresh the tracked file with
`bin/phpdup analyze src --baseline-out baseline.json` (atomic replace)
in a normal commit — never with `cp`.

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
| `--silent`            | Do not output any message (stricter than `--quiet`: even error output is suppressed here — what survives is only the exit code).          |
| `-v` / `-vv` / `-vvv` | Increase verbosity.                                                               |
| `-n, --no-interaction`| Don't prompt for input.                                                           |
| `--ansi` / `--no-ansi`| Force or disable ANSI output.                                                     |

## `serve` — options

The endpoint map, security model and operation notes live in
[docs/SERVER.md](SERVER.md).

| Option                | Default       | Description                                                                                     |
|-----------------------|---------------|-------------------------------------------------------------------------------------------------|
| `--host HOST`         | `127.0.0.1`   | Host to bind. Loopback by default; a non-loopback host is refused without `--bind-public`.      |
| `--port PORT`         | `8080`        | TCP port to bind.                                                                               |
| `--bind-public`       | off           | Allow binding to a non-loopback address. Refused unless `--token` is set too — both opt-ins are required to expose the server. |
| `--serve-root DIR`    | CWD           | Root directory to which scanning is confined (realpath-canonicalised). An empty value disables confinement entirely — any path this uid can read becomes scannable; see the SECURITY note in docs/SERVER.md. |
| `--token TOKEN`       | —             | Bearer token required for all requests. Mandatory with `--bind-public`; without it, no authentication is enforced. |
| `--serve-cache`       | off           | Let the synchronous `/analyze` pipeline reuse the AST/cluster caches. Off by default: the historic serve posture re-parses fresh on every request. |
| `--serve-workers N`   | `1`           | Worker count for the synchronous `/analyze` pipeline. Default `1` (serial) prevents a per-request fork storm on many-core boxes; raise to opt into parallel analysis (still capped by the WorkerPool ceiling). `0` restores automatic CPU-count workers. Must be a non-negative integer — anything else refuses the bind during configuration validation, before the socket opens. |

## `init` — options

| Option                | Default | Description                                                            |
|-----------------------|---------|------------------------------------------------------------------------|
| `-p, --profile NAME`  | —       | Manually specify a profile (laravel\|symfony\|drupal\|wordpress\|generic\|db-aware-*) instead of detecting one. |
| `-f, --force`         | off     | Overwrite `phpdup.json` if it already exists (no interactive prompt).   |

## `self:update` — options

| Option                     | Default | Description                                                              |
|----------------------------|---------|--------------------------------------------------------------------------|
| `--dry-run`                | off     | Fetch release info and print what would be downloaded, but do not replace anything. |
| `-o, --output FILE`        | —       | Write the downloaded phar to FILE instead of replacing the running one.   |
| `--dev-allow-unsigned`     | off     | DEV ONLY — install without integrity verification (loud stderr banner; never for production). |
| `--verify-artifact FILE`   | —       | Offline verify: path to the artifact (phar) to check.                     |
| `--verify-manifest FILE`   | —       | Offline verify: path to the release manifest JSON.                        |
| `--verify-signature FILE`  | —       | Offline verify: path to the manifest signature file.                      |

The three `--verify-*` options combine into one offline audit invocation: given
all three paths, the manifest signature and the artifact hash are checked
locally, exiting `0`/`1` with no network access and without running as a phar —
the user-facing audit tools of [docs/RELEASE.md](RELEASE.md).

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
| `2`  | Invalid input: missing required argument, unknown shell for `completion`, schema-validation failure for `--validate-config`, a non-numeric or out-of-domain value for a numeric option (e.g. `--min-block-size abc`, `--similarity 1.5`, `--limit abc`, `--min-safety 2.0`, `--max-memory banana`, `--optional-blocks-containment hot`), an invalid choice for `--stage`, `--pager` or `--theme`, a non-boolean value for `--dry-run`, a scan path that does not exist (`phpdup analyze /nope` is refused with one `phpdup: path not found:` line BEFORE the pipeline — never the old clean-looking exit 0 over `0 files`), etc. |
| `3`  | CI gate triggered: `--fail-on-impact N` tripped (maximum single-cluster impact ≥ N — same canonical formula as the GitHub action's `fail-on-impact` input) or `--max-clusters N` tripped (cluster count > N). The tripped gate is named in the output. Pre-pipeline SAFETY gates ride this code too: real apply (`--dry-run=false`) against a git work tree with uncommitted tracked changes (once-S04), and an apply directory that already exists as a symbolic link (once-S29) — both refuse before any analysis with zero files written. |
| `4`  | CI gate triggered: `--baseline FILE` was provided, FILE exists, and new duplicate clusters were found that were not present in the baseline. |

A bare `phpdup` with no arguments is the one no-path form that exits `0`:
it prints the command listing (identical to `phpdup list`). Every other
path-less `analyze` form — a flags-only run such as `phpdup --exact-only`
— still hits the `2` door above, whose suggested `phpdup <path>/ ...`
shape runs analyze directly.

Numeric option values are trimmed before coercion: surrounding whitespace
is accepted (`--workers ' 4 '` is the same as `--workers 4`). A value that
is still non-numeric — or outside the option's domain — after trimming
exits `2` as above.

Option admission is EAGER and uniform: a rejected value — non-numeric,
out-of-domain, or an unknown choice for the enum options `--stage`,
`--pager` and `--theme` — prints one `phpdup:` error line and exits `2`
BEFORE the pipeline runs. An option error is always the run's last word;
it never scrolls past under a full report, and a rejected invocation never
writes a report file.

## Worker auto-detection

When `--workers` is `0` (auto), the CPU count is probed portably — no shell
is ever invoked. The first positive answer wins:

1. **cgroup CPU quota** — v2 `cpu.max`, then the v1 cpu-controller
   `cfs_quota_us`/`cfs_period_us` pair. A quota-limited container counts its
   own share (rounding up: 1.8 CPUs → 2), never the host topology.
2. **`/proc/cpuinfo`** — one entry per logical CPU (Linux hosts).
3. **`NUMBER_OF_PROCESSORS`** — the environment variable Windows exports to
   every process (the portable Windows equivalent of `nproc`).
4. **Fallback `4`** — a declared default for platforms nothing above can
   see (e.g. a macOS or FreeBSD host outside a quota).

The detected count also sets the pool ceiling: concurrent children never
exceed `4 × detected CPUs`, whatever was requested (see `--workers` above).

## Environment variables

| Variable          | Effect                                                                                  |
|-------------------|-----------------------------------------------------------------------------------------|
| `PHPDUP_WORKERS`  | **Seed, not override** (lower precedence than `-j` / `--workers`). Seeds the CPU auto-detection used when the worker count is automatic, and therefore moves the pool ceiling too; an explicit `--workers N` (N ≥ 1) always wins. Non-numeric or ≤ 0 values are ignored. |
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
`max_ted_size` joins this list but has no CLI flag (config
file only — it sits alongside `fingerprint_id_mode` as a root-only key).
`max_hole_coverage` joins this list with a CLI flag
(`--max-hole-coverage`) and the same root-only posture: a cluster spans
members from many directories, so its re-expression threshold is a stance
about the one final report, never a per-subtree tuning knob.

A few keys use different fallback paths:

| Key | Precedence chain |
|-----|-----------------|
| `allowed_kinds` | CLI → config `kinds` → the twelve traversable kinds (everything in `BlockExtractor::ALL_KINDS` except `script` — that fold kind is opt-in only and never implicit) |
| `optional_blocks.*` | CLI → config `optional_blocks` → `Config` defaults |
| `db_aware`, `trinity_collapse` | CLI → config → `Config` defaults |
| `exclude` | APPEND: `Config` defaults + profile globs + config-file list + programmatic CLI-dict list (deduped, in that order) — never a replacement; `"exclude_reset": true` drops the inherited layers. A `"exclude": []` in any layer is a pure no-op: an empty list adds nothing, and its mere presence wipes nothing. Per-directory `.phpdup.json` lists accumulate down the tree the same way — but inside the subtree of the file that carries them their patterns are anchored **relative to that file's own directory**, and they prune the scan walk for real (see "exclude stacks" below). |
| `paths` | config `paths` → `Config` defaults (CLI argument is separate) |

### `exclude` stacks — the glob dialect

Explicit exclude lists APPEND to the stock defaults; a single list no
longer wipes them. The one documented escape hatch is `"exclude_reset":
true` (root config file or any per-directory file): everything inherited
below that layer (defaults, profile, ancestors) is discarded and only the
layer's own list survives.

**Per-directory `.phpdup.json` lists are applied at scan time (once-S28,
php-dup-finder-once-032).** The keys were admitted to the config surface early but had
no scan-side consumer: a nested file's `exclude`/`exclude_reset` changed
the per-file knob view (`Config::effectiveFor()`) while the walk itself
only ever honored the root-resolved list. The walk now honors them too.
Each discovered file contributes one layer governing exactly its own
subtree:

- Its patterns are matched against paths relative to **that file's
  directory** — a `.phpdup.json` in `legacy/` excluding `gen/**` prunes
  `legacy/gen/` and everything under it, and says nothing about a
  `gen/` directory elsewhere in the tree (the root-frame reading in the
  table above applies to root-list layers; a per-directory layer carries
  the same dialect in its own frame).
- Layers chain shallow→deep over the root-resolved list: every list on
  the chain from the scan root down to the entry's directory stays
  active, and each layer APPENDS to the accumulated one — the same
  merge the per-file knob view computes.
- A layer carrying `"exclude_reset": true` re-roots its subtree: the
  accumulated chain below it (stock defaults, profile, root file, CLI
  dict, every ancestor directory) is discarded and only its own list
  survives. The discard is one-way; deeper layers then append again.
- Exclusion prunes at the **directory** level, so a whole excluded
  subtree is never enumerated and its files never enter the scan
  census — the pruning is silent, exactly as for root-list excludes
  (`skipped …` notes stay reserved for environment problems). The one
  exception to the prune is honest re-rooting: when a resetting layer
  sits inside an excluded directory's subtree, the walk descends so the
  re-rooted files can reappear (and everything still unmatched by the
  surviving chain stays skipped).
- Config **discovery** is governed by the root-resolved exclude list
  only. A `.phpdup.json` inside a root-excluded tree (a stock
  `vendor/`, say) is never read — so a vendored package can neither
  poison the run nor write its own escape hatch out of a tree the user
  excluded. A file under a subtree excluded by an ANCESTOR per-directory
  list is still read (and still fails loudly when misconfigured) — it
  just has no effect unless its own list resets, which re-roots its
  subtree as promised above.
- `--exclude` and the root config file's keys keep their CLI-dict frame:
  root-relative. Only per-directory files introduce a nested anchor.

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

**Defaults erratum.** The exclude-compiler rewrite promised the nine stock
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
| `fingerprint_id_mode`                  | string           | `"numbered"`     | One of `numbered`, `un-numbered`. Root config only — per-directory `.phpdup.json` files carrying this key fail the load; no CLI flag in v1. `un-numbered` strips the `__V`/`__CALL`/`__PROP`/`__CLASS` occurrence counters from the n-gram bag AND the tree-edit labels together; Jaccard/containment/TED are monotone under the coarsening (no today-accepted edge is lost). |
| `max_ted_size`                         | integer          | `800`            | `>= 1`. Node-count cap for APTED judgment: a pair whose LARGER member exceeds it skips the TED call — accepted on Jaccard alone with edge tier `jaccard-unverified` (weight = Jaccard score), else falls through to containment/IR/ML unchanged. Default equals the `max_block_size` extraction ceiling, so the gate is inert out of the box and every golden stays byte-identical. Root config only — per-directory `.phpdup.json` files carrying this key fail the load (a pair spans two directories); no CLI flag in v1. |
| `max_hole_coverage`                    | number           | `1.0`            | `0..1`. Hole-coverage ceiling for extraction honesty: a cluster whose generalized template is MORE than this fraction hole is marked `re_expressed` in reports and gets its longest hole-free statement run surfaced as `hole_fragment`. Default `1.0` = rule never fires (byte-identical reports). CLI `--max-hole-coverage`. Root config only — per-directory `.phpdup.json` files carrying this key fail the load (a cluster spans directories). |
| `cache_dir`                            | string           | `".phpdup-cache"`| Any path; created on first use.                                          |
| `parallelism`                          | string           | `"auto"`         | One of `auto`, `off`, `manual`.                                          |
| `workers`                              | integer          | `0` (auto)       | `>= 0`.                                                                  |
| `incremental`                          | boolean          | `true`           | Per-file index-snapshot reuse.                                           |
| `lazy_ast`                             | boolean          | `true`           | Drop original ASTs after fingerprinting; reload via `BlockAstLoader`.    |
| `kinds`                                | list of strings  | all              | Each entry one of `function`, `method`, `closure`, `arrow`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`, `script`. `script` (top-level folds) must be named explicitly — the implicit "all" is the twelve traversable kinds without it. |
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

### What a per-directory `.phpdup.json` may carry — the admission truth table

Discovery walks every scan root (pruned by the exclude layers above) and
reads each `.phpdup.json` it meets. Admission is **total** (once-S67,
php-dup-finder-once-077): of the 44 top-level keys above, **11 are
honored per subtree and 33 are refused at load** — there is no third
state of "admitted but inert". The third state used to be the file's own
lie: the seven clustering/gate stances (`similarity_threshold`,
`tree_threshold`, `min_cluster_impact`, `max_df`, `candidate_budget`,
`sort`, `fail_on_impact`, `max_clusters`) and the five
`optional_blocks.*` sub-knobs were read from subtree files, stored onto
the derived per-file config, and never consulted — their readers
(ClusterStage, ReportStage, the CI gates, AntiUnifier) receive the ROOT
config, so the subtree tuning was decoration. The root-only plumbing and
output keys vanished from subtree files in total silence. Both halves now
have loud, honest names.

**Honored per subtree** (a deeper file wins over a shallower one;
`exclude` APPENDS down the chain and `exclude_reset: true` truncates the
inherited layers for its subtree; every value rides the per-file worker
tooling key and the subtree's `IndexStore` cache key, so editing a
subtree file invalidates exactly that subtree's warm cache):

| Key                                    | Effect inside the subtree's files                                                                                                          |
|----------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| `exclude`, `exclude_reset`             | Scan-time pruning; patterns anchored **relative to the file's own directory** (once-S28).                                                   |
| `min_block_size`, `max_block_size`     | Extraction size gates. Top-level `function`/`method`/`closure`/`arrow` blocks are min-exempt (engine-057), so the observable gate is on statement-kind blocks (`if`, `for`, `try`, …). |
| `normalization_mode`                   | `strict` / `default` / `aggressive` fold depth for that subtree's ASTs.                                                                     |
| `ngram_size`                           | Fingerprint bag width (2–10).                                                                                                               |
| `kinds`                                | Which block kinds are extracted.                                                                                                            |
| `db_aware`                             | Run the `DbOpCanonicalizer` semantic pre-pass for that subtree.                                                                              |
| `trinity_collapse`                     | Fold canonical CRUD trinity sequences into `__DB_UPSERT__` synthetics for that subtree.                                                      |
| `db_symbols`                           | Custom symbol buckets merge ON TOP of the root map for that subtree.                                                                         |
| `low_memory`                           | Compact int-keyed bags + node-pool interning for that subtree.                                                                               |

**Refused at load** — a subtree file carrying any of these fails the run
with `"<key> is not a per-directory key (root phpdup.json only): <file>"`
(exit 2, naming key and file). The 33-key roster in four families:

| Family | Keys | Why root-only |
|--------|------|---------------|
| Fingerprint / pair / report stance | `fingerprint_id_mode`, `scope`, `score_mode`, `max_ted_size`, `max_hole_coverage`, `ted_weights`, `scorer`, `ir_threshold` | Pairs and clusters span directories; the stance is chosen once per run (a per-directory value would be ambiguous even if it were read). |
| Write / ref / code / sidecar | `report`, `apply`, `normalization`, `baseline`, `baseline_out`, `diff_base`, `debug_log`, `ml_pair_allow_private`, `ml_pair_url`, `ml_pair_threshold` | They name files the run reads or WRITES, argv for git, plugin class loaders, or a network sidecar — the shell-S22 family posture: no scanned subtree may choose them (a planted `ml_pair_url` must fail loudly, once-S54; the old silent inertness was the once-076 exploit's vehicle). |
| Run plumbing | `paths`, `cache_dir`, `parallelism`, `workers`, `incremental`, `lazy_ast` | One cache root and one worker pool per run; `paths` is fixed before discovery walks the trees; `lazy_ast` mints the reload machinery from the ROOT flag only, so a subtree `true` would unload ASTs the run could never reload. |
| Whole-run clustering / gate stances (shed by once-S67) | `similarity_threshold`, `tree_threshold`, `min_cluster_impact`, `max_df`, `candidate_budget`, `sort`, `fail_on_impact`, `max_clusters`, `optional_blocks` | Every reader receives the root config — admission without observability was the dishonesty; refusal replaces it. |

Two related truths ride the root layer only: at the root level a `paths`
key STILL outranks the CLI scan roots (config-file-over-argv precedence),
but the replacement is no longer silent — a run whose config file
overrode the caller's roots prints a `phpdup: warning:` load note naming
both sides. And `parallelism` / `optional_blocks.min_segment_length`
remain documented-inert at the ROOT layer (once-078 wire-or-retire
families) — inert-at-root is a wiring question for the owner ruling;
inert-BUT-ADMITTED per-directory is the lie this table forbids.

