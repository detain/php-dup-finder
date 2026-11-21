# phpdup — Phase 4 execution plan (plan_review.md)

Generated from `reviews/annotations/php-dup-finder__{engine,output,shell,once}.md` (237 annotated steps → **247 plan steps** (Phase-5 r1: 11 DD parents split into 22 half-legs; once-S81 absorbed into once-S80)) and the 304 consolidated findings (`reviews/findings/php-dup-finder__*.md`). Buckets: MM=123 HH=107 DD=17; total ≈ 21,005 min. Companion machine queue: `plan_review.queue.tsv` (row order = execution order below). Every step below is self-contained: an executing agent needs nothing else.

## Orchestrator instructions

Read **only** this section plus `plan_review.queue.tsv`. Step details exist solely for the working agents.

- **Scheduler:** the concurrency is `EXEC_CONCURRENCY` (default 15, max 20, one value to change). A step is **ready** when its status is `pending`, all its prerequisites are `done`, and none of its locks overlap the locks of a running step. Whenever a slot frees up, fill it with the first ready step in queue order. Keep every slot busy while ready steps exist.
- **Settings:** `WORK_DIR = reviews/` · `MAX_FIX_ROUNDS = 10` · `EXEC_CONCURRENCY = 15` (default; hard max 20).
- **Per-step loop.** Each agent occupies one slot while it runs, and the step keeps its locks for the whole loop:
  1. An implementer agent does the step.
  2. A reviewer agent checks the change for issues and missed items, and checks that it fully completes the step and passes the acceptance check.
  3. If the reviewer finds nothing, the step is `done`. Release its locks.
  4. Otherwise a fixer agent fixes the reported issues. Go back to 2.
  5. After `MAX_FIX_ROUNDS` rounds, mark the step `blocked` and release its locks. Mark steps that depend on it `skipped`.
- **Agent prompts stay tiny.** The orchestrator passes only the role, step ID, plan path, and any issues-file path. The agent reads its own step detail from `plan_review.md`.
- **Replies:**
  - implementer: `DONE <step> <commit-sha|none>`
  - reviewer (read-only): `CLEAN <step>` or `ISSUES <step> <n> WORK_DIR/exec/<step>-r<round>.md`
  - fixer: `DONE <step> <commit-sha|none>`
- **Status:** only the orchestrator updates the `status` column of the queue file (`pending → running → done | blocked | skipped`). That file is the resume point after a restart.
- **Git:**
  - Implementers and fixers commit their own work. Reviewers never edit.
  - Commit directly to `master` and push. No branches, no PRs.
  - Other agents share the tree, so stage only explicit paths, never `git add -A` or `git add .`.
  - Before pushing, run `git pull --rebase`. Resolve conflicts only inside your own files; otherwise report the conflict in your reply. Retry the push until it succeeds.
- **Worktrees (if used):** merge each worktree's work into `master` in the main repo directory, then remove the worktree. At the end, confirm that no worktrees remain.
- **Finish:** when no step is pending or running, report the counts of done, blocked, and skipped steps, and list the blocked step IDs.

- **Terminal safety (every agent prompt MUST carry this bullet, verbatim):**

  **Never touch the user's terminal multiplexer.** Your shell inherits `$TMUX`, so a bare `tmux` command acts on the user's live tmux/byobu server, and `tmux kill-server` there destroys every window they have open. To drive an app in a PTY, prefix EVERY call with `env -u TMUX -u TMUX_PANE tmux -L <unique-name>` (for example `<step>-<role>`) and kill only that server (`tmux -L <unique-name> kill-server`). Never run `kill-server`, `kill-session`, `switch-client`, `attach` or `send-keys` without `-L`, and never `screen -X` a session you didn't create. For a one-shot PTY run prefer `script -qec '<cmd>' /dev/null`. Write scratch files under `/tmp`, never into the target tree.

- **Resume:** after restart/compaction re-read the queue file; rows still `running` were interrupted — restart them. A blank/partial agent reply → resume the SAME agent id with a bare `continue` (≤10 rungs) before any restart decision.

## Program laws (cross-step invariants every agent must honor)

- **Golden-serial:** `tests/Golden/` (notify-exact, sql-exact, optional-near-dups) is a single-writer resource — only one step touching it may run at a time. The step's own locks carry `tests/Golden/` as its queue token. Only regen lanes carry it — verify-only lanes run the Golden suite read-free without the token (their zero-move check lives in acceptance). Regen holders (Phase-5 r1 re-derived): engine-S01, engine-S02b, engine-S08b, once-S56, engine-S34b, engine-S15, engine-S10, output-S27, output-S28, once-S37, once-S55, output-S37, once-S93. Never run `UPDATE_SNAPSHOTS=1` against this checkout except inside a step explicitly flagged GOLDEN-STEP, and then read every diff line; zero-golden is the default and every moved golden must be disclosed in the commit message.
- **Schema window:** `JsonSchemaSpec::SCHEMA_VERSION` is 1.6 at master. The ONLY sanctioned bumps: output-S27 (1.6→1.7) and once-S37 (1.7→1.8, owner-approved row); once-S55 rides the once-S37 window without a bump. Any other step that would change the JSON cluster/summary shape must not — report instead.
- **psalm-baseline.xml never grows.** One sanctioned re-baseline lane (output-S01); every other step keeps it equal-or-smaller.
- **Exit-code contract (docs-truth):** 0 = ok incl. findings, 1 = runtime/unknown-option, 2 = invalid config, 3 = gate-trip — per `docs/CLI.md`; shell-S03/once-S75 reconcile the 130 convention. Do not silently redefine an exit code.
- **Socketpair law:** worker-wire payloads are scalars only (no objects/`Serializable` graphs) — new fields go as JSON strings through the allow-listed envelope.
- **PHP setup:** composer.lock is gitignored — never stage it. No `composer install` in-place (copy to /tmp when deps are needed). PHP 8.1 floor for language constructs in `src/` per AGENTS.md, toolchain runs 8.3+.
- **Caliber files:** `AGENTS.md`/`CLAUDE.md`/`.claude/` are caliber-managed — lanes never edit them; report desired text (once-S80 owns the drift ledger).
- **phpunit discipline (user rule):** run a suite ONCE to a file (`vendor/bin/phpunit … < /dev/null > "$OUT" 2>&1`, $OUT under /tmp), inspect the file, delete it. Never pipe phpunit into tail/grep live.
- **Per-dir belt:** every key admitted by `ConfigLoader::shapeOverrides()` must be consumed by `Config::withOverrides()` (or consciously banked with an R8 note) — `PerDirOverrideBeltTest` is the guard; do not loosen it.
- **Artifacts are UTC-stable:** no wall-clock timestamps in committed reports/snapshots; `FindingId`/cluster ids stay content-derived.
- **Suite-figure trio:** counts in README/status badges are welder rows at closeout — lanes only REPORT owed figure changes, never edit the trio files.

## Step catalogue

Order below = queue order (severity-first ready schedule: ready steps owning a CRITICAL finding pop first, then shortest-first; prerequisites never violated). Ranks match `plan_review.queue.tsv` rows 1:1. Order re-derived by severity-first Kahn (pop-key (CRITICAL-ownership, est_min, id)) at the Phase-5 owner-decision pass, per owner ruling D1(b); previously Kahn (est_min, id) min-heap at Phase-5 round 1.

### engine-S01 — Near-dup candidate generation is dead below 200 blocks (critical)

- **Queue rank:** 1 of 247
- **Severity mix:** critical=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-engine-001
- **Prereqs:** none

- **Files:** `src/Index/NgramInvertedIndex.php`, `tests/Unit/Index/NgramInvertedIndexTest.php`,
  `tests/Golden/optional-near-dups.json` (regen), optionally `tests/Fuzz/DetectionRateTest.php`
  (threshold expectations if the suite was tuned around the dead zone).
  near-dup output (S08, S10, S15), and S18/S24 edit this file.
- **Shared resources:** tests/Golden/optional-near-dups.json (GOLDEN-MOVES, mandatory); no config
  flag changes — AutoTuner's tiny-corpus tier is routed to the shell group (see Outside), untouched here.
- **Fix shape:** `maxDf = max(2, (int)ceil($blockCount * $maxDocumentFrequency))` per finding.
- **Acceptance:** `bin/phpdup analyze tests/Fixtures/optional --no-cache` reports ≥1 cluster (was
  "4 blocks · 0 clusters · clean"); `vendor/bin/phpunit --testsuite Unit --filter NgramInvertedIndex`
  green incl. new pair at df=2 with N<200 and unchanged behavior at `--max-df` extremes; Golden suite
  green with regenerated `optional-near-dups.json` showing clusters>0 and non-empty declared_twins
  (diff disclosed); Fuzz suite green.


- **Phase-4 Outside merge:** ENGINE-PDDETECT out-of-group 1 (golden governance) — the optional-near-dups re-pin must be a separate disclosed diff in this lane; never let it silently re-pin behind another step's UPDATE_SNAPSHOTS.

### engine-S02a — Role-swap exact-clone lie + Param double-rename in Normalizer — half A: writer-side sentinel redesign — canonicalizeNames per-arm/positional key sentinels, kills the engine-055 double-rename, `__V0` alias guard, Normalizer unit pins.

- **Queue rank:** 2 of 247
- **Severity mix:** critical=1 low=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-002, php-dup-finder-engine-055
- **Prereqs:** none  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: writer-side sentinel redesign — canonicalizeNames per-arm/positional key sentinels, kills the engine-055 double-rename, `__V0` alias guard, Normalizer unit pins. Sibling: engine-S02b — together the two legs replace engine-S02 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S02 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Normalization/Normalizer.php` (canonicalizeNames :445-498, canonicalizeVariables
  :399-420, call site :85-88), `tests/Unit/Normalization/NormalizerTest.php`,
  `tests/Unit/Normalization/NormalizerBareReturnTest.php` (slot renumbering ripples),
  `tests/Golden/*.json` (regen if streams shift), `src/Clustering/Clusterer.php` ONLY if the
  "demote permutation-dependent pairs" variant is chosen (badge/similarity cap in the scoring tier —
  if so, this step joins the Clusterer chain after S15).
- **Shared resources:** tests/Golden (aggressive-mode canonical streams change → all three
  snapshots may move; GOLDEN-MOVES-likely); `MatchTier`/`Cluster::$pairs` semantics if the
  role-swapped badge path is chosen; MatchTier.php enum roster.
- **Fix shape:** one coherent redesign: key rename sentinels per-arm/positionally (not corpus-free
  first occurrence), which also kills the 055 double-rename. Decide with S03/S04 (same file): keep
  the design inside Normalizer to avoid the Clusterer coupling.
- **Acceptance:** unit: branch-role-swapped if/else pair from the finding no longer canonicalizes to
  byte-identical streams (or scores <1 / carries the badge); Param/body slots agree (user var `x`
  gets ONE sentinel in Param and body); a user variable literally named `__V0` cannot alias the
  generated space; `vendor/bin/phpunit --testsuite Unit --filter Normalizer` green; Golden suite
  green after regen with disclosed per-snapshot diff.

### engine-S02b — Role-swap exact-clone lie + Param double-rename in Normalizer — half B: GOLDEN-STEP re-pin leg — regenerate the three goldens with disclosed per-snapshot diffs once the writer bytes settle; owns the `tests/Golden/` token.

- **Queue rank:** 3 of 247
- **Severity mix:** critical=1 low=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-002, php-dup-finder-engine-055
- **Prereqs:** engine-S02a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: GOLDEN-STEP re-pin leg — regenerate the three goldens with disclosed per-snapshot diffs once the writer bytes settle; owns the `tests/Golden/` token. Sibling: engine-S02a — together the two legs replace engine-S02 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S02 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Normalization/Normalizer.php` (canonicalizeNames :445-498, canonicalizeVariables
  :399-420, call site :85-88), `tests/Unit/Normalization/NormalizerTest.php`,
  `tests/Unit/Normalization/NormalizerBareReturnTest.php` (slot renumbering ripples),
  `tests/Golden/*.json` (regen if streams shift), `src/Clustering/Clusterer.php` ONLY if the
  "demote permutation-dependent pairs" variant is chosen (badge/similarity cap in the scoring tier —
  if so, this step joins the Clusterer chain after S15).
- **Shared resources:** tests/Golden (aggressive-mode canonical streams change → all three
  snapshots may move; GOLDEN-MOVES-likely); `MatchTier`/`Cluster::$pairs` semantics if the
  role-swapped badge path is chosen; MatchTier.php enum roster.
- **Fix shape:** one coherent redesign: key rename sentinels per-arm/positionally (not corpus-free
  first occurrence), which also kills the 055 double-rename. Decide with S03/S04 (same file): keep
  the design inside Normalizer to avoid the Clusterer coupling.
- **Acceptance:** unit: branch-role-swapped if/else pair from the finding no longer canonicalizes to
  byte-identical streams (or scores <1 / carries the badge); Param/body slots agree (user var `x`
  gets ONE sentinel in Param and body); a user variable literally named `__V0` cannot alias the
  generated space; `vendor/bin/phpunit --testsuite Unit --filter Normalizer` green; Golden suite
  green after regen with disclosed per-snapshot diff.

### engine-S23a — Cache blob integrity: serialize crash guard, nested decode validation, TokenCache parity, perms — half A: crash guard + nested decode + framing/seal + AstCache perms + shared helper home + AstCacheTest + 10k-depth exit 0.

- **Queue rank:** 4 of 247
- **Severity mix:** critical=1 medium=3
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-004, php-dup-finder-engine-017, php-dup-finder-engine-018, php-dup-finder-engine-020
- **Prereqs:** none  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: crash guard + nested decode + framing/seal + AstCache perms + shared helper home + AstCacheTest + 10k-depth exit 0. Sibling: engine-S23b — together the two legs replace engine-S23 (270 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S23 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Parsing/AstCache.php` (put :86 try/catch + depth skip; get :58-70 recursive Node
  validation + TypeError→miss + length-framing/seal; mkdir 0700; chmod 0600 on writes),
  `src/Parsing/TokenCache.php` (mirror loop via the new shared helper; mkdir/perms), shared helper
  new location (either `src/Parsing/` private helper or small `src/Util/` class — decide in-step),
  `tests/Unit/Parsing/AstCacheTest.php` (NEW — none exists today),
  `tests/Unit/Parsing/TokenCacheTest.php`, `tests/Unit/Persistence/SerializedClassAllowListTest.php`
  (allow-list interplay only).
  while the class is alive — if the catalog later deletes it, the removal just takes the helper's
  second call site with it).
- **Shared resources:** blob framing = storage-format change → bump the cache key/version marker
  (AstCache VERSION token or filename scheme) → interacts with S24 (keyFor) and S25 — keep the
  marker line trivially mergeable; `declare(strict_types=1)` fatal surfaces under failOnNotice.
- **Acceptance:** depth fuzz: generating a 10k-deep array-literal file and analyzing with cache ON →
  run exits 0 (was rc=255 fatal at AstCache.php:86), file simply uncached; unit battery: truncated
  blob→miss, appended-garbage→miss (framing), nested-`__PHP_Incomplete_Class` blob (SEC nested.txt
  shape)→miss not fatal, TokenCache tampered→miss; stat modes: fresh cache dir 0700, blobs 0600
  under umask 002; full Unit+Integration green.

### engine-S23b — Cache blob integrity: serialize crash guard, nested decode validation, TokenCache parity, perms — half B: TokenCache parity via the shared helper + tampered→miss + 0700/0600 census under umask 002 + allow-list interplay re-run.

- **Queue rank:** 5 of 247
- **Severity mix:** critical=1 medium=3
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-engine-004, php-dup-finder-engine-017, php-dup-finder-engine-018, php-dup-finder-engine-020
- **Prereqs:** engine-S23a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: TokenCache parity via the shared helper + tampered→miss + 0700/0600 census under umask 002 + allow-list interplay re-run. Sibling: engine-S23a — together the two legs replace engine-S23 (270 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S23 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Parsing/AstCache.php` (put :86 try/catch + depth skip; get :58-70 recursive Node
  validation + TypeError→miss + length-framing/seal; mkdir 0700; chmod 0600 on writes),
  `src/Parsing/TokenCache.php` (mirror loop via the new shared helper; mkdir/perms), shared helper
  new location (either `src/Parsing/` private helper or small `src/Util/` class — decide in-step),
  `tests/Unit/Parsing/AstCacheTest.php` (NEW — none exists today),
  `tests/Unit/Parsing/TokenCacheTest.php`, `tests/Unit/Persistence/SerializedClassAllowListTest.php`
  (allow-list interplay only).
  while the class is alive — if the catalog later deletes it, the removal just takes the helper's
  second call site with it).
- **Shared resources:** blob framing = storage-format change → bump the cache key/version marker
  (AstCache VERSION token or filename scheme) → interacts with S24 (keyFor) and S25 — keep the
  marker line trivially mergeable; `declare(strict_types=1)` fatal surfaces under failOnNotice.
- **Acceptance:** depth fuzz: generating a 10k-deep array-literal file and analyzing with cache ON →
  run exits 0 (was rc=255 fatal at AstCache.php:86), file simply uncached; unit battery: truncated
  blob→miss, appended-garbage→miss (framing), nested-`__PHP_Incomplete_Class` blob (SEC nested.txt
  shape)→miss not fatal, TokenCache tampered→miss; stat modes: fresh cache dir 0700, blobs 0600
  under umask 002; full Unit+Integration green.

### engine-S06a — `--db-aware` name-only folding fabricates EXACT DB clones (critical) — half A: receiver-evidence gate — folding the get/all/save tier out of pure-name matching (store-vs-logger evidence), CustomDbSymbols + DbOpCanonicalizer pins.

- **Queue rank:** 6 of 247
- **Severity mix:** critical=1
- **Size:** HH (~160 min)
- **Covers:** php-dup-finder-engine-003
- **Prereqs:** none  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: receiver-evidence gate — folding the get/all/save tier out of pure-name matching (store-vs-logger evidence), CustomDbSymbols + DbOpCanonicalizer pins. Sibling: engine-S06b — together the two legs replace engine-S06 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S06 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Normalization/DbOpCanonicalizer.php` (rewriteMethodCall :149-190,
  buildSynthetic :241-266), `src/Normalization/DbOpRegistry.php` (METHOD_OPS :58-123),
  `tests/Unit/Normalization/DbOpCanonicalizerTest.php`, `tests/Unit/Normalization/CustomDbSymbolsTest.php`,
  `tests/Unit/Normalization/DbOpRegistryTest.php`; possible new registry entry shape →
  `profiles/db-aware-*.json` + `docs/config-schema.json` (cross-group roster — see conflicts).
- **Shared resources:** if receiver-evidence config keys are admitted, the PerDirOverrideBeltTest
  emit/consume invariant (admitted key must be consumed or consciously banked) and docs/config-schema.json
  come into play; db-aware symbol packs are shared with the cli/shell group (ProjectProfileDetector).
- **Fix shape:** receiver evidence before folding generic names (`get/all/save` out of pure-name
  tier); retain sorted-column-set signature in synthetic args for QUERY/EXECUTE so "same table,
  different columns" is near-dup not type-1.
- **Acceptance:** the finding's two repros: two different `exec('UPDATE users SET …')` payloads no
  longer EXACT (unit asserts differing synthetic streams); `$this->store->get` vs `$this->logger->get`
  not folded without DB evidence; genuine same-payload DB clones still cluster; `--filter 'DbOp|Custom'`
  green; goldens unmoved (dbAware defaults false).

### engine-S06b — `--db-aware` name-only folding fabricates EXACT DB clones (critical) — half B: column-set synthetic signature + registry entry shape + profiles/docs/config-schema rows + DbOpRegistryTest; closes both repros.

- **Queue rank:** 7 of 247
- **Severity mix:** critical=1
- **Size:** HH (~140 min)
- **Covers:** php-dup-finder-engine-003
- **Prereqs:** engine-S06a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: column-set synthetic signature + registry entry shape + profiles/docs/config-schema rows + DbOpRegistryTest; closes both repros. Sibling: engine-S06a — together the two legs replace engine-S06 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S06 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Normalization/DbOpCanonicalizer.php` (rewriteMethodCall :149-190,
  buildSynthetic :241-266), `src/Normalization/DbOpRegistry.php` (METHOD_OPS :58-123),
  `tests/Unit/Normalization/DbOpCanonicalizerTest.php`, `tests/Unit/Normalization/CustomDbSymbolsTest.php`,
  `tests/Unit/Normalization/DbOpRegistryTest.php`; possible new registry entry shape →
  `profiles/db-aware-*.json` + `docs/config-schema.json` (cross-group roster — see conflicts).
- **Shared resources:** if receiver-evidence config keys are admitted, the PerDirOverrideBeltTest
  emit/consume invariant (admitted key must be consumed or consciously banked) and docs/config-schema.json
  come into play; db-aware symbol packs are shared with the cli/shell group (ProjectProfileDetector).
- **Fix shape:** receiver evidence before folding generic names (`get/all/save` out of pure-name
  tier); retain sorted-column-set signature in synthetic args for QUERY/EXECUTE so "same table,
  different columns" is near-dup not type-1.
- **Acceptance:** the finding's two repros: two different `exec('UPDATE users SET …')` payloads no
  longer EXACT (unit asserts differing synthetic streams); `$this->store->get` vs `$this->logger->get`
  not folded without DB evidence; genuine same-payload DB clones still cluster; `--filter 'DbOp|Custom'`
  green; goldens unmoved (dbAware defaults false).

### shell-S01 — Async serve: enforce `--serve-root` confinement + crash-proof request handling

- **Queue rank:** 8 of 247
- **Severity mix:** critical=3
- **Size:** HH (~160 min)
- **Covers:** php-dup-finder-once-001, php-dup-finder-shell-001, php-dup-finder-shell-002
- **Prereqs:** none

**Files:** src/Server/Application.php, src/Server/AnalysisWorker.php, src/Cli/ServeCommand.php, bin/phpdup-worker, tests/Unit/Server/ApplicationTest.php, tests/Unit/Cli/ServeCommandTest.php
**Shared resources:** docs/SERVER.md confinement wording becomes true (row check; full restatement in S09); ServeCommand.php + AnalysisWorker.php file locks (chain head); Socketpair Law — serveRoot travels as the THIRD argv token of the `bin/phpdup-worker <sock> <dir> [<serve-root>]` spawn string (escapeshellarg'd), never as serialized state.
**Acceptance:**
- Unit: `validatePaths` non-string element (`{"paths":[123]}`) → 400 array result, no TypeError; enqueue of payload with absolute path outside serve root → 400 with no job minted; worker process handed the same payload refuses (defense-in-depth leg) — tests in ApplicationTest.
- Live probe (one-shot PTY or background + curl): `serve --serve-root=$PWD` + `POST /jobs {"paths":["/etc"]}` → 400 (was 202 + served result); `POST /analyze {"paths":[123]}` → 400 and `GET /healthz` still 200 immediately after (daemon alive — was exit 255).
- Both docblock promises (Application.php:50-52, ServeCommand.php:39-40) now match code.


- **Phase-4 merge (absorbs once-S01):** once-001 (async POST /jobs bypasses serve-root confinement). shell-S01's confinement + worker-side defense legs ARE the once-001 fix; the once annotator confirmed identical acceptance probes.

- **Phase-4 Outside merge:** ENGINE-SEC out-of-group 4 (serve job payloads setting ml_pair_url) — add an admission leg: enumerate which config fields the `RUN <jobId> <configJson>` frame accepts; any ml_pair_url admission must run through engine-S19's fail-closed gate (cross-ref once-S54). Also carries once-001 (merged; worker re-validation is this step's defense-in-depth leg).

### output-S10 — ArchitecturalAnalyzer: delete phantom registry {@see}

- **Queue rank:** 9 of 247
- **Severity mix:** low=1
- **Size:** MM (~5 min)
- **Covers:** php-dup-finder-output-062
- **Prereqs:** none

- Covers: 062
- Files: `src/Architecture/ArchitecturalAnalyzer.php`
- Size: MM (5 min)
- Prereqs: none.
- Acceptance: `grep -n "ArchitecturalAnalyzerRegistry" src/` → zero; docblock matches CLAUDE.md recipe (ReportStage::run() array); GATE.

### output-S04 — GitLab SAST timestamp: RFC3339 Z

- **Queue rank:** 10 of 247
- **Severity mix:** low=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-output-049
- **Prereqs:** none

- Covers: 049
- Files: `src/Reporting/GitLabSastReporter.php`, `tests/Unit/Reporting/GitLabSastReporterTest.php`
- Size: MM (10 min)
- Prereqs: none. Conflicts: file also in S02/S03 (adjacent lines; serial note only).
- Acceptance: `--filter GitLabSastReporterTest` — updated pin asserts `dateLastModified` matches `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/`; GATE.

### shell-S38 — Phpdup facade: empty-paths guard

- **Queue rank:** 11 of 247
- **Severity mix:** low=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-shell-044
- **Prereqs:** none

**Files:** src/Phpdup.php, tests/Unit/PhpdupTest.php
**Shared resources:** published-library surface (behavior change for programmatic users — throwing on empty vs silent empty report; disclose in report; docs/CLI.md NOT affected since this is the lib path — but docs/adr or README lib example? check at lane time, report-only).
**Acceptance:**
- Unit: `(new Phpdup())->paths([])->run()` and `(new Phpdup())->run()` → InvalidArgumentException with actionable message; `paths(['src'])` happy path unchanged; CLI exit 2 path (Command.php:196-202) untouched and green.

### output-S08 — BenchmarkResultsRenderer: markdown cell escape

- **Queue rank:** 12 of 247
- **Severity mix:** low=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-output-057
- **Prereqs:** none

- Covers: 057
- Files: `src/Testing/BenchmarkResultsRenderer.php`, `tests/Unit/Bench/BenchmarkResultsRendererTest.php`
- Size: MM (15 min)
- Prereqs: none.
- Acceptance: `--filter BenchmarkResultsRenderer` — name containing `|` renders `\|` and table column count holds; GATE.

### shell-S29 — DebugLogger destructor: safe-close

- **Queue rank:** 13 of 247
- **Severity mix:** medium=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-shell-020
- **Prereqs:** none

**Files:** src/Pipeline/DebugLogger.php, tests/Unit/Pipeline/DebugLoggerTest.php (new — no current file)
**Shared resources:** none.
**Acceptance:**
- Probe: `bin/phpdup analyze src --debug-log=/proc/definitely-not-writable/x.log` → run completes, exit 0 with findings (was: exit 255 at shutdown), warning surfaced; unit: destructor with `handle===false` does not fatal (is_resource guard + handle=nullafter close state machine).

### once-S69 — --debug-log unwritable path closed

- **Queue rank:** 14 of 247
- **Severity mix:** medium=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-079
- **Prereqs:** shell-S29 (safe-close core)

**Files:** src/Pipeline/DebugLogger.php, tests/Unit/Pipeline/PipelineTest.php
**Acceptance:** probe: --debug-log /nonexistent/x.log → one clear error line, no fopen warning spam, exit 2 (usage posture per shell ruling) and NO post-report TypeError 255; destructor close guarded (shell-S29 verify census).

### engine-S35 — MemoryDebug labels emalloc bytes as "RSS" (mixed metrics)

- **Queue rank:** 15 of 247
- **Severity mix:** low=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-engine-045
- **Prereqs:** none

- **Files:** `src/Util/MemoryDebug.php:8-16`, grep consumers (Clusterer :214/220/245/290, Tui,
  Command --stats) for label-format assertions in tests (`tests/Unit/Cli/CliStatsTest` or wherever
  stats strings are pinned — update pins).
- **Shared resources:** --stats output text (TUI panel line + docs/media GIF prose unchanged —
  report-only if docs quote the exact label).
- **Acceptance:** debug line uses `emalloc=` / `peak_alloc=` (or both true-RSS consistently — pick
  per finding, one metric per label, disclose); any test matching the old "RSS" token updated;
  `bin/phpdup analyze src --stats` eyeball + full green.

### output-S06 — PlacementAdvisor: single-file scan roots; dead import

- **Queue rank:** 16 of 247
- **Severity mix:** low=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-output-051
- **Prereqs:** none

- Covers: 051
- Files: `src/Refactor/PlacementAdvisor.php`, `tests/Unit/Refactor/PlacementAdvisorTest.php`
- Size: MM (20 min)
- Prereqs: none. Conflicts: file also in S19 (charset gate).
- Acceptance: `--filter PlacementAdvisorTest` — regression case: root == file itself yields no "does not resolve under any configured scan root" note; `grep -n "use Phpdup.Cli.Config" src/Refactor/PlacementAdvisor.php` gone (or load-bearing docblock rewritten); GATE.

### output-S11 — pickBestSeed docblock truth + is_finite threshold guards

- **Queue rank:** 17 of 247
- **Severity mix:** low=1
- **Size:** MM (~25 min)
- **Covers:** php-dup-finder-output-056
- **Prereqs:** none

- Covers: 056
- Files: `src/Refactor/AntiUnifier.php` (docblock :158-161 only), `src/Reporting/CoherenceAnalyzer.php` (:31), `src/Reporting/Ranker.php`, `src/Cli/Config.php` (range checks → `!is_finite()`), tests: `tests/Unit/Reporting/CoherenceAnalyzerTest.php`, `tests/Unit/Cli/ConfigTest.php` (or existing config-validation suite)
- Size: MM (25 min)
- Prereqs: none — but the AntiUnifier docblock edit must re-check position after S25/S26/S30 (serial lock note).
- Conflicts: Config.php is shell/shared (six-edit-site rule NOT triggered — no new keys, only tightening validation of existing range fields).
- Acceptance: `--filter ConfigTest` NAN/NAN-inf rejected with descriptive error (exit 2 path observable via `bin/phpdup analyze --config` with `{"similarity_threshold": NaN}` → invalid, per exit contract); CoherenceAnalyzer test passes; docblock no longer promises unimplemented margin; GATE.


- **Phase-4 Outside merge:** shell's (output) row — Ranker.php:42-43 minSafety range re-throw duplicates the CLI-side admission contract; drop the crash leg here (shell-S24 removes its surface).

### engine-S27 — AstParser::parseFile silently nulls on unreadable files (no $lastError)

- **Queue rank:** 18 of 247
- **Severity mix:** medium=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-engine-026
- **Prereqs:** none

- **Files:** `src/Parsing/AstParser.php:29-36`, `tests/Unit/Parsing/AstParserTest.php` (NEW).
- **Shared resources:** caller PreprocessWorker :169-171 message quality improves without change;
  FileScanner skipNotes path is shell group (do not edit).
- **Acceptance:** unit: parseFile('/nonexistent') and a chmod-000 file → null AND `lastError` set
  with the path; docblock claim now true; suites green (root user caveat: chmod-000 is readable by
  root — use a nonexistent/deleted-file variant if the runner is root).

### engine-S36 — BlockIndex duplicate-id invariant unenforced

- **Queue rank:** 19 of 247
- **Severity mix:** low=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-engine-047
- **Prereqs:** none

- **Files:** `src/Index/BlockIndex.php:25-30`, `tests/Unit/Index/BlockIndexTest.php` (NEW).
- **Shared resources:** none.
- **Acceptance:** unit: add() with colliding id + different object → LogicException naming the id
  (or deterministic skip — pick loud); re-add same object tolerated/idempotent; three-view
  (byId/all/byHash) consistency asserted; Clusterer/Pipeline suites green.

### once-S59 — GitLab SAST timestamps deterministic + honest window

- **Queue rank:** 20 of 247
- **Severity mix:** medium=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-066
- **Prereqs:** output-S04 (Z format core)

**Files:** src/Reporting/GitLabSastReporter.php, tests/Unit/Reporting/GitLabSastReporterTest.php
**Shared resources:** w/ S38 Timeseries clock story.
**Acceptance:** two runs over identical synthetic Report → byte-identical (fixed-clock test helper, no wall clock in golden-less path); start==end only when report duration truly zero; carries from pipeline metadata (where report has none, field omitted honestly not fabricated).

### once-S82 — Root-doc archive + tracked-junk untrack

- **Queue rank:** 21 of 247
- **Severity mix:** medium=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-102
- **Prereqs:** none

**Files:** plan.md, findings.md, prompt.md
**Shared resources:** [OWNER: git-rm vs move to docs/archive/ — default rm]; improve_logic*.md owner-file constraint: contradiction row is REPORT-ONLY (S80 packet rider), never edited here.
**Acceptance:** June-era trio archived/removed per ruling (git ls-files zero); README/AGENTS references to the trio (grep) die first. The `.claude/settings.json` nvm-path portability and `.claude/scheduled_tasks.lock` untrack legs became OWNER ACTIONS at Phase-5 r1 (I3/D3a — caliber paths, lanes never edit them): rows recorded in the once-S80 packet + ## Owner decisions.

### output-S01 — psalm baseline re-derive + staleness detector on

- **Queue rank:** 22 of 247
- **Severity mix:** high=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-output-013
- **Prereqs:** none

- Covers: 013
- Files: `psalm-baseline.xml`, `psalm.xml`, `src/Refactor/PatternRecognizer.php` (verify-only; nothing to code-fix — entries are dead)
- Size: MM (30 min)
- Prereqs: none — but schedule FIRST for its file, and Phase-4 should fold the engine's out-of-group dead entries (BlockExtractor ×2, FileScanner ×1) into this same re-baseline rather than a second lane.
- Conflicts: psalm-baseline.xml single-writer (ledger row).
- Acceptance: `vendor/bin/psalm` rc=0; `vendor/bin/psalm --no-cache` with stub baseline re-run per CONV-01 shows remaining entries all live; psalm.xml `findUnusedBaselineEntry="true"` and psalm still rc=0; entry count drops 14→5; phpstan untouched, GATE.


- **Phase-4 Outside merge (formalized):** engine's psalm-baseline dead entries (BlockExtractor ×2, FileScanner ×1) fold into THIS single tree-wide re-baseline — Phase-4 ruling: no second baseline lane.

### output-S09 — FuzzCorpusGenerator: template-name validation + var_export values

- **Queue rank:** 23 of 247
- **Severity mix:** low=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-output-061
- **Prereqs:** none

- Covers: 061
- Files: `src/Testing/FuzzCorpusGenerator.php`, matching test under `tests/Unit/` (locate; extend if present)
- Size: MM (30 min)
- Prereqs: none. Conflicts: file also in S23 (write fail-loud).
- Acceptance: generator test — plan template key `../x` throws fail-loud; a hole `value` containing `'); system('id'); //` lands quoted via var_export; generated file `php -l` clean; `tests/Fuzz/DetectionRateTest.php` still green (`--testsuite Fuzz`); GATE.

### output-S12 — CoherenceAnalyzer: symmetric pairs once

- **Queue rank:** 24 of 247
- **Severity mix:** medium=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-output-027
- **Prereqs:** none

- Covers: 027
- Files: `src/Reporting/CoherenceAnalyzer.php`, `tests/Unit/Reporting/CoherenceAnalyzerTest.php`
- Size: MM (30 min)
- Prereqs: none; serial with S11 (same file, tiny).
- Acceptance: `--filter CoherenceAnalyzerTest` — existing outlier sets unchanged (pin before/after on same fixtures), call counter or assert via JaccardSimilarity spy: similarity() invocations halve; full `--testsuite Unit` green; goldens byte-identical; GATE.

### output-S13 — BaselineStore: hoist array_flip out of the compare loop

- **Queue rank:** 25 of 247
- **Severity mix:** medium=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-output-029
- **Prereqs:** none

- Covers: 029
- Files: `src/Reporting/BaselineStore.php`, `tests/Unit/Reporting/BaselineStoreTest.php`
- Size: MM (30 min)
- Prereqs: none.
- Acceptance: `--filter BaselineStoreTest` — verdicts identical on existing fixtures; added test with ≥2 baseline entries × ≥2 current entries passes; no behavior change observable in reports (goldens byte-identical); GATE.

### output-S17 — one safeSlug helper for seven copy-pasted sites

- **Queue rank:** 26 of 247
- **Severity mix:** low=1
- **Size:** MM (~35 min)
- **Covers:** php-dup-finder-output-063
- **Prereqs:** none

- Covers: 063
- Files: `src/Reporting/Slug.php` (new; or `Cluster::safeSlug()` — planning call, keep in Reporting to avoid Cluster mutation), `src/Reporting/DiffReporter.php`, `src/Reporting/RefactorPatchReporter.php`, `src/Reporting/RefactorTestReporter.php`, `src/Refactor/ApplyExtractor.php`, `tests/Unit/Reporting/SlugTest.php` (new)
- Size: MM (35 min)
- Prereqs: none functionally; execute AFTER S44 (or before S36/S43) to minimize ApplyExtractor patch-window conflicts; behavior-preserving so safe anywhere.
- Acceptance: `grep -cn "preg_replace('/\[^a-zA-Z0-9_-\]/', '_', "` src → 0; SlugTest round-trips current legacy ids byte-identically (`F`+hex unchanged); generated artifact filenames in existing reporter/apply tests unchanged; goldens byte-identical; GATE.

### shell-S06 — JobQueue: bounded memory for retained payloads

- **Queue rank:** 27 of 247
- **Severity mix:** low=1
- **Size:** MM (~35 min)
- **Covers:** php-dup-finder-shell-048
- **Prereqs:** none

**Files:** src/Server/JobQueue.php, tests/Unit/Server/JobQueueTest.php
**Shared resources:** none beyond JobQueue.php; job-status JSON shape in /api/jobs responses keeps byte-compat (S09 documents routes — statuses unchanged).
**Acceptance:**
- Unit: enqueue N pending jobs with large `paths` payloads → queue stores size+digest, not raw arrays (memory test: 100 × 16MiB-shape payloads retained < fixed budget, or payloads evicted while id/status survive — posture chosen by the lane, pinned by test); `peek`/`complete` paths still deliver the payload to the result writer (S01/S08 consumers unaffected — payloads fetched from the job, so if the async leg needs paths AFTER enqueue, the digest must carry enough info or the leg must snapshot the dispatch at 202 time: prove whichever posture via test).
- Existing JobQueueTest green.

### output-S14 — ClusterSort: Schwartzian decorate-sort-undecorate

- **Queue rank:** 28 of 247
- **Severity mix:** medium=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-output-030
- **Prereqs:** none

- Covers: 030
- Files: `src/Reporting/ClusterSort.php`, `tests/Unit/Reporting/ClusterSortTest.php`
- Size: MM (40 min)
- Prereqs: none.
- Acceptance: `--filter ClusterSortTest` — order byte-identical across all sort keys vs a fixture ordering pin (including ties — comparator tie semantics preserved; PHP usort stability notes respected); project() call-count test (spied subclass or counter static) equals N; GATE.

### output-S16 — BenchmarkScoreCalculator: straddling tolerance buckets

- **Queue rank:** 29 of 247
- **Severity mix:** medium=1
- **Size:** HH (~40 min)
- **Covers:** php-dup-finder-output-032
- **Prereqs:** none

- Covers: 032
- Files: `src/Testing/BenchmarkScoreCalculator.php`, new `tests/Unit/Bench/BenchmarkScoreCalculatorTest.php`
- Size: HH (40 min)
- Prereqs: none.
- Acceptance: `--filter BenchmarkScoreCalculatorTest` — explicit pair (line 99 vs 101, tol 2) matches; pairs >tol apart still do NOT match (no bucket inflation); existing bench consumers unchanged: Integration/`--filter EndToEndTest` + whatever test drives `bin/phpdup bench` (grep); GATE.


- **Phase-4 note:** once-S09 (benchmark family-granular recall) consumes this tolerance-bucket core — it depends on output-S16.

### once-S09 — Benchmark scoring: ground truth per family, competitor closure

- **Queue rank:** 30 of 247
- **Severity mix:** critical=1 high=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-once-009, php-dup-finder-once-053
- **Prereqs:** output-S16 (tolerance buckets core)

**Files:** src/Testing/BenchmarkScoreCalculator.php, bench/run.php, bench/score.php, bench/corpora, tests/Unit/Bench/BenchmarkScoreCalculatorTest.php
**Shared resources:** bench/* lock w/ S85/S86; README benchmark claims + feature-matrix numbers republished by S85 (this step only fixes math); GT format (bench/corpora) schema note to S86.
**Acceptance:** synthetic perfect detector scores 1.0 (unit); single-clone-per-family GT → family-granular recall; competitor pair-only GT gets transitive closure so pairwise tools score >0 on families; negative controls pin precision ≤0.5 when injected; tolerance unit asserts the advertised ±2-line rule (not floor(len/2)); real-corpus re-score is manual row feeding S85 commit.

### output-S21 — Severity routing for SARIF/Checkstyle labels + consumer list refresh

- **Queue rank:** 31 of 247
- **Severity mix:** medium=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-output-037
- **Prereqs:** none

- Covers: 037
- Files: `src/Reporting/Severity.php` (new `sarifLevel`/`checkstyleCategory` routing or extension of forImpact/forScore contract), `src/Reporting/SarifReporter.php:92`, `src/Reporting/CheckstyleReporter.php:41``src/Reporting/Severity.php:23-24` docblock list, `tests/Unit/Reporting/{SarifReporterTest,CheckstyleReporterTest}.php`
- Size: MM (40 min)
- Prereqs: none. Conflicts: — (caliber row routed to once-S80 packet at Phase-5 r1).
- Acceptance: `grep -n "'warning'" src/Reporting/{Sarif,Checkstyle}Reporter.php` → only via Severity:: calls; reporter tests pin level mapping for exact/near clusters unchanged vs old inline logic (byte-identical outputs on fixtures); docblock list matches `grep -rn "Severity::" src/Reporting/`; GATE.
- **Phase-5 r1 (I3/D3a):** desired `.claude/rules/reporting-patterns.md:17` consumer-list text → once-S80 packet rider — lane edits no caliber file.

### shell-S20 — FileScanner symlink containment

- **Queue rank:** 32 of 247
- **Severity mix:** high=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-006
- **Prereqs:** none

**Files:** src/Scanning/FileScanner.php, tests/Unit/Scanning/FileScannerTest.php
**Shared resources:** behavior change visible to all scan consumers (pipeline, serve — S01's confinement now has belt AND braces); docs/CLI.md scan-behavior rider (report row, edited in S24's doc pass? No: docs/CLI.md writer chain is S24→S28; add this rider to S24's list as a doc-only sentence); symlink-in-tree scans drop out-of-root PHP from results (user-visible: disclose).
**Acceptance:**
- Unit: root containing a symlinked dir/file pointing outside → target never yielded, skip note recorded (FileScanner::skipNotes()); symlink to a target INSIDE root still yields (canonicalize-compare posture); FileWatcher's same hazard (:320) handled in S33, not here.
- Probe: temp tree with `ln -s /etc` — `bin/phpdup analyze $tmp` reports only in-root files; combined S01 check: serve-side exfil route (finding 001's "Verified" repro) now blocked twice over.


- **Phase-4 Outside merge:** this step IS the durable root-cause fix for output's '(shell, root-cause) FileScanner FOLLOW_SYMLINKS :63' row — output-S38 (read-side half) and output-S52 (write-side half) depend on this landing; record the skipNotes posture output-S38 mirrors.

### shell-S21 — globToRegex: nested-quantifier ReDoS coalescing

- **Queue rank:** 33 of 247
- **Severity mix:** medium=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-030
- **Prereqs:** shell-S20 (same file; compile region independent but chain the writers)

**Files:** src/Scanning/FileScanner.php, tests/Unit/Scanning/FileScannerTest.php, tests/Unit/Cli/ExcludeSemanticsTest.php
**Shared resources:** exclude-regex semantics for patterns with adjacent `**/` segments (coalesced `(?:[^/]+/)*` form must accept the SAME language — equivalence table in test).
**Acceptance:**
- Unit: pattern equivalence matrix (`**/x`, `a/**/**/b`, `**/**/vendor/**` etc.) pre/post compile match the same paths incl. edge cases (root-relative, empty segments); a 45-level adversarial path against `**/**/**/…` excludes matches in <10ms (time-boxed CI-safe); preg_match returns int, never silent false from backtrack-limit blowout (raise pcre.backtrack_limit? no — the compiled regex must not need it: assert `preg_last_error()` PREG_NO_ERROR after the run).
- ExcludeSemanticsTest + FileScannerTest green.

### once-S78 — Hostile exclude pattern cannot wedge the scanner

- **Queue rank:** 34 of 247
- **Severity mix:** medium=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-095
- **Prereqs:** shell-S21 (bounded compiler port)

**Files:** src/Scanning/FileScanner.php, tests/Unit/Scanning/FileScannerTest.php
**Acceptance:** ReDoS probe (classic `(a+)+$`-style + unbounded ** chains) via --exclude + hostile .phpdup.json on /tmp: scan completes under fixed seconds budget; sugar-ignore parity test ported.

### shell-S36 — UpdateCommand: honest download completion

- **Queue rank:** 35 of 247
- **Severity mix:** medium=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-017
- **Prereqs:** none

**Files:** src/Cli/UpdateCommand.php, tests/Unit/Cli/UpdateCommandTest.php
**Shared resources:** self-update surface (never run `phpdup update` against the checkout in tests — mock transport / local file server only, hard rule).
**Acceptance:**
- Unit (test seam over stream resource): mid-read `fread === false` before EOF → downloadFile false; Content-Length=1000 but 500 written → false + byte-count error; fwrite partial (returns < strlen) → false or resume-loop (lane choice, pinned); happy path unchanged.
- Caller (:235) refuses signature/hot-swap when downloadFile false (assert no report-file rename onto a torn phar).
- UpdateCommandTest green.

### once-S05 — Update signature integrity rebuild (fail open → fail closed)

- **Queue rank:** 36 of 247
- **Severity mix:** critical=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-once-005
- **Prereqs:** shell-S36 (download completion)

**Files:** src/Cli/UpdateCommand.php, .github/workflows/release.yml, docs/RELEASE.md, tests/Unit/Cli/UpdateCommandTest.php
**Shared resources:** release.yml chain (S05→S11→S12); docs/RELEASE.md content rewrite (file exists NOW — findings-era 'absent' note corrected); [OWNER: generate asymmetric keypair + secret custody]; phar-stub key separation note to S102 (XP-09).
**Acceptance:** HMAC secret env moved to the SIGN step (proof: CI round-trip job signs artifact then verifies via UpdateCommand code-path, exit 0 on good / 1 on tamper); placeholder VERIFICATION_KEY → verification REFUSES (explicit error) instead of passing; raw-vs-base64 key forms identical both sides; `--allow-unsigned` deleted or confined to `--dev-allow-unsigned` with loud stderr; sha256 no longer trusted from same response as artifact (pin to signed manifest).

### engine-S12 — Block::$canonical guard is statically dead / fatal for unnormalized blocks

- **Queue rank:** 37 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-023
- **Prereqs:** none

- **Files:** `src/Clustering/Clusterer.php` (:231-242, :237 site), `tests/Unit/Clustering/ClustererTest.php`
  (new pin). Preferred fix = honest `isset()` guard (zero Block.php change). The alternate nullable-
  property fix (`public ?Node $canonical = null` in `src/Extraction/Block.php`) is DISCOURAGED inside
  this step: it makes every `$b->canonical` consumer across src/ (Refactor, Reporting, Parallel, Tui)
  possibly-null for phpstan → repo-wide annotation churn and cross-group collision with S18; if the
  executor prefers it, it becomes its own follow-up with those audits.
- **Shared resources:** none if isset chosen; Block.php docblock if not.
- **Acceptance:** unit feeding an unnormalized Block (never through Normalizer) into
  `generateCandidatePairs` → skipped, no `Error` fatal; existing 'Typed property' grep proves the
  fatal previously possible; `--filter Clusterer` green; phpstan still level-6 clean without
  baseline additions.

### engine-S13 — UnionFind answers "impossible id" with undefined behavior

- **Queue rank:** 38 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-049
- **Prereqs:** engine-S12 (Clusterer file chain).

- **Files:** `src/Clustering/Clusterer.php` (edges loop :382-408 + `final class UnionFind` :818+),
  `tests/Unit/Clustering/ClustererTest.php`.
- **Shared resources:** none.
- **Acceptance:** unit: edge referencing an id absent from the index → RuntimeException naming the
  id (or `find` throws LogicException per fix option) under `declare(strict_types=1)` — no
  undefined-key warning, no TypeError (phpunit failOnWarning=true makes the old shape fail loudly
  too — pin both the guard and the clean throw).

### engine-S26 — `--no-cache` must actually disable AST disk-cache writes on the preprocess path

- **Queue rank:** 39 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-019
- **Prereqs:** engine-S23b (same-file knowledge; also S25's policy makes the escape-hatch fix

- **Files:** `src/Parallel/PreprocessWorker.php:86` (construct `new AstCache('')` when cache
  disabled — `isEnabled()` :34-37 already no-ops), threading source (`src/Pipeline/Stages/PreprocessStage.php`
  constructor area, possibly `Config` if the worker needs a flag — cross-group shell files, minimal edit),
  `tests/Unit/Parallel/PreprocessWorkerIsolationTest.php`.
  coherent). NOT Command.php help-text (shell group's FUZZ-X02 row — do not touch :127).
- **Shared resources:** none.
- **Acceptance:** unit: worker with cache disabled leaves the cache dir byte-empty (no new files)
  yet completes fingerprinting from fresh parses; mirror of SEC-02 proof: decoy planted + `--no-cache`
  → decoy NOT served end-to-end; `--filter PreprocessWorker` + Pipeline suites green.

### engine-S28 — PluginRegistry constructs arbitrary autoloadable classes before the interface check

- **Queue rank:** 40 of 247
- **Severity mix:** medium=1 low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-021, php-dup-finder-engine-022
- **Prereqs:** none

- **Files:** `src/Normalization/PluginRegistry.php:44-50`, `tests/Unit/Normalization/PluginRegistryTest.php`,
  (autoloadable fixture class under `tests/Fixtures/` — engine-safe new file).
- **Shared resources:** the untrusted-config policy layer belongs to the once group (Outside row) —
  this step is mechanism-only (`is_subclass_of` gate before `new`, try/catch(\Throwable) wrap →
  RuntimeException naming the config entry).
- **Acceptance:** unit: fixture class with a side-effecting (marker-writing) argumentless
  constructor → marker file NEVER created, descriptive RuntimeException; constructor requiring
  args → wrapped RuntimeException naming the FQCN, not a bare ArgumentCountError; non-plugin
  behavior otherwise unchanged; `--filter PluginRegistry` green.

### engine-S32 — --low-memory 32-bit XOR-folded gram keys: collision bound + docblock claim

- **Queue rank:** 41 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-042
- **Prereqs:** none

- **Files:** `src/Fingerprint/CompactNgramBag.php:30-38`, `src/Fingerprint/NgramFingerprint.php:74-79`
  (comment), `tests/Unit/Fingerprint/CompactNgramBagTest.php`, `tests/Integration/FingerprintModeAcceptanceTest.php`.
- **Shared resources:** low-memory bag values are persisted in cached blocks (same roster concern
  as S18/S25 — if S18 already bumped versions, piggyback; else bump).
- **Acceptance:** unit: pack/unpack 64-bit positive key equality for high-bit digests; fold-collision
  pair from the doc's example now distinct; ≪1% claim corrected to corpus-wide birthday math or
  obsolete (keys 64-bit); `--filter 'CompactNgram|FingerprintMode'` green.

### engine-S37 — SqlTableExtractor: bracket residue + string-unaware CTE paren walker

- **Queue rank:** 42 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-048
- **Prereqs:** none

- **Files:** `src/Normalization/SqlTableExtractor.php` (cleanIdentifier :130-190,
  stripLeadingCte :82-110), `tests/Unit/Normalization/SqlTableExtractorTest.php`.
  land independently, values-only).
- **Shared resources:** none.
- **Acceptance:** unit table: `FROM [users],` → rejected-or-cleaned, never leaked `[users]` (reject
  regex extended to `[]"\`); CTE body containing `'a(b'` → prefix strip still correct (string-span
  skip or balance gate); null-ish degrade preserved otherwise; suites green.

### engine-S38 — IdentifierIds::strip regex hot path (compounds S09)

- **Queue rank:** 43 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-053
- **Prereqs:** none

- **Files:** `src/Util/IdentifierIds.php:44-53`, `tests/Unit/Util/IdentifierIdsTest.php`.
- **Shared resources:** none — stream VALUES must be identical (fingerprint stability); assert.
- **Acceptance:** property unit: old preg vs new str_ends_with/ctype walk equal on random +
  adversarial token lists (`N9N9`, digits-only, unicode); micro-bench disclosed; Apted suites green.

### engine-S39 — SerializedClassAllowList auto-trusts the entire PhpParser\ prefix

- **Queue rank:** 44 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-engine-058
- **Prereqs:** engine-S23b (AstCache/TokenCache real-blob tests re-run after tightening; the

- **Files:** `src/Persistence/SerializedClassAllowList.php:52-85`,
  `tests/Unit/Persistence/SerializedClassAllowListTest.php`.
  filter must not break legitimate decode).
- **Shared resources:** this list is consumed by both caches AND WorkerPool fork transport
  (shell-group audit memo notes it) — tightening affects all three; run Parsing + Parallel suites.
- **Acceptance:** unit: allowed set = `is_subclass_of(PhpParser\Node)` + Token (+ whatever real
  blobs need — enumerate, e.g. Attribute classes; document any widening explicitly); new test
  fails if any allowed class declares `__destruct|__wakeup|__unserialize`; end-to-end cached analyze
  (cold+warm) and a `--workers 2` run still pass.

### output-S07 — CliReporter cheapness: totals once, table rows batched

- **Queue rank:** 45 of 247
- **Severity mix:** low=2
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-output-054, php-dup-finder-output-060
- **Prereqs:** none

- Covers: 054, 060
- Files: `src/Reporting/CliReporter.php`, `tests/Unit/Reporting/CliReporterVerbosityTest.php`, `tests/Unit/Reporting/CliReporterHonestyTest.php`
- Size: MM (45 min)
- Prereqs: none — but keep ahead of S19 (same file, scrub pass).
- Conflicts: CliReporter also in S11 (threshold guards adjacent), S19, S27 (absence render).
- Acceptance: `--filter CliReporter` (both suites) — CLI render bytes for a synthetic Report identical before/after (capture-and-diff in test); new test asserts clusters==N table via one `rows()` call (reflection or output byte-equality against old shape); GATE.

### output-S15 — MutationTester: probe accounting, honest clean verdicts

- **Queue rank:** 46 of 247
- **Severity mix:** medium=1
- **Size:** HH (~45 min)
- **Covers:** php-dup-finder-output-031
- **Prereqs:** none

- Covers: 031
- Files: `src/Validation/MutationTester.php`, `tests/Unit/Validation/MutationTesterTest.php` (new — class currently has zero test coverage)
- Size: HH (45 min)
- Prereqs: none.
- Acceptance: `--filter MutationTesterTest` — cluster with N null-AST members yields reason string "X of Y probed (K unprobed: AST not loaded)" and NO clean verdict when K>0; all-unprobed ⇒ refusal verdict, not `[]`; GATE.

### shell-S14 — Fork-zone APTED parity: thread `--ted-weights` into PairScoreWorker

- **Queue rank:** 47 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-004
- **Prereqs:** none

**Files:** src/Parallel/PairScoreWorker.php, src/Pipeline/Stages/ClusterStage.php, tests/Unit/Parallel/PairScoreWorkerTedParityTest.php (new), tests/Unit/Clustering/TedSizeGateTest.php (ctor call check only)
**Shared resources:** ClusterStage.php file (chains before S30/S37); new scalar ctor param must honor Socketpair Law (string model id — fine); golden suite runs default weights → MUST stay byte-identical.
**Acceptance:**
- New parity test: small fixture corpus, non-default `--ted-weights` model, scorePairs forked (workers>1, ≥64 pairs forced via fixture or injected) vs `workers:1` → identical edge list; RED before the threading, GREEN after (mutation-proof in report).
- Probes: `bin/phpdup analyze <corpus> --ted-weights=<alt> --workers=1 --json /tmp/a.json` vs `--workers=4 --json /tmp/b.json` → `diff` identical.
- Golden suite byte-identical; phpstan/psalm clean.

### once-S25 — --ted-weights fork-path closure + Clusterer docblock truth

- **Queue rank:** 48 of 247
- **Severity mix:** high=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-once-028
- **Prereqs:** shell-S14 (PairScoreWorker wiring core)

**Files:** src/Clustering/Clusterer.php, src/Parallel/PairScoreWorker.php, tests/Unit/Clustering/ClustererTest.php
**Shared resources:** PairScoreWorker after shell chains.
**Acceptance:** serial-vs-fork byte-identity test with non-default weights (shell-S14 owns parity; this step adds the weights-specific case + fixes 'mirrored byte-for-byte' docblock at Clusterer:718 to cite the real mirror).


- **Phase-4 Outside merge:** shell's (engine) row — the Clusterer.php:723 serial scoring path gets the fork/serial parity test that shell-S14 ships for the worker side; add the weights-specific serial case here (this step already owns the Clusterer docblock truth fix).

### shell-S23 — `--diff-base`: argv-safe git invocation, per-root repo, honest failure

- **Queue rank:** 49 of 247
- **Severity mix:** high=1 low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-003, php-dup-finder-shell-040
- **Prereqs:** none

**Files:** src/Pipeline/Stages/ScanningStage.php, src/Cli/Command.php, tests/Unit/Pipeline/Stages/ScanningStageTest.php
**Shared resources:** Command.php :667-670 scanError-return site (S24/S34 chain behind this); git-subprocess posture report-only in CI (no fixture git repos needed in-repo — tests use tmp init'd repos; keep hermetic); exit-code note: git failure now aborts EARLY (was: full run then exit 2 — same exit code, different timing; CLI.md table unchanged).
**Acceptance:**
- Probe: `--diff-base='--output=/tmp/S23PWNED'` → NO file created (neither `/tmp/S23PWNED` nor `..HEAD` variant), exit 2 with clean message (`--` separator makes git refuse the ref).
- Unit: scanError set → stage aborts pipeline BEFORE extraction (assert no "scanning → N files → analyzing" continuation; early-exit posture); `2>&1` removed — git's stderr no longer parsed as filenames (feed a warning-producing ref: assert error surfaced via scanError, list unaffected); multi-root + diff-base → per-root `git -C` or documented refusal (lane choice, pinned by test).
- ScanningStageTest green; `--diff-base=HEAD~1` happy-path probe on a temp git repo unchanged.

### once-S34 — --diff-base honors named roots, repo-relative git, unicode, empty cohort

- **Queue rank:** 50 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-038
- **Prereqs:** shell-S23 (git -C + argv-safe core)

**Files:** src/Cli/Command.php, src/Scanning/FileScanner.php, tests/Unit/Scanning/FileScannerTest.php
**Shared resources:** FileScanner late chain; CLI.md diff-base rows.
**Acceptance:** git fixture in /tmp: analyze named-root subdir w/ --diff-base HEAD~1 → cohort = changed files under that root only (was FULL scan), `core.quotePath=true` unicode filename maps and is diffed, empty changed-cohort → exit 2 + message.

### shell-S32 — Register the six orphan db-aware symbol packs

- **Queue rank:** 51 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-037
- **Prereqs:** none

**Files:** src/Cli/ProjectProfileDetector.php, tests/Unit/Cli/ProjectProfileDetectorTest.php
**Shared resources:** profiles/db-aware-*.json files (read-only here); AGENTS.md packs list already claims parity (no edit — caliber-managed; report row if drift found); add-db-symbol-pack skill ritual (composer-package signal + KNOWN_PROFILES ordering) governs the fix.
**Acceptance:**
- Unit: each of db-aware-laravel/-codeigniter/-laminas/-yii/-myadmin/-myadmin-orm detected from its composer marker in a fixture; KNOWN_PROFILES count 23→29 with set-equality pin against `listAvailable()` scan of profiles/ (kills the class permanently); `--profile=auto` on a laravel-marker tmp dir → db-aware-laravel applied (probe exit 0 + profile-mention in debug or JSON).
- ProjectProfileDetectorTest green.

### output-S02 — shared Json::encode helper; kill false-into-string encode class

- **Queue rank:** 52 of 247
- **Severity mix:** high=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-output-012
- **Prereqs:** none

- Covers: 012
- Phase-5 r1 (I10/D4): `tests/Golden/` token stripped — this lane is verify-only (`goldens byte-identical (GATE; no UPDATE_SNAPSHOTS run)` in acceptance); any witnessed movement regens under the regen-window law, disclosed.
- Files: `src/Util/Json.php` (new), `src/Reporting/JsonReporter.php`, `src/Reporting/SarifReporter.php`, `src/Reporting/GitLabSastReporter.php`, `src/Reporting/TimeseriesReporter.php`, `tests/Unit/Util/JsonTest.php` (new)
- Size: HH (60 min)
- Prereqs: none.
- Conflicts: JsonReporter/Sarif/GitLab/Timeseries also in S03/S05/S20/S22/S38/S41 — schedule S02 early (mechanical).
- Acceptance: unit `--filter JsonTest` (invalid UTF-8 and unencodable resources: throws named exception, never returns false/empty); `grep -n "json_encode" src/Reporting/{Json,Sarif,GitLabSast,Timeseries}Reporter.php` shows only `Json::encode(`; Timeseries reporter with a hostile-utf8 name emits either a substituted record or a named error — not an empty line; goldens byte-identical (GATE; no UPDATE_SNAPSHOTS run). If Command maps the new exception to a `phpdup:` error line, that's a (xg: src/Cli/Command.php) row — otherwise generic exit 1 is acceptable for now.

### once-S52 — SARIF $schema URL that resolves

- **Queue rank:** 53 of 247
- **Severity mix:** medium=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-058
- **Prereqs:** output-S02

**Files:** src/Reporting/SarifReporter.php, tests/Unit/Reporting/SarifReporterTest.php
**Acceptance:** constant swapped to docs.oasis-open.org errata path; test asserts URL shape; fetchability verified once (web probe row in report; no network in test).

### output-S03 — central Version constant, five drifted literals gone

- **Queue rank:** 54 of 247
- **Severity mix:** medium=1
- **Size:** HH (~45 min)
- **Covers:** php-dup-finder-output-038
- **Prereqs:** output-S02  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 038
- Files: `src/Util/Version.php` (new), `src/Reporting/JsonReporter.php`, `src/Reporting/TimeseriesReporter.php`, `src/Reporting/SarifReporter.php`, `src/Reporting/GitLabSastReporter.php`, `src/Reporting/CheckstyleReporter.php`, `tests/Unit/Util/VersionTest.php` (new), `docs/JETBRAINS_PLUGIN.md` (version-cell truth if it cites 0.x)
- Size: HH (45 min)
- Prereqs: S02 (same reporter files, encode shape first).
- Conflicts: (xg: shell src/Cli/UpdateCommand.php:487 '0.0.0' fallback — Outside row) — the central constant should feed it; report as collected row.
- Acceptance: `grep -rn "'0\.1\.0'\|'0\.2\.0'\|\"phpdup-0" src/` → zero hits; VersionTest asserts all five payloads carry `Version::STRING` on one synthetic Report; goldens: `phpdup_version` is absent from golden files (verified) → byte-identical; GATE.

### output-S05 — TimeseriesReporter: packed-refs sha, repo-root derivation, UTC stamps

- **Queue rank:** 55 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-output-050
- **Prereqs:** output-S02  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 050
- Files: `src/Reporting/TimeseriesReporter.php`, `tests/Unit/Reporting/TimeseriesReporterTest.php`
- Size: MM (45 min)
- Prereqs: S02.
- Conflicts: (xg: engine UTC-in-artifacts law — Outside row; report-only rider here). Config::paths availability at this call site confirmed during implementation; constructor may need the roots passed → ReportStage wiring row (xg).
- Acceptance: `--filter TimeseriesReporterTest` — new tests against a fixture repo with `git pack-refs --all` resolve the sha; run from a non-root cwd still resolves via scan root; emitted `timestamp`/`commit_ts` fields end in `Z` or `+00:00`; GATE.


- **Phase-4 merge (law):** engine/determinism out-of-group row — 'timestamps inside golden/serial artifacts must be UTC' — this step ships the Timeseries half; the law is recorded in ## Program laws for every artifact-touching lane (once-S59 carries the GitLab-clock half).

### once-S38 — Non-UTF-8 end-to-end (no 255 in any JSON family member)

- **Queue rank:** 56 of 247
- **Severity mix:** high=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-once-042
- **Prereqs:** output-S02 (Util/Json core), output-S05

**Files:** src/Reporting/TimeseriesReporter.php, tests/Unit/Reporting/TimeseriesReporterTest.php, tests/Integration/CliSmokeTest.php
**Shared resources:** Timeseries w/ S101.
**Acceptance:** /tmp fixture w/ Latin-1 bytes in filename AND content: json/sarif/gitlab/timeseries runs exit 0/1-by-contract (never 255), emitted JSON parses strict; Timeseries no-blank-line-on-encode-false pin (or full failure honest).

### shell-S02 — Worker results dir + job-id hardening

- **Queue rank:** 57 of 247
- **Severity mix:** medium=2
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-shell-021, php-dup-finder-shell-022
- **Prereqs:** shell-S01 (same spawn/processJob regions; worker ctor shape just changed)

**Files:** src/Cli/ServeCommand.php, src/Server/AnalysisWorker.php, tests/Unit/Server/ServeCommandTest.php, tests/Unit/Server/ApplicationTest.php
**Shared resources:** /tmp surface (`phpdup-results-<pid>`, `phpdup-worker-<pid>.sock`); docs/SERVER.md results-path row (rider; restated in S09); 052(c) roll-up leg deferred to S39.
**Acceptance:**
- Results dir created 0700; pre-existing foreign-owned dir of the same name → refuse at startup with an error (exit 1 runtime posture, not silent adoption); `stat -c %a` = 700 on a live serve run's results dir.
- Unit: jobId not matching the queue's own `/^[0-9a-f]{16}$/` mint format → write refused + error ack (no `../../` path escape: assert the `RUN <evil-id>` line writes nothing outside resultDir); `file_put_contents` failure → ack not "OK" (worker reports failure).
- cleanupWorker sweeps the results dir (unit: after shutdown handler, dir gone).


- **Phase-4 note:** ENGINE-SEC out-of-group-1's phpdoc-worker socket-pair confirmation leg rides shell-S39(c) (bind race) + engine-S39 (allow-list).

### shell-S07 — Worker request line: bounded read

- **Queue rank:** 58 of 247
- **Severity mix:** low=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-shell-049
- **Prereqs:** shell-S02 (processJob socket-read region; jobId check lands there first)

**Files:** src/Server/AnalysisWorker.php, tests/Unit/Server/ApplicationTest.php
**Shared resources:** cap must align with Application::MAX_BODY_BYTES scale (same constant story; S08 changes ack timing on this file after).
**Acceptance:**
- Unit: feed a line of cap+1 bytes on a socketpair → worker replies error, does not buffer it whole, stays alive for the next valid job.
- `stream_get_line`/`fgets` maxlen visible in code; no regression of the 022 jobId validation.

### shell-S03 — SIGINT/SIGTERM on `phpdup serve`: reap worker, clean /tmp, exit 130

- **Queue rank:** 59 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-016
- **Prereqs:** shell-S02 (cleanupWorker/result-dir region shared; S02's sweep is what the signal path must now trigger)

**Files:** src/Cli/ServeCommand.php, src/Server/AnalysisWorker.php, bin/phpdup-worker, tests/Unit/Server/ServeCommandTest.php
**Shared resources:** ServeCommand accept loop :185-198 (S04 extends it next); worker self-exit needs a parent-liveness check (getmypid ppid poll) — no new wire.
**Acceptance:**
- Live probe: start `phpdup serve`, record pids, `kill -INT` the server → server exits 130, `pgrep -f phpdup-worker` shows the child gone within ~1s, socket + results dir removed (was: orphaned worker + litter).
- `pcntl_signal(SIGINT/SIGTERM)` installed; `pcntl_signal_dispatch()` runs on EVERY accept-loop iteration including the 30s-timeout `continue` path (assert by code inspection + a unit that the dispatch sits before `continue`).
- Worker self-exits when its parent dies (kill server hard with -9 → orphan worker terminates within one poll interval).
- If pcntl unavailable: graceful no-op + doc note (same posture as WorkerPool fallbacks).

### shell-S04 — Per-client read timeout: one stalled client must not freeze the daemon

- **Queue rank:** 60 of 247
- **Severity mix:** high=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-007
- **Prereqs:** shell-S03 (accept-loop region serialization)

**Files:** src/Cli/ServeCommand.php, tests/Unit/Server/ServeCommandTest.php
**Shared resources:** request-deadline constant (documented in S09's SERVER.md pass).
**Acceptance:**
- Probe: client A opens the socket, sends bytes but never completes the request; client B's `GET /healthz` returns 200 within the timeout window + margin (was: blocked until A closes).
- `stream_set_timeout` + `stream_get_meta_data()['timed_out']` checked in BOTH read loops (header loop and body loop); timed-out client gets a 408 (or clean close — pick one, pin it in a test) and the loop continues.
- Unit: a fake stream fixture that stalls → handleClient path returns within configured bound (hermetic, no wall-clock sleeps >2s so CI stays green).

### once-S43 — serve concurrency posture decided; /healthz immune to slow-loris

- **Queue rank:** 61 of 247
- **Severity mix:** high=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-once-047
- **Prereqs:** shell-S04

**Files:** src/Cli/ServeCommand.php, docs/SERVER.md
**Shared resources:** [OWNER: keep serial + documented backlog vs worker-per-conn — default keep-serial]; SERVER.md concurrency section w/ S97.
**Acceptance:** listen backlog set + documented; manual probe: stalling one client (netcat half-open) → GET /healthz still 200 within 1 s; documented known-serial limitation + sizing guidance row.

### shell-S05 — HTTP framing: anchored Content-Length, duplicate rejection, LF leniency, no chunked

- **Queue rank:** 62 of 247
- **Severity mix:** medium=1 low=1
- **Size:** MM (~50 min)
- **Covers:** php-dup-finder-shell-033, php-dup-finder-shell-047
- **Prereqs:** shell-S04 (same raw-request read/parse region :228-265)

**Files:** src/Cli/ServeCommand.php, tests/Unit/Server/ServeCommandTest.php
**Shared resources:** MAX_BODY_BYTES semantics in Application::jsonBody stays authority (cross-check only, no edit).
**Acceptance:**
- Unit: `X-Content-Length: 999999999` ahead of real `Content-Length: 12` → gate uses the real one (no 413 hijack); two headers with DIFFERENT Content-Length values → 400; `Content-Lengthings:` does not match the anchored regex.
- Probe: `printf 'GET /healthz HTTP/1.1\n\n' | (one-shot PTY nc)` → 200 immediately (bare-LF accepted, was hang-until-close); a request with `Transfer-Encoding: chunked` → 400 with explanatory body.
- extractHeaders duplicate policy (reject-on-conflict for Content-Length, documented last-wins for others) pinned by a test.

### shell-S15 — Preprocess streaming: generator task, per-file yield

- **Queue rank:** 63 of 247
- **Severity mix:** high=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-shell-008
- **Prereqs:** none

**Files:** src/Pipeline/Stages/PreprocessStage.php, src/Parallel/PreprocessWorker.php, tests/Unit/Pipeline/Stages/PreprocessStageTest.php, tests/Unit/Parallel/PreprocessWorkerIsolationTest.php
**Shared resources:** PreprocessStage.php + PreprocessWorker.php file locks; runStreaming contract (already proven with generator tasks by the pair-score path — mirror ClusterStage.php:362-371 shape); YIELD_EVERY=32 repaint semantics preserved.
**Acceptance:**
- Unit: chunk of k files, child task generator yields per file/sub-batch — first record observable before the chunk's last file is processed (arrival-order pin like PERF's early-arrival metric, expressed deterministically); PreprocessWorker `process()` keeps its array API for serial callers OR gains a generator twin (lane choice — both callers checked).
- Full Unit suite green; Golden byte-identical (records identical, only arrival order changes).
- Lane report: RSS/wall delta note (PERF measured 24.4% early-arrival with generator) — report-only, no CI assert.

### shell-S16 — `low_memory`: per-dir capture + both cache keys

- **Queue rank:** 64 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-027
- **Prereqs:** shell-S15 (both rewrites sit in the toolFor/process region :100-143)

**Files:** src/Parallel/PreprocessWorker.php, src/Pipeline/Stages/PreprocessStage.php, tests/Unit/Parallel/PreprocessWorkerIsolationTest.php, tests/Unit/Persistence/IndexStoreTest.php
**Shared resources:** IndexStore cache-key change (invalidates existing on-disk caches once — expected, documented in report; pairs with S18's format change story); ConfigLoader/Config per-dir admit stays (flag stays admitted).
**Acceptance:**
- Unit: per-dir `.phpdup.json` with `low_memory:true` on subdir B only → files in B fingerprint with compact bags, A files with 64-bit (assert via tool identity or fingerprint shape) — RED before the capture fix.
- Unit: corpus cached under `--low-memory`, re-run without → MISS (config-key includes lowMemory; same for toolFor sprintf key).
- No PARSER_VERSION bump if key-namespace route taken (precedent: fingerprint_id_mode comment) — state choice in report.

### shell-S17 — Per-dir override hot path: memoized effectiveFor / group-keyed tooling

- **Queue rank:** 65 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-035
- **Prereqs:** shell-S16 (same :100-124 key region settled first; Config.php chains: S22/S27 edit it later — this goes first among Config writers here)

**Files:** src/Cli/Config.php, src/Parallel/PreprocessWorker.php, tests/Unit/Cli/PerDirectoryConfigTest.php, tests/Unit/Parallel/PreprocessWorkerIsolationTest.php
**Shared resources:** Config.php file lock (S22/S27 follow here); memo must not break the 60-field withOverrides semantics (derived Configs are value-identical per dir); no behavior delta allowed.
**Acceptance:**
- Unit: same-directory files reuse one derived Config / one tooling key (counter via test seam: withOverrides-invocation count == distinct matched groups, not file count); outputs byte-identical to pre-change run (probe `--json` diff on a per-dir fixture corpus).
- Full Unit green; Golden byte-identical; lane report cites the ~2ms/file elimination (report-only perf note).

### engine-S30 — ShapeletSketch fallbackPopcount: wrong, undocumented ext-bcmath, duplicate of BloomFilter's table

- **Queue rank:** 66 of 247
- **Severity mix:** medium=1
- **Size:** MM (~75 min)
- **Covers:** php-dup-finder-engine-038
- **Prereqs:** none

- **Files:** `src/Fingerprint/ShapeletSketch.php:76-113`, `src/Index/BloomFilter.php:104-136`
  (popcount hoisted to a shared `src/Util` helper — new small file + both classes call it),
  `tests/Unit/Fingerprint/ShapeletSketchTest.php`, `tests/Unit/Index/BloomFilterTest.php`.
- **Shared resources:** none (sketch VALUES on gmp hosts unchanged; non-gmp hosts get corrected —
  no composer.json edit: bcmath branch deleted, not declared).
- **Acceptance:** unit: table popcount == gmp path on edge vectors (63-bit, 64-bit high-bit set,
  zero); `extension_loaded('gmp')` hoisted (static lookup); docblock SWAR lie replaced; full suites
  green on this host (gmp present) and a forced-fallback unit (call the table path directly).

### engine-S24 — Cache key honesty (parser version + PHP major) and sha1_file memoization

- **Queue rank:** 67 of 247
- **Severity mix:** medium=1 low=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-engine-016, php-dup-finder-engine-050
- **Prereqs:** engine-S23b (file chain).

- **Files:** `src/Parsing/AstCache.php` (VERSION :24 / keyFor :89-99: derive marker from
  `Composer\InstalledVersions::getPrettyVersion('nikic/php-parser')` + `PHP_VERSION_ID` major;
  path→sha1 memo), `src/Parsing/TokenCache.php` (same memo/derivation),
  `tests/Unit/Parsing/AstCacheTest.php`, `tests/Unit/Parsing/TokenCacheTest.php`,
  `tests/Unit/Extraction/BlockAstLoaderTest.php` (consumer).
- **Shared resources:** key change invalidates all existing caches (acceptable — correctness goal);
  AGENTS.md/CLAUDE.md prose repeats the false promise → caliber-managed files, do NOT edit: emit a
  report-only closeout row.
- **Acceptance:** unit: keyFor output contains `nikic/php-parser` pretty version + PHP major; second
  keyFor() on same path does not re-hash (memo — assert via file mtime touch after first call: blob
  still matches / counter seam); cache written by a "5.0"-labeled key is not read by a "5.9"-labeled
  run (two-marker identity unit); suites green.

### once-S10 — PHP floor + extension truth in composer/README/CI

- **Queue rank:** 68 of 247
- **Severity mix:** high=2
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-012, php-dup-finder-once-013
- **Prereqs:** engine-S30 (bcmath branch deletion outcome), output-S01 (psalm pin truth)

**Files:** composer.json, README.md, .github/workflows/ci.yml
**Shared resources:** composer.json serial S10→S11; ci.yml serial S10→S11→S56→S93→S13; AGENTS/CLAUDE stale 8.1-floor prose → REPORT-ONLY rows (S80 packet); psalm 6.20.9 docs truth also touches docs/RELEASE.md row (S05 window — coordinate append).
**Acceptance:** `composer validate` passes; ext matrix: gmp demoted to suggest + guarded fallback verified (php -d disable_functions-free run WITHOUT gmp completes notify fixture — /tmp probe), bcmath declared-or-dropped per engine-S30, mbstring declared, react/promise + psr/log declared explicitly, curl help row removed (never used); README badge `php-8.3` matches `^8.3`; ci installs gmp+mbstring; docs grep: no 'psalm 6.0.0' or 'PHP 8.1' version claims left outside historical notes.


- **Phase-4 Outside merge:** output's (once, DOCSYNC) row — composer.json `^8.3` vs CLAUDE.md/AGENTS.md 'PHP 8.1 floor' — this step owns the manifest truth; doc prose rides the S80 packet.

### once-S40 — Windows path portability at the boundary

- **Queue rank:** 69 of 247
- **Severity mix:** high=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-044
- **Prereqs:** shell-S20, shell-S21, output-S13

**Files:** src/Scanning/FileScanner.php, src/Util/PathCanonical.php, src/Reporting/FindingId.php, src/Reporting/BaselineStore.php, tests/Unit/Scanning/FileScannerTest.php
**Shared resources:** FindingId w/ engine rulings (S31 context); ids/baselines cross-run portability note to S50.
**Acceptance:** unit-level: relpath maps `\` and drive-prefixed forms → canonical forward-slash ids; underRoots comparison separator+case normalized; fabricated CWD-separator probe on /tmp; a stated MANUAL Windows row (owner box) since no runner — behaviour proven via separator-injection units, not claims.

### output-S25 — AntiUnifier seed trials: drop wasted clone, carry best trial forward

- **Queue rank:** 70 of 247
- **Severity mix:** high=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-014
- **Prereqs:** none

- Covers: 014
- Files: `src/Refactor/AntiUnifier.php` (pickBestSeed :165-190, trialUnifyHoleCount :204-217, unify :107-127), `tests/Unit/Refactor/AntiUnifierTest.php`
- Size: HH (90 min)
- Prereqs: none — start of the AntiUnifier serial chain (S25→S26→S27→S28→S30; S49 later consumes).
- Acceptance: `--filter AntiUnifierTest` — chosen seed + hole set identical to pre-change on all fixture clusters (pin); trial path no longer calls `Normalizer::deepClone` (`grep` structure check + spy); unify() walks ≤1 full extra pass; goldens byte-identical; GATE.

### shell-S26 — Per-dir discovery: honor excludes, degrade malformed configs; config-file size cap + throwing JSON

- **Queue rank:** 71 of 247
- **Severity mix:** high=1 medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-shell-010, php-dup-finder-shell-029
- **Prereqs:** none

**Files:** src/Cli/ConfigLoader.php, tests/Unit/Cli/PerDirectoryConfigTest.php, tests/Unit/Cli/ConfigLoaderTest.php
**Shared resources:** .phpdup.json discovery contract (malformed-file THROW → skip-note is a user-visible behavior change — disclose; a subdir file that USED to exit 2 now warns and continues — S31's docs and S24's exit-table pass must reflect); FileScanner exclude-prune pattern reuse (:86-90); 5.8× discovery-measure improvement report-only.
**Acceptance:**
- Unit: excluded dir (default `**/vendor/**`) containing a stale-key or broken `.phpdup.json` → never read (no throw, scan proceeds); included dir with broken JSON → skip note on STDERR + run continues (was: throw → exit 2); discovery skips unreadable dirs.
- Unit: 2MB root phpdup.json → refused with size-cap error, exit 2; deep-nesting JSON → error message names the real JsonException (JSON_THROW_ON_ERROR at both :25 and :435 legs).
- Perf probe (report-only): discovery wall on a 25k-entry tree ≤ FileScanner-class cost.

### shell-S22 — Per-dir override admit-list: drop path/ref-bearing keys

- **Queue rank:** 72 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-026
- **Prereqs:** shell-S26 (ConfigLoader same-file chain: discovery robustness lands first; also S10's workers-bound leg touched :685-690 — no region overlap with this step's :530-562, ordering only), shell-S17 (Config::withOverrides settled there before keys are removed from it)

**Files:** src/Cli/ConfigLoader.php, src/Cli/Config.php, tests/Unit/Cli/PerDirOverrideBeltTest.php, tests/Unit/Cli/PerDirectoryConfigTest.php, docs/config-schema.json (per-dir section note)
**Shared resources:** belt law (shapeOverrides-emitted == withOverrides-consumed: remove from BOTH sides or the belt test flips red); docs/config-schema.json per-dir notes (S31 re-derives the root mirror test after); .phpdup.json discovery-path contract.
**Acceptance:**
- Unit: per-dir `.phpdup.json` carrying `diff_base`/`baseline`/`baseline_out`/`debug_log` → RuntimeException "root-only" family message, exit 2 (same posture as fingerprint_id_mode refusals :443-499); keys removed from the derived-Config copy in Config::withOverrides; PerDirOverrideBeltTest updated to pin the new emitted-set.
- Probe: planted subdir config with `debug_log` → `bin/phpdup analyze . → exit 2` naming the file and key.


- **Phase-4 Outside merge:** ENGINE-SEC out-of-group 2 (debug_log/baseline/baseline_out/diff_base admitted per-dir) — this step's root-only refusal IS the fix; the confinement-check half lands via shell-S23 (diff_base git injection) + output-S52 (write confinement) + once-S29.

### shell-S24 — Runtime option validation: invalid flags exit 2, numeric garbage rejected

- **Queue rank:** 73 of 247
- **Severity mix:** medium=2
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-shell-013, php-dup-finder-shell-014
- **Prereqs:** shell-S23 (Command.php execute-tail region first writer), shell-S22 (ConfigLoader :134/:209 coerce region after admit-list)

**Files:** src/Cli/Command.php, src/Cli/ConfigLoader.php, tests/Unit/Cli/NumericFlagCoercionTest.php, tests/Unit/Cli/CliDefaultShadowingTest.php, tests/Integration/CliSmokeTest.php, docs/CLI.md (exit-code table section)
**Shared resources:** docs/CLI.md "Exit codes" + flags tables (first writer; S28/S25 follow); exit-contract: paths that printed an error line + exited 0 now exit 2 — pure contract tightening toward the documented rule (no doc-table CHANGE needed, but CLI.md flag rows' wording "errors are fatal" rider where flagged); Ranker.php:42 re-throw (Outside/output) loses its crash surface via this step — note for merge.
**Acceptance:**
- Probes (exact, against `php bin/phpdup`): `--stage=blah` → exit 2 + error line (was 0 + full run); `--pager=banana` → 2; `--theme=nope` → 2; `--min-safety=2.0` → 2 BEFORE the pipeline runs (was: crash-1 after; assert short run time); `--limit=abc --min-safety=hot --max-memory=banana` → 2 (was 0); `--optional-blocks-containment=hot` → 2.
- NumericFlagCoercionTest extended to the four flags (they join NUMERIC_KEYS or the coerce helper); valid values still pass; `--workers banana` unchanged.
- Full Unit green; Golden byte-identical.

### once-S49 — --limit docs + closure (0 = unlimited)

- **Queue rank:** 74 of 247
- **Severity mix:** medium=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-054
- **Prereqs:** shell-S24

**Files:** docs/CLI.md, tests/Unit/Cli/NumericFlagCoercionTest.php
**Shared resources:** CLI.md window.
**Acceptance:** validation fence (shell) confirmed; `--limit 0` documented 'show all', `--limit 1` shows exactly 1 cluster in CLI + JSON output.

### once-S20 — Exit-2 admission closure for all four runtime options

- **Queue rank:** 75 of 247
- **Severity mix:** high=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-once-023
- **Prereqs:** shell-S24 (core validator)

**Files:** src/Cli/Command.php, src/Reporting/Ranker.php, tests/Unit/Cli/NumericFlagCoercionTest.php
**Shared resources:** Command.php leg; NumericFlagCoercionTest shared w/ S49.
**Acceptance:** probe each: `--stage bogus`, `--pager 7`, `--profile nope`, `--theme nope`, `--min-safety 9`, `--limit abc` → exit 2 single clean error (was exit 0/1/trace); --min-safety validated at Config/Ranker boundary before use (no RuntimeException leak).

### once-S19 — Default command: `phpdup src/` works; broken-form error text fixed

- **Queue rank:** 76 of 247
- **Severity mix:** high=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-once-022
- **Prereqs:** shell-S24

**Files:** bin/phpdup, docs/CLI.md, README.md, tests/Unit/Cli/CliDefaultShadowingTest.php
**Shared resources:** bin/phpdup shared w/ S30 (version stamp) — serialize S19 → S30; README quickstart row w/ S12/S95.
**Acceptance:** `bin/phpdup src --json /tmp/o.json` runs analyze (setDefaultCommand enable=true, no-args falls to help not error); missing-path hint suggests valid form (verify vs shell-S24 wording); subcommand table lists init/serve/update.

### shell-S27 — `apply` key: validate, then consume or drop (decide in-lane, record)

- **Queue rank:** 77 of 247
- **Severity mix:** medium=1 low=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-015, php-dup-finder-shell-053
- **Prereqs:** shell-S22 (shapeOverrides final admit list), shell-S24 (Command parseRuntimeOptions validation idiom lands first)

**Files:** src/Cli/ConfigLoader.php, src/Cli/Config.php, src/Cli/Command.php, tests/Unit/Cli/ConfigLoaderTest.php, tests/Unit/Cli/PerDirOverrideBeltTest.php (if admitted-set shifts), docs/config-schema.json (if key kept — then add its property)
**Shared resources:** validate() bool-legs family (:734-790); Command.php:220 catch-class widening (`\TypeError` → exit 2 — a class-level rule: any future ctor TypeError is a usage error; check no legitimate runtime TypeErrors were expected to crash raw — grep catches); key kept-vs-dropped is a §6-style decision row — implementer picks the validated+consumed posture (validate bool leg + `flag OR config` consumption in parseRuntimeOptions) since dropping a shipped key is breaking; S31 pins schema afterwards.
**Acceptance:**
- Probes: config file `"apply":"yes"` → `--validate-config` exit 2 (was 0 "config OK"); same file on a run → exit 2 with clean message (was: raw TypeError exit 255). `"apply":true` in config alone → apply-mode active (if consumed posture) — pin the chosen semantics in a unit; CLI flag still overrides.
- Command.php:220-style catch converts a deliberate ctor TypeError fixture to exit 2 (unit).
- Full Unit green; psalm baseline unchanged.

### shell-S28 — Wire `--dry-run`; fill grouped-help gaps

- **Queue rank:** 78 of 247
- **Severity mix:** low=2
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-shell-039, php-dup-finder-shell-055
- **Prereqs:** shell-S24, shell-S27 (same :529-530 apply/dry-run neighborhood; validation posture final)

**Files:** src/Cli/Command.php, docs/CLI.md, tests/Unit/Cli/CliDefaultShadowingTest.php
**Shared resources:** docs/CLI.md (adds the missing `--dry-run` flag row — table writer, batch with remaining riders from S24/S25); help-builder :167-173 list vs configure() parity (S55); apply plumbing already exists one layer below (RefactorPatchReporter dryRun arg).
**Acceptance:**
- Probe: `--help` grouped "Output / reports" block contains `--pager` and `--debug-log`; `bin/phpdup analyze --dry-run <path>` accepted; with apply posture active, dry-run=true keeps preview (behavior pin unit) and `--dry-run=false` changes it (unit exercising the read).
- docs/CLI.md has a `--dry-run` row with default + semantics; no exit-code change.


- **Phase-4 Outside merge:** ENGINE-FUZZ-X02 — reconcile the lying `--no-cache` option text at Command.php:127 with the engine-S26 behavior that now ships (worker path truly disables writes).

### once-S06 — Stop the TUI printing the full CLI report on the alt screen

- **Queue rank:** 79 of 247
- **Severity mix:** critical=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-006
- **Prereqs:** shell-S27, shell-S28  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/Command.php, src/Pipeline/Stages/ReportStage.php
**Shared resources:** Command.php lock ledger; pager-suppression flag = shared seam with S76; ReportStage touched later by S72 — coordinate order S06→S36→S72.
**Acceptance:** `--tui` run under `script -qec`: raw stdout (od-dumped PTY capture) contains no CLI report body / pager spawn (assert via `ps` census for pager absence); file reporters still write; exit 0.

### once-S07 — TUI surfaces pipeline errors and exits non-zero

- **Queue rank:** 80 of 247
- **Severity mix:** critical=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-once-007
- **Prereqs:** once-S06

**Files:** src/Tui/TuiRunner.php, src/Tui/PhpdupModel.php, tests/Unit/Tui/TuiRunnerTest.php, tests/Unit/Tui/PhpdupModelTest.php
**Shared resources:** PhpdupModel chain (S07→S61→S62→S63→S72→S96); toast-queue consumer seam; error exit-class row to S95.
**Acceptance:** injected throw at ClusterStage in unit → run() returns 1 (was 0); error panel/reason visible (model state assertion, not pixels); budget-exceeded shows remedy line consistent with CLI's.

### once-S22 — --tui without TTY refuses; bogus theme no render

- **Queue rank:** 81 of 247
- **Severity mix:** high=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-once-025
- **Prereqs:** once-S06, shell-S24

**Files:** src/Cli/Command.php, src/Tui/TuiRunner.php
**Shared resources:** TuiRunner shared w/ S07 (serialize S07→S22→S75); Command leg.
**Acceptance:** `bin/phpdup --tui ... | cat` → exit 2 early, zero alt-screen bytes in pipe (`od` proof), hint suggests default CLI; --theme bogus exit path (shell-S24) verified not reaching render.

### once-S15 — One canonical --fail-on-impact semantic (gate honesty)

- **Queue rank:** 82 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-018
- **Prereqs:** shell-S24

**Files:** .github/actions/phpdup/action.yml, src/Cli/Command.php, docs/CI.md, docs/CLI.md
**Shared resources:** action.yml shared with S14; Command.php leg for gate; docs rows to S18/S95 window.
**Acceptance:** single formula [OWNER: per-cluster max impact '>=' — recorded for Phase-5]: CLI and action.yml agree, gate name in outputs, jq missing → explicit fail (exit 1 runtime posture, not `&&`-chain 0); both docs say the same; probe: action step logic extracted to shell-less unit check with fixture JSON both sides.

### once-S50 — Baseline: formula docs true, atomic writes, empty-file advice

- **Queue rank:** 83 of 247
- **Severity mix:** medium=2
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-055, php-dup-finder-once-056
- **Prereqs:** output-S13, shell-S28

**Files:** src/Reporting/BaselineStore.php, docs/CLI.md, tests/Unit/Reporting/BaselineStoreTest.php
**Shared resources:** BaselineStore w/ S40 portability note; CLI.md example line.
**Acceptance:** doc formula == sha256(underRoots path|structuralHash) (assert by recomputing one id in unit); `--baseline-file` empty or `[]` → actionable advice exit 2 (not 'not valid JSON' exit 1); write = tmp+rename+LOCK (concurrency unit two-writers no truncation); CLI.md baseline example never `cp` over user file.

### shell-S25 — `--profile` failures fatal + auto-tune probe honors profile excludes

- **Queue rank:** 84 of 247
- **Severity mix:** high=1 low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-012, php-dup-finder-shell-043
- **Prereqs:** shell-S24 (Command.php execute() region chain; shared failure-marker idiom from parseCliOverrides reused)

**Files:** src/Cli/Command.php, src/Cli/AutoTuner.php, tests/Unit/Cli/AutoTunerTest.php, tests/Unit/Cli/ConfigLoaderTest.php (profile legs)
**Shared resources:** Command.php; call-order change :209-219 (profile-before-tune) interacts with per-dir discovery? no; docs/CLI.md `--profile`/`--auto-tune` rows rider (report row; S28 batch-writes CLI.md).
**Acceptance:**
- Probes: `--profile=bogus` → exit 2 (was: error line + default run + exit 0); unreadable profile file path → exit 2; `--profile=auto` unaffected.
- Unit: fixture with profile-excluded dir (laravel-style `vendor/` clone) sized to straddle the medium/large band → resolved band computed WITHOUT the excluded trees (assert exact_only flips accordingly); probe stays counting-only (stat budget — no third full walk: filesize only for candidates it must read, or reuse discovery counts; lane note).
- AutoTunerTest + Unit green.


- **Phase-4 Outside merge:** ENGINE-PDDETECT out-of-group 2 — AutoTuner tiny-corpus tier (:71) relaxes max_df to 0.5 which stays dead for N ≤ 3: floor the tier's candidate generation the same way engine-S01 floors candidatesFor (max(2, …)), same file, one lane.

### once-S16 — Profile registry: disk == registry == docs, bogus --profile exits 2

- **Queue rank:** 85 of 247
- **Severity mix:** high=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-019
- **Prereqs:** shell-S32 (register 6 orphans), shell-S25 (--profile fatal)

**Files:** src/Cli/ProjectProfileDetector.php, profiles/, src/Cli/Command.php, docs/CLI.md, tests/Unit/Cli/ProjectProfileDetectorTest.php
**Shared resources:** profiles/ dir shared S100 (db-pack hygiene) — serialize S16 → S100; help list rows into S18's CLI.md window; ghost myadmin adjudication row [DECISION: add pack or drop KNOWN entry].
**Acceptance:** generated-from-disk: test asserts set-equality glob(profiles/*.json names ∪ db-aware-*) vs KNOWN_PROFILES ∪ help output ∪ docs/CLI.md list; `--profile bogus` exit 2 (closure re-check of shell-S25); no UNPROFILED success path.

### once-S100 — db-aware pack hygiene (op disagreements + dup key)

- **Queue rank:** 86 of 247
- **Severity mix:** low=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-once-125
- **Prereqs:** engine-S06b (receiver evidence), once-S16 (registry=disk)

**Files:** profiles/db-aware-laravel.json, profiles/db-aware-illuminate.json, profiles/db-aware-thinkorm.json, src/Normalization/DbOpRegistry.php, tests/Unit/Normalization/DbOpRegistryTest.php
**Shared resources:** profiles/ shared w/ S16 (order S16→S100); BlockAstLoader matchesKind risk = AGENTS 4th-mirror row → REPORT-ONLY rider.
**Acceptance:** delete-ish op classification identical in both packs (regression unit feeds same fixture, asserts same db-op tags); dup JSON key fixed; test asserts unique-key parse (json_decode err flag via JSON_THROW_ON_ERROR + manual scan); --db-aware loads ALL packs census unchanged + shared keys conflict-free.

### once-S18 — docs/CLI.md: every registered flag, drop the false 'full reference'

- **Queue rank:** 87 of 247
- **Severity mix:** high=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-021
- **Prereqs:** shell-S28

**Files:** docs/CLI.md, tests/Unit/Cli/DocFlagParityTest.php
**Shared resources:** CLI.md once-chain (S18 first once writer after shell; later legs S24/S26/S27/S29/S34/S37/S49/S50/S83).
**Acceptance:** new parity test reflects `Command::configure()` (+ ServeCommand) vs docs/CLI.md flag list — 15 named flags + serve flags land; [OWNER: hand rows + parity test (chosen default) vs generated doc]; claim sentence honest.

### once-S26 — Auto-tuner reads effective config, never overrides user values

- **Queue rank:** 88 of 247
- **Severity mix:** high=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-030
- **Prereqs:** shell-S25 (profile excludes honored)

**Files:** src/Cli/AutoTuner.php, src/Cli/Command.php, tests/Unit/Cli/AutoTunerTest.php
**Shared resources:** Command.php leg; help text precedence row (S18/S95 window).
**Acceptance:** unit: explicit min_block_size stays; tuner sees applied excludes; narration no longer '8→8' no-op on a tuned tree; --auto-tune help states precedence honestly.

### engine-S07 — TrinityCollapser fabricates UPSERT for never-saved mutates (high)

- **Queue rank:** 89 of 247
- **Severity mix:** high=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-engine-005
- **Prereqs:** none

- **Files:** `src/Normalization/TrinityCollapser.php` (:150-194 + isMutateOf side-effect clause),
  `tests/Unit/Normalization/TrinityCollapserTest.php`.
- **Shared resources:** none (trinityCollapse defaults false → goldens unmoved).
- **Acceptance:** end-of-body (no-save) run does NOT collapse (`$sawSave` gate; unit for the
  read→mutates→EOF stream); dead-store fn vs saved fn no longer 1.00 EXACT end-to-end with
  `--trinity-collapse` (CLI probe: 0 clusters vs previous 1); mutate-assignment RHS with side-effect
  not silently folded into synthetic (PDDETECT secondary — distinct token or refuse); stale comments
  :188-189 corrected; `--filter TrinityCollapser` green.

### engine-S22 — PairFeatures recomputes all per-block features on every pair

- **Queue rank:** 90 of 247
- **Severity mix:** medium=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-engine-033
- **Prereqs:** none

- **Files:** `src/Ml/PairFeatures.php`, `tests/Unit/Ml/PairFeaturesTest.php`.
  Block to avoid the S18/S12 Block.php contention — report the Block-field alternative as a row).
- **Shared resources:** depends semantically on IR shapes (S29) and Jaccard — value-equal refactors;
  micro-bench note disclosed.
- **Acceptance:** unit: for pairs sharing block X, the lifted-IR/summarizer call count for X drops
  to one (seam counter); feature vectors byte-identical to pre-change for a fixture set (golden-
  style pin inside PairFeaturesTest); `--filter PairFeatures` + Fuzz green; `MicroFixtureAuditTest`
  green (ML feature consumers unchanged).

### once-S28 — per-dir exclude/exclude_reset: implement subtree skip or drop the keys

- **Queue rank:** 91 of 247
- **Severity mix:** high=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-once-032
- **Prereqs:** shell-S20, shell-S21, shell-S22, shell-S26  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Pipeline/Stages/ScanningStage.php, src/Scanning/FileScanner.php, src/Cli/ConfigLoader.php, docs/CLI.md, tests/Unit/Cli/PerDirectoryConfigTest.php
**Shared resources:** [OWNER: implement-vs-drop]; CLI.md per-dir section; belt test PerDirOverrideBeltTest update either way.
**Acceptance:** implement path: fixture tree w/ nested .phpdup.json `exclude` → nested dir skipped in scan census, `exclude_reset:true` re-roots; OR drop path: keys refuse with pointer to CLI (exit 2) + docs/schema rows removed; in BOTH cases no silent acceptance remains.

### once-S85 — bench audit/comparative honesty + commit raw results

- **Queue rank:** 92 of 247
- **Severity mix:** medium=2
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-once-106, php-dup-finder-once-107
- **Prereqs:** once-S09

**Files:** bench/score-audit.php, bench/comparative.php, bench/feature-matrix.md, bench/results (new committed dir), README.md, docs/algorithms/clustering.md (numbers cited there)
**Shared resources:** README benchmark claims (S12/S32 earlier rows stay); feature-matrix w/ S68 citations; corpus GT changes must precede (S86 after S85, or coordinate — results are scored on S86-pinned corpora? chose: S85 commits results from CURRENT corpus state + 'provenance' note; S86 re-pins corpus and RE-renders results — two commit events disclosed).
**Acceptance:** recall splits ranged-vs-file-only (5/5 rangeless no longer count), verified:false tokens excluded from denominators, precision renamed honestly; comparative: no phantom matrix numbers (all recomputed from committed raw JSONs), --max-df fairness note (0.5=50× prod disclosed), timeouts shown as timeout not 0.00, 'Clusters' column unit-typed.

### shell-S08 — Async dispatch protocol: ack-at-accept, no post-work blocking; sync-mode cache posture

- **Queue rank:** 93 of 247
- **Severity mix:** medium=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-shell-032
- **Prereqs:** shell-S01, shell-S02, shell-S07 (all prior AnalysisWorker/Application regions stable; protocol change builds on the validated pipeline)

**Files:** src/Server/AnalysisWorker.php, src/Server/Application.php, src/Cli/ServeCommand.php, tests/Unit/Server/ApplicationTest.php, tests/Unit/Cli/ServeCommandTest.php
**Shared resources:** the one-line worker protocol changes shape (`OK <jobId>` moves from post-work to pre-work; completion signalled by result file appearing) — docs/SERVER.md route-behavior rows land in S09; phpdup-worker/bin surface re-checked by S35; Socketpair Law respected (scalars only).
**Acceptance:**
- Probe: submit an async job that takes >5s (fixture corpus) → queue reaches `completed` + result retrievable (was: `failed`/dispatch-failure split-brain while the result existed).
- Unit: worker replies accept-ack before running; server never marks FAILED on a slow-but-successful job; a worker crash mid-job → job lands FAILED via result-file absence with a deadline (or explicit `ERR` frame — lane choice, pinned).
- Sync `/analyze`: workers auto-nproc fork per request — clamp small payloads to serial (reuse S10's cap decision if it lands first; otherwise serial-by-default with a server flag). `useCache:false` becomes a server option (`--serve-cache` style) defaulting to current false; doc row in S09.
- ApplicationTest + ServeCommandTest green.

### once-S02 — Async job lifecycle actually completes (or fails honestly)

- **Queue rank:** 94 of 247
- **Severity mix:** critical=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-002
- **Prereqs:** shell-S06 (bounded queue), shell-S08 (ack-at-accept protocol)

**Files:** src/Server/Application.php, src/Server/JobQueue.php, tests/Unit/Server/JobQueueTest.php, tests/Unit/Server/ApplicationTest.php
**Shared resources:** JobQueue.php lock w/ shell chain; MAX_JOCS eviction policy touches shell-S06 semantics; false-500 posture is an exit-class row for S95 docs.
**Acceptance:** submitted fixture job transitions pending→running→done observed via GET /jobs/<id> (no 5 s fixed-fail); queue at cap evicts oldest with visible note, request never returns false-500 while alive; worker death reported via exit-status ack (not timeout lie); unit drives a fake worker both ways.

### shell-S09 — Serve route contract: docs and docblocks match the 7 real routes

- **Queue rank:** 95 of 247
- **Severity mix:** low=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-shell-046
- **Prereqs:** shell-S01, shell-S02, shell-S03, shell-S04, shell-S05, shell-S08  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/ServeCommand.php, src/Server/Application.php, docs/SERVER.md
**Shared resources:** docs/SERVER.md single writer; route POST/GET table is the contract reviewers cite for S01/S05/S08 acceptance.
**Acceptance:**
- `docs/SERVER.md` route table lists exactly the implemented allow-list (all 7, or a lane decision to drop `/api/*` — if dropped, code deletion too); the phantom `POST /jobs/cleanup` sentence at :108 is gone (or the route exists — no third option); docblock at ServeCommand.php:21-27/45-53 + Application.php:50-52 byte-consistent with the table.
- `grep -c '/api/jobs' docs/SERVER.md` > 0 iff routes kept.
- **Phase-5 r1 (I3/D3a):** desired `.claude/rules/server-job-queue.md` "allow-list" text → rider row in the once-S80 packet; lane edits no caliber files.

### once-S03 — buildSummary reads the right level; /analyze stops faking exactOnly

- **Queue rank:** 96 of 247
- **Severity mix:** critical=1 medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-003, php-dup-finder-once-065
- **Prereqs:** once-S02, shell-S09

**Files:** src/Server/Application.php, src/Server/AnalysisWorker.php, tests/Unit/Server/ApplicationTest.php
**Shared resources:** docs/SERVER.md counters rows (S17 window); shared counter helper co-owned with S37 summary work (keep helper additive).
**Acceptance:** sync job on notify fixture → summary block shows non-zero totals read from `$decoded['summary']`; /analyze + worker no longer force exactOnly (payload carries holes/signature fields asserted by key-existence tests); SERVER.md example output matches real bytes.

### once-S57 — Quickstart serve example matches the loopback guard

- **Queue rank:** 97 of 247
- **Severity mix:** medium=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-once-063
- **Prereqs:** shell-S09

**Files:** docs/SERVER.md, README.md
**Shared resources:** SERVER.md chain.
**Acceptance:** quickstart shows default bind; explicit non-loopback exposure documented AS the guarded case w/ the token/--serve-root consequence (not the surprise first-run error); probe: quickstart lines run as-written succeed.

### once-S17 — serve docs: real topology (routes, 413 body cap, playground truth)

- **Queue rank:** 98 of 247
- **Severity mix:** high=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-once-020
- **Prereqs:** shell-S09

**Files:** docs/SERVER.md, docs/PLAYGROUND.md, src/Server/Application.php (docblocks only)
**Shared resources:** SERVER.md append chain slot (after shell base, before S41/S42); 'synchronous demo' docblock lies → 'future' wording.
**Acceptance:** every implemented route documented (/jobs, /jobs/{id}, /jobs/cleanup→removed or added, /api/jobs×3, score-batch pointer to S53) and every documented route returns non-404 (loopback probe census); MAX_BODY 16 MiB + 413 row present; playground paste story reads as aspiration.

### shell-S35 — PHAR surface: InitCommand in stub + worker packaging decision

- **Queue rank:** 99 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-045
- **Prereqs:** shell-S09 (SERVER.md final route/limitation rows — phar note lands beside them), shell-S03 (worker-spawn resolve path :354-357 stable after signal work)

**Files:** build-phar.php, src/Cli/ServeCommand.php, docs/SERVER.md, tests/Unit/Cli/ServeCommandTest.php
**Shared resources:** bin/ + build-phar.php PHAR surface (only step editing build-phar.php); phar build requires `php -d phar.readonly=0` (CI has a job — keep the step CI-green); S36 (self-update) hot-swaps the same artifact — no file overlap, posture awareness only.
**Acceptance:**
- `php -d phar.readonly=0 build-phar.php` → exit 0; `php phpdup.phar list` shows `init`; worker shipped (`php phpdup.phar serve` spawns async leg — or documented sync-only with a serve warning line; lane decision recorded in report + SERVER.md row matches).
- Probe whichever posture: serve-from-phar smoke: POST /analyze returns same JSON as checkout-run (fixture tmp dir — phar confinement: note serve-root arg honored in S01's protocol).

### once-S42 — Serve request trust: Host/Origin checks, CSRF text/plain refused

- **Queue rank:** 100 of 247
- **Severity mix:** high=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-046
- **Prereqs:** once-S02, shell-S05, shell-S08  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Server/Application.php, src/Cli/ServeCommand.php, docs/SERVER.md
**Shared resources:** SERVER.md security section grows (S71 owns final assembly); token posture coupling S71 [OWNER shared with S71].
**Acceptance:** POST with `Origin: http://evil.test` → 403; Host header not loopback:port → 400; `text/plain` POST → refused pre-body; rebinding probe (Host: attacker-mapped→127.0.0.1) refused by Host allow-list — units driving Application directly.

### shell-S10 — WorkerPool child lifecycle: sentinel verification, exit-status inspection, bounded fork set

- **Queue rank:** 101 of 247
- **Severity mix:** high=1 medium=2
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-shell-005, php-dup-finder-shell-028, php-dup-finder-shell-038
- **Prereqs:** none

**Files:** src/Parallel/WorkerPool.php, src/Cli/ConfigLoader.php, tests/Unit/Parallel/WorkerPoolTest.php, tests/Unit/Parallel/WorkerPoolWireTest.php, tests/Unit/Cli/NumericFlagCoercionTest.php
**Shared resources:** ConfigLoader.php workers-upper-bound leg (:685-690 region) chains with S26/S22/S27 — this is the FIRST ConfigLoader writer; fork-zone tests must be hermetic (bounded, no timing races) so CI's matrix runners don't flake; three stages' `runStreaming` call sites unchanged (signature stable).
**Acceptance:**
- Unit: task that SIGKILL-exits a child mid-chunk (child `posix_kill(getmypid(), SIGKILL)` after partial writes) → parent throws a descriptive RuntimeException (exit 1 posture through the CLI, not silent drop); missing `__done` sentinel → throw; nonzero child status → throw with the child's stderr/message frame when present.
- Unit: `stream_socket_pair` forced to fail after k children (test seam or cap edge) → already-spawned children reaped, parent fds closed (no zombie/litter: `pcntl_waitpid` returns, no fd leak asserted via `/proc/self/fd` count or closed-pipe flags).
- Cap: `--workers=100000` → effective children ≤ min(workers, 4·cpu) (assert chunk/child count), note in help/ConfigLoader message; ConfigLoader gains an upper-bound validation error or the pool clamps silently with a debug line (lane picks one, pinned).
- Existing WorkerPoolTest/WireTest green.

### once-S46 — Worker death mid-stream ≠ success

- **Queue rank:** 102 of 247
- **Severity mix:** high=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-050
- **Prereqs:** shell-S10 (sentinel/exit-status core), once-S02

**Files:** src/Server/AnalysisWorker.php, tests/Unit/Server/AnalysisWorkerTest.php
**Shared resources:** AnalysisWorker chain.
**Acceptance:** fake worker emitting 23/40 records then EOF-without-__done + nonzero status → consumer reports failure exit and partial marker (was silent 0); status checked even on clean EOF.

### shell-S11 — Decode hot-path: memoized allow-list + cheap blocked-class detection

- **Queue rank:** 103 of 247
- **Severity mix:** high=1 medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-011, php-dup-finder-shell-034
- **Prereqs:** shell-S10 (WorkerPool drain/decode region reshaped there first)

**Files:** src/Parallel/WorkerPool.php, src/Persistence/SerializedClassAllowList.php, tests/Unit/Parallel/WorkerPoolTest.php, tests/Unit/Persistence/SerializedClassAllowListTest.php
**Shared resources:** the recursive "blocked class" scan becomes ONE shared helper (SerializedClassAllowList) reused by S19's stores and S39's allow-list leg — keep its signature stable; frame-envelope posture change interacts with WorkerPool's __done/__error early-outs from S10.
**Acceptance:**
- Unit: `blockCacheClasses()` returns the identical array instance across calls (`===` memo assert); WorkerPool hoists once per run (code review).
- Unit: decodeFrame on a graph-wire record (nested Block+ASTs) yields the same allow/refuse verdict as the old deep scan (fuzz the existing poison vectors: blocked class nested 3 deep inside an allowed container is STILL caught — verdict parity test, else security regresses).
- Perf sanity: micro-bench in the lane report (e.g. 5k frames decode wall before/after; PERF's 2.13x delta should collapse — no CI assertion, report only).


- **Phase-4 Outside memo:** ENGINE-PERF out-of-group 3 is an explicit no-defect audit memo (parserClasses already memoized) — recorded so nobody double-files it; this step's memo helper is its home.

### shell-S12 — WorkerPool cancellation: stall-aware cooperative abort

- **Queue rank:** 104 of 247
- **Severity mix:** low=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-041
- **Prereqs:** shell-S10, shell-S11 (pool loop settled), shell-S14, shell-S15 (the three stages' runStreaming call-site regions are rewritten there first)

**Files:** src/Parallel/WorkerPool.php, src/Pipeline/Stages/PreprocessStage.php, src/Pipeline/Stages/ClusterStage.php, src/Pipeline/Stages/RefactorStage.php, tests/Unit/Parallel/WorkerPoolTest.php
**Shared resources:** optional-callback param added to runStreaming (signature change — all three call sites move in one step); stages also get re-indented by S37 AFTER this.
**Acceptance:**
- Unit: stalled child + cancel callback flipping true → runStreaming returns promptly, children SIGTERMed + reaped (S10's exit-status logic recognizes deliberate cancels, does NOT throw for them), generator ends without error; `state->cancelled` CLI path exercised (TUI-first-Ctrl-C now interrupts mid-stream).
- `runStreaming` default (no callback) behaves exactly as before — all existing green.

### once-S77 — CPU detection portable + workers ceiling + PHPDUP_WORKERS semantics

- **Queue rank:** 105 of 247
- **Severity mix:** medium=2
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-093, php-dup-finder-once-110
- **Prereqs:** shell-S10

**Files:** src/Parallel/WorkerPool.php, src/Cli/Command.php, docs/CLI.md, tests/Unit/Parallel/WorkerPoolTest.php
**Shared resources:** shell-S10 owns --workers admission clamp (mine = detection + docs); ForbiddenCode constraint (no shell_exec: read cgroup/quota through PHP file APIs + sugar idiom).
**Acceptance:** probe in container w/ cpu.max cgroup limit → detected == quota (unit parses fixture /sys + /proc strings); Windows path uses portable API no `2>/dev/null`; --workers 99999 → clamped (shell fence re-verify) + PHPDUP_WORKERS documented as seed-not-override (or flipped — pick truth, test it).

### shell-S18 — Cache load order: header/metadata check BEFORE full unserialize

- **Queue rank:** 106 of 247
- **Severity mix:** high=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-shell-009
- **Prereqs:** none

**Files:** src/Persistence/IndexStore.php, src/Persistence/ClusterCache.php, tests/Unit/Persistence/IndexStoreTest.php, tests/Unit/Persistence/ClusterCacheTest.php, tests/Unit/Persistence/ClusterCacheVersionTest.php
**Shared resources:** on-disk cache FILE FORMAT changes (small header/meta prefix) → all pre-existing `.phpdup-cache` blobs must miss gracefully (never crash) — one-time invalidation noted in report; ReportStage writes `clusters.list` (S40 territory) not here; Config.php default cache-dir posture leg belongs to S39 (052b), don't pre-empt.
**Acceptance:**
- Unit: blob with stale parser_version / mismatched file_hash / flipped config_key → load() returns miss WITHOUT deserializing the payload (assert via a payload that would be expensive or poison: e.g. a 50MB serialized junk body behind a short header → fast miss); malformed header → miss, not error.
- Unit: old-format (current-shape) file present → clean miss (backward compat), new save() writes new format once, next load hits.
- save() no longer sha1_file's the source twice (caller passes hash — unit proves single hash count via seam or code review).
- Perf probe (report-only): warm-then-config-flip run on a fixture tree drops from PERF's +46% toward cold parity.
- Golden byte-identical (cache is transparent).

### shell-S19 — Cache shape guards: recursive poisoning check + LOCK_EX

- **Queue rank:** 107 of 247
- **Severity mix:** medium=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-shell-031
- **Prereqs:** shell-S18 (load-order rewrite lands first; guards attach to its new read path), shell-S11 (shared recursive blocked-class helper from SerializedClassAllowList — reuse, do not fork a second implementation)

**Files:** src/Persistence/IndexStore.php, src/Persistence/ClusterCache.php, tests/Unit/Persistence/IndexStoreTest.php, tests/Unit/Persistence/ClusterCacheTest.php
**Shared resources:** same two files as S18 (chain); poison-vector tests must not hit the real tree (tmp dirs); engine-side BlockAstLoader:664 cascade (Outside note) expected to stop reproducing after this step.
**Acceptance:**
- Repro-turned-test: tampered IndexStore blob with `['not-a-block', 42]` payload → friendly refusal (phpdup error line, nonzero exit — runtime-error class exit 1, NOT 255 TypeError); tampered ClusterCache blob with nested stdClass hole → refused (was: served corrupt, exit 0).
- ClusterCache::save uses LOCK_EX (code + a concurrent-save unit); SerializedClassAllowListTest green (helper moved/shared).
- Probe: `bin/phpdup analyze src` twice with a poisoned `--cache-dir` → second run errors cleanly; `--no-cache` unaffected.


- **Phase-4 Outside merge:** ENGINE-SEC out-of-group 1 routes the IndexStore/ClusterCache harder landing here (shape guards) + once-S64 (trust posture, empty-blob, prune) + shell-S39(b) (dir perms). WorkerPool fork-transport allow-list confirmation = engine-S39.

### shell-S30 — Cluster cache: cohort-order correctness + 5 missing key fields

- **Queue rank:** 108 of 247
- **Severity mix:** medium=2
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-shell-018, php-dup-finder-shell-019
- **Prereqs:** shell-S14, shell-S18 (ClusterStage score-region + cache-key consumption stable first; IndexStore/ClusterCache header format from S18 is what the new fields ride in)

**Files:** src/Pipeline/Stages/ClusterStage.php, tests/Unit/Pipeline/Stages/ClusterStageTest.php, tests/Unit/Persistence/ClusterCacheTest.php, tests/Unit/Persistence/ClusterCacheVersionTest.php
**Shared resources:** ClusterCache key bytes (namespace-only change → existing cluster caches miss once); Golden unaffected (default runs never diff-base).
**Acceptance:**
- Unit: warm full-corpus cluster cache + `--diff-base` run → cache NOT served (skip-when-diffBase posture or post-expansion keying — lane choice, pinned); diff-base save does not poison full-run keys.
- Unit: cache written under `--scorer=ir` (and each of ted_weights/ml_pair_url/ml_pair_threshold/ir_threshold varied) replays as MISS into the differing config; ClusterCacheVersionTest pattern extended.
- `--workers` default full-pipeline probe: second run faster via cache (sanity, report-only).

### shell-S31 — config-schema.json ↔ ConfigLoader $known parity test

- **Queue rank:** 109 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-036
- **Prereqs:** shell-S22, shell-S26, shell-S27, shell-S30 (admitted-key list + per-dir refusals + apply posture + any cache keys are FINAL; schema mirrors end-state), shell-S10 (workers bound leg touched ConfigLoader there)

**Files:** docs/config-schema.json, tests/Unit/Cli/ConfigSchemaParityTest.php (new), src/Cli/ConfigLoader.php (expose $known for the test: public const/static getter)
**Shared resources:** docs/config-schema.json writer after S22/S27's riders — consolidate all property additions here (apply, debug_log, exclude_reset, normalization, ted_weights + S27's choice + S10's workers-max note); per-dir section wording S22 began.
**Acceptance:**
- New test asserts the two sets equal (documented "kept in sync by hand" comment upgraded to pinned); add the ≥5 missing properties with types/defaults; test FAILS if either side drifts (mutation-proof in report).
- `bin/phpdup analyze --config <file-with-ted_weights> --validate-config` exit 0 (loader accepted it all along; schema consumers now agree).

### once-S83 — Internal shorthand scrubbed from user-facing surfaces

- **Queue rank:** 110 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-103
- **Prereqs:** output-S06, shell-S28, shell-S29, shell-S31  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/Command.php, docs/CLI.md, docs/config-schema.json, src/Refactor/PlacementAdvisor.php, src/Clustering/Clusterer.php (remedy strings), src/Cli/AutoTuner.php (narration)
**Shared resources:** help-text wrapping decision (300-600 char rows) = shared w/ S18 table width work; 'F1a/F1b' rows co-owned w/ S29 (S29 owns --help, S83 owns the rest — coordinate via this ledger line); PlacementAdvisor user strings after output-S06/S36 finalize.
**Acceptance:** grep census: user-facing (help/docs/report strings) contain no (NN1)/p5a-m1/'item 1.1/§5a'/F1a/F1b/MAX_CANDIDATE_PAIRS-as-symbol; remedy text names flags+values not constants; help rows wrapped ≤ ~110ch; tests/Unit/Cli/CliDefaultShadowingTest-style probes green.

### shell-S39 — Trust-posture roll-up: explicit decisions on allow-list, cache perms, socket race, error echoes

- **Queue rank:** 111 of 247
- **Severity mix:** low=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-shell-052
- **Prereqs:** engine-S39, shell-S01, shell-S02, shell-S08, shell-S11, shell-S18  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Persistence/SerializedClassAllowList.php, src/Persistence/IndexStore.php, src/Cli/Config.php, src/Server/Application.php, src/Server/AnalysisWorker.php, src/Cli/ServeCommand.php
**Shared resources:** Config.php:74 default cache-dir location decision (in-tree `.phpdup-cache` → 0700 + configurable; moving the DEFAULT is breaking — posture: tighten perms, keep location, disclose); this is the LAST writer of Application.php/ServeCommand.php/WorkerPool(:260-283)/AnalysisWorker.php behavior after the chain (rebase onto all of them).
**Acceptance (per leg, all four decided + landed or explicitly banked with reason in the report):**
- (a) allow-list: explicit curated set replaces the composer-classmap sweep — SerializedClassAllowListTest pins the exact set (mutation-proof: a PhpParser node class removed from the curation → the wire test still passes ONLY because value-object set covers… no: assert nested-node decode still works; if curation shrinks the graph safety, that's S11's envelope — verify together).
- (b) cache dirs: `stat -c %a` = 700 on fresh IndexStore dirs (was 0775); Config.php:74 posture documented.
- (c) socket: bind under a temporary restrictive umask OR flock-then-chmod-with-verify before accept; race leg unit-reviewed + reported.
- (d) 500/400 bodies: generic text + logged detail (ApplicationTest asserts message path-leak gone: an exception with an absolute path in its message → body contains none of it, while the debug log / STDERR keeps the detail).

---

### shell-S13 — writeRecord: linear partial-write handling

- **Queue rank:** 112 of 247
- **Severity mix:** low=1
- **Size:** MM (~25 min)
- **Covers:** php-dup-finder-shell-050
- **Prereqs:** shell-S12, shell-S39  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Parallel/WorkerPool.php, tests/Unit/Parallel/WorkerPoolTest.php
**Shared resources:** none beyond WorkerPool.php.
**Acceptance:**
- Unit: large record (>2·pipe buffer) against a slow reader writes fully with correct bytes; implementation uses a fixed slice cursor (code review: no `substr` of the shrinking remainder per iteration); WorkerPoolWireTest (round-trips) green.

### once-S45 — Forked workers: SIG_DFL reset before work (kill-switch real)

- **Queue rank:** 113 of 247
- **Severity mix:** high=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-049
- **Prereqs:** shell-S13 (chain tail)

**Files:** src/Parallel/WorkerPool.php, tests/Unit/Parallel/WorkerPoolTest.php
**Shared resources:** WorkerPool hot chain after shell; feeds once-S74/S77.
**Acceptance:** unit/live: parent `kill -TERM <worker-pid>` → child dies 143 & pool marks death (was immortal handler-inherited hang); pcntl_async_signals off in child branch asserted via child probe output.

### once-S44 — Death paths clean up: socket gone, worker reaped, no stale lock

- **Queue rank:** 114 of 247
- **Severity mix:** high=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-once-048
- **Prereqs:** once-S45, shell-S03, shell-S39  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/ServeCommand.php, src/Server/AnalysisWorker.php, bin/phpdup-worker
**Shared resources:** pid-file/flock in results dir (S71's sweep sees it).
**Acceptance:** kill -9 then restart: stale socket auto-reclaimed (bind error path handled), `pgrep phpdup-worker` empty after second start; worker ppid-watchdog unit (fake ppid) exits.

### once-S71 — serve tokens/stale results/security section (posture owner call)

- **Queue rank:** 115 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-085
- **Prereqs:** shell-S02, shell-S39, once-S02

**Files:** src/Cli/ServeCommand.php, src/Server/Application.php, docs/SERVER.md
**Shared resources:** [OWNER: default-token vs documented-local-only]; SERVER.md security section final owner (S42 leg folded into S39's section by ledger: S71 LAST writer); results-dir sweep couples S44 pid-file.
**Acceptance:** chosen posture implemented+documented: tokenless multi-user box probe (second uid simulated via chmod/read assertions) no longer serves arbitrary paths unauthenticated if token default (POST/GET 401 without token when enabled); stale /tmp/phpdup-results-* from dead pids pruned at startup (count assert); 0700 recheck.

### once-S97 — serve odds: ::1 brackets, verbosity notes, healthz doc, redaction

- **Queue rank:** 116 of 247
- **Severity mix:** low=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-122
- **Prereqs:** once-S42, once-S71, shell-S05, shell-S39  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Server/Application.php, src/Cli/ServeCommand.php, docs/SERVER.md
**Shared resources:** SERVER.md final append slot; exception-redaction leg w/ S71/S39.
**Acceptance:** IPv6 bind --host ::1 probe: connect URL builds `tcp://[::1]:port` (unit + one-shot live); pcntl-absent serial fallback visible at DEFAULT verbosity (not only -vvv); raw-path exception bodies redacted (relative labels only — S39 seam); /healthz token note (if S71 default-token landed) in doc; predict+@unlink socket window closed (S44 pid-flock reused).

### once-S64 — Cache durability: shape trust closed, empty-blob miss, prune policy

- **Queue rank:** 117 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-073
- **Prereqs:** engine-S23b, engine-S24, shell-S18, shell-S19, shell-S39  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Persistence/ClusterCache.php, src/Persistence/IndexStore.php, tests/Unit/Persistence/ClusterCacheTest.php, src/Cli/Command.php (prune leg per ruling), docs/CLI.md
**Shared resources:** [OWNER: prune flag vs TTL vs documented rm — default documented-rm if none]; dir-perm closure vs shell-S39.
**Acceptance:** units: `serialize([])` blob → treated as MISS not permanent hit (today it hides file forever); nested stdClass envelope rejected (engine-S23 twin re-run for ClusterCache lane); LOCK_EX write verified concurrent (two-process fork test); cache dirs 0700 census; prune policy landed or documented verbatim.


- **Phase-4 Outside merge:** ENGINE-SEC out-of-group 1's IndexStore/ClusterCache content-addressed trust half lands here (posture + empty-blob miss + prune decision), built on the shell-S18/S19 read path and engine-S23/S24 keys.

### once-S73 — Sync fallback: loud refusal, not secret full-pipeline

- **Queue rank:** 118 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-088
- **Prereqs:** shell-S08, shell-S35, once-S71

**Files:** src/Server/Application.php, src/Cli/ServeCommand.php, docs/PLAYGROUND.md
**Shared resources:** Application server chain (post S72 probe removal); playground story lands or dies here (final PLAYGROUND edit w/ S17 first).
**Acceptance:** worker-missing deployment (/tmp read-only dir probe): jobs return honest 503 'async unavailable' + startup warning instead of inline-pipeline stall-then-maybe; fixed 5 s probe becomes startup-once; docs = shipped reality.

### once-S74 — Wire framing caps + shared frame codec + fork-window/fd leaks

- **Queue rank:** 119 of 247
- **Severity mix:** medium=2
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-089, php-dup-finder-once-091
- **Prereqs:** shell-S03, shell-S07, shell-S10, shell-S13, shell-S39  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Util/Framing.php (new, w/ UP-02), src/Parallel/WorkerPool.php, src/Server/AnalysisWorker.php, src/Cli/Command.php, tests/Unit/Parallel/WorkerPoolTest.php
**Shared resources:** Util/Framing.php new file single-owner (engine-S34 AstSerializer stream is related but distinct — cite, don't collide); Command spawn leg (proc_open array form).
**Acceptance:** oversize record / truncated frame → bounded read + explicit protocol error (no unbounded fgets memory: length-prefixed cap shared by worker AND serve reader); fork/bootstrap throw leaves zero children (pcntl_waitpid census) and no fd leak (/proc/self/fd count unit); worker stderr captured to log not @fclose-discard.

### shell-S33 — Watch mode: survive any pipeline Throwable; debounce + PHP filtering + no symlink follow

- **Queue rank:** 120 of 247
- **Severity mix:** medium=2
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-shell-023, php-dup-finder-shell-024
- **Prereqs:** none

**Files:** src/Watch/WatchRunner.php, src/Watch/FileWatcher.php, tests/Unit/Watch/WatchRunnerTest.php, tests/Unit/Watch/FileWatcherTest.php
**Shared resources:** FileScanner::looksLikePhp reuse (no fork of the predicate — S20/S21 keep FileScanner settled); poll-backend FOLLOW_SYMLINKS drop mirrors S20's posture (consistency note); debounce window constant (tunable, no new CLI flag in this step); watch-mode is interactive — CI-safe tests only via injected clocks.
**Acceptance:**
- Unit: callback throwing a plain RuntimeException on pass 1 → watcher survives, warning rendered, next event still triggers a pass (was: process death); back-off after N consecutive failures honored (lane may pick simple skip-note posture — pin choice).
- Unit: touch of a .txt/.md file → NO re-analysis; touch of 5 .php files inside the debounce window → exactly ONE pipeline pass (event-coalescing proof with fake backend); poll backend does not follow out-root symlinks (fixture like S20's).
- WatchRunnerTest + FileWatcherTest green; PERF duty-cycle note report-only.

### once-S08 — Watch survives non-budget errors (closure)

- **Queue rank:** 121 of 247
- **Severity mix:** critical=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-once-008
- **Prereqs:** shell-S33

**Files:** src/Watch/WatchRunner.php, tests/Unit/Watch/WatchRunnerTest.php
**Shared resources:** WatchRunner also S47 (debounce) — serialize S08 → S47.
**Acceptance:** unit: run-callback throws RuntimeException → daemon logs, loop continues, next event re-analyzes; pool-construction throw likewise; process-alive assertion after forced failure.

### shell-S40 — ReportStage/watch/TUI micro-costs

- **Queue rank:** 122 of 247
- **Severity mix:** low=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-shell-051
- **Prereqs:** shell-S33  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Pipeline/Stages/ReportStage.php, src/Watch/FileWatcher.php, src/Tui/PhpdupModel.php, tests/Unit/Pipeline/Stages/ReportStageTest.php, tests/Unit/Tui/PhpdupModelTest.php (if exists)
**Shared resources:** cache-dir mode 0775 leg NOT here (S39's roll-up, decision pending — don't half-touch ReportStage.php:294); golden unaffected (report bytes unchanged when clusters>0; zero-cluster case writes nothing vs an empty list — disclose if a fixture covers it).
**Acceptance:**
- Unit: zero-cluster report run does NOT (re)write clusters.list / does not create the dir when unchanged; rewrite is content-skipped when identical; FileWatcher poll walk: clearstatcache hoisted per-scan not per-file (count via seam or code review); PhpdupModel RSS sample throttled (interval const) — repaint cadence test green.

### shell-S34 — Escape untrusted filesystem bytes at every terminal sink

- **Queue rank:** 123 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-shell-025
- **Prereqs:** shell-S20, shell-S21 (FileScanner settled), shell-S23 (ScanningStage settled), shell-S33 (WatchRunner settled), shell-S28 (Command.php writer chain finished)

**Files:** src/Scanning/FileScanner.php, src/Pipeline/Stages/ScanningStage.php, src/Watch/WatchRunner.php, src/Cli/Command.php, tests/Unit/Scanning/FileScannerTest.php, tests/Unit/Pipeline/Stages/ScanningStageTest.php
**Shared resources:** OutputInterface::escape convention becomes law for path bytes (grep-able pattern for future sinks); STDERR skip-note leg uses control-char scrubbing (no Symfony formatter there); docs rider only if wording of a sample changes.
**Acceptance:**
- Probe: dir name containing a raw ESC sequence (create via printf) → warning bytes on STDERR are scrubbed (`cat -v` shows no live escapes); `--diff-base=$'<error>boom'` → literal text visible, no swallowed markup (SEC's markup-consumption repro inverted); unit-level: captured Symfony BufferedOutput contains escaped markup.
- Full Unit green.

### shell-S37 — Stage cancel-block reindent + yield-constant naming/rule reconciliation

- **Queue rank:** 124 of 247
- **Severity mix:** low=2
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-shell-042, php-dup-finder-shell-054
- **Prereqs:** shell-S12, shell-S15, shell-S16 (cancel blocks + yield regions rewritten there first), shell-S23 (ScanningStage), shell-S30 (ClusterStage), shell-S34 (ScanningStage/Preprocess debug-line escapes touch :54-57)

**Files:** src/Pipeline/Stages/PreprocessStage.php, src/Pipeline/Stages/ClusterStage.php, src/Pipeline/Stages/ScanningStage.php, src/Pipeline/Stages/RefactorStage.php
**Shared resources:** rename `ScanningStage::YIELD_INTERVAL` → `YIELD_EVERY` (internal const, no API break); the rule-file fold of real values (16/32/256/4) became a once-S80 packet rider at Phase-5 r1 (I3/D3a — caliber file, owner applies).
**Acceptance:**
- `git diff -w` shows only real changes on the reindent hunks (whitespace-only claim); `grep -rn YIELD_INTERVAL src/` empty; the five blocks visually nested at correct depth; aborted-run accounting caveat documented in docs/ CLI-side note as desired-rule text for the once-S80 packet (or cancelledMidLoop flag — pin if chosen, then the summary line shows it).
- Full Unit green, Golden byte-identical (no semantic change).

### once-S23 — Cache-key completeness: every detection knob invalidates

- **Queue rank:** 125 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-026
- **Prereqs:** engine-S24, shell-S16, shell-S37  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Pipeline/Stages/ClusterStage.php, src/Pipeline/Stages/PreprocessStage.php, tests/Unit/Cli/CacheKeyKnobReplayTest.php
**Shared resources:** ClusterStage/PreprocessStage both late in shell chains (S14→…→S37 / S15→…→S37) — once-leg after them; new test models FingerprintIdModeConfigTest precedent.
**Acceptance:** warm cache then flip EACH of scorer/ir_threshold/ml_pair_*/ted_weights/low_memory/normalization_plugins/per-dir-overrides → report bytes change or cache-miss counted (no md5-identity across engine switch); unit test ships as the permanent fence.


- **Phase-4 Outside merge:** ENGINE-CONV-04 — the IndexStore config-key serialize list at PreprocessStage.php:82-109 omits `normalizationPlugins` (violating the CLAUDE.md plugin contract); add the key + extend this step's knob-flip fence to cover it.

### once-S75 — Signal contract: scoped handlers, second-SIGINT exits now

- **Queue rank:** 126 of 247
- **Severity mix:** medium=1
- **Size:** HH (~75 min)
- **Covers:** php-dup-finder-once-090
- **Prereqs:** once-S07, once-S45, shell-S33  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/SignalHandler.php, src/Cli/Command.php, src/Watch/WatchRunner.php, src/Tui/TuiRunner.php, tests/Unit/Cli/SignalHandlerTest.php
**Shared resources:** SignalHandlerTest global-async flip = S93 test-hygiene dependency (S75 before S93); TuiRunner/WatchRunner shared w/ S07/S08/S47 (final signal pass).
**Acceptance:** install() returns restorer; async mode set inside scope only (unit: pcntl_async_signals(false) after scope, handler restored); second SIGINT → exit 130 within one loop iteration incl watch+TUI (PTY probe: two ^C, capture exit); '130' doc row to S95.

### engine-S18a — Preprocess residency: int postings, gram interning, lowMemory default threshold — half A: int postings in NgramInvertedIndex.

- **Queue rank:** 127 of 247
- **Severity mix:** high=1
- **Size:** HH (~140 min)
- **Covers:** php-dup-finder-engine-011
- **Prereqs:** engine-S01, engine-S12, engine-S32  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: int postings in NgramInvertedIndex. Sibling: engine-S18b — together the two legs replace engine-S18 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S18 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Extraction/Block.php` (:24-30 fields/docblocks), `src/Index/NgramInvertedIndex.php`
  (:42-50 int postings), `src/Fingerprint/NgramFingerprint.php` (master gram dictionary + int-bag
  default logic), `tests/Unit/Index/NgramInvertedIndexTest.php`, `tests/Unit/Fingerprint/NgramFingerprintTest.php`,
  `tests/Unit/Fingerprint/NgramFingerprintModeTest.php`; cross-group rows (report, do not edit):
  default-flip threading at `src/Pipeline/Stages/PreprocessStage.php` + `src/Parallel/PreprocessWorker.php`
  and IndexStore/ClusterCache key-version bumps (the finding routes the wiring decision to the shell group).
  Block.php collision with the canonical-typing question), engine-S32 (64-bit keys fix BEFORE making
  int bags the default — default must ship on the sound keyspace).
- **Shared resources:** persisted cache shapes — serialized Blocks carry ngram bags; changing key
  families MUST bump the IndexStore config-key/`ClusterCache` version (src/Persistence cross-group;
  name the exact roster files in the commit message) and docs/config-schema.json text is NOT affected;
  FingerprintIdMode roster (src/Fingerprint/FingerprintIdMode.php) shares the "bag mode" concept —
  align naming.
- **Acceptance:** unit: postings store int ids; dictionary interning returns stable keys; warm-run
  after cold-run with changed shapes yields identical candidates (cache-version actually bumps —
  IndexStoreStaleLabelsTest + new pin); 80k-block serial RSS re-measured on the reviewer's /tmp
  corpus recipe with disclosed before/after figure (not CI-timed); full suites green.


- **Phase-4 Outside memos:** ENGINE-PERF out-of-group 2 (int-bag default-flip threading lands at the PreprocessStage/PreprocessWorker wiring layer = shell group; cross-group rows stay report-only here) + output's (engine) PERF-24 peak-RSS 382 MB memo is this residency line.

### engine-S08a — AptedDistance flatten() size-sort erases statement order (high) — half A: flatten/orderedChildren fix + distance>0 + permuted<1.0000 pins + EditCostModel note (core APTED semantics).

- **Queue rank:** 128 of 247
- **Severity mix:** high=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-007
- **Prereqs:** engine-S01  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: flatten/orderedChildren fix + distance>0 + permuted<1.0000 pins + EditCostModel note (core APTED semantics). Sibling: engine-S08b — together the two legs replace engine-S08 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S08 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Similarity/AptedDistance.php` (flatten :261-309, orderedChildren),
  `tests/Unit/Similarity/AptedDistanceTest.php`, `tests/Unit/Similarity/AptedDistanceModeTest.php`,
  `tests/Unit/Clustering/ClustererRunningMinTest.php` (Jaccard-tier 1.0 mints on permuted code),
  `tests/Unit/Similarity/EditCostModelTest.php` (worst-cost bound note from PDDETECT),
  `tests/Golden/optional-near-dups.json` (regen if sim values shift).
  semantics — golden-serial law).
- **Shared resources:** tests/Golden near-dup values (GOLDEN-MOVES-possible); tree-cost model docs
  (`docs/algorithms/` if it exists — check prose, else report-only row).
- **Acceptance:** pins from the finding: swapped unequal-size siblings ⇒ distance > 0 (never 1.0);
  the reviewer's `{ $u=1; if($u){g();} }` permuted pair scores < 1.0000; equal-size-swap pair no
  longer lands Jaccard-tier 1.0 through Clusterer (unit); structuralHash-order vs TED-order never
  disagree on identical-multiset bodies (property/unit); `--filter 'Apted|Clusterer'` green;
  Golden suite green (regen disclosed or verified unmoved).

### engine-S08b — AptedDistance flatten() size-sort erases statement order (high) — half B: ClustererRunningMin tier pin + optional-near-dups regen-if-shift (GOLDEN token) + docs/algorithms consistency check.

- **Queue rank:** 129 of 247
- **Severity mix:** high=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-007
- **Prereqs:** engine-S01, engine-S08a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: ClustererRunningMin tier pin + optional-near-dups regen-if-shift (GOLDEN token) + docs/algorithms consistency check. Sibling: engine-S08a — together the two legs replace engine-S08 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S08 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Similarity/AptedDistance.php` (flatten :261-309, orderedChildren),
  `tests/Unit/Similarity/AptedDistanceTest.php`, `tests/Unit/Similarity/AptedDistanceModeTest.php`,
  `tests/Unit/Clustering/ClustererRunningMinTest.php` (Jaccard-tier 1.0 mints on permuted code),
  `tests/Unit/Similarity/EditCostModelTest.php` (worst-cost bound note from PDDETECT),
  `tests/Golden/optional-near-dups.json` (regen if sim values shift).
  semantics — golden-serial law).
- **Shared resources:** tests/Golden near-dup values (GOLDEN-MOVES-possible); tree-cost model docs
  (`docs/algorithms/` if it exists — check prose, else report-only row).
- **Acceptance:** pins from the finding: swapped unequal-size siblings ⇒ distance > 0 (never 1.0);
  the reviewer's `{ $u=1; if($u){g();} }` permuted pair scores < 1.0000; equal-size-swap pair no
  longer lands Jaccard-tier 1.0 through Clusterer (unit); structuralHash-order vs TED-order never
  disagree on identical-multiset bodies (property/unit); `--filter 'Apted|Clusterer'` green;
  Golden suite green (regen disclosed or verified unmoved).

### engine-S19a — ML SSRF gate rebuild + response-size caps (high, demonstrated PoC) — half A: SSRF gate rebuild — canonical-literal-or-resolve, inet_pton matrices, unit refuse-list matrix.

- **Queue rank:** 130 of 247
- **Severity mix:** high=1 medium=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-012, php-dup-finder-engine-015
- **Prereqs:** none  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: SSRF gate rebuild — canonical-literal-or-resolve, inet_pton matrices, unit refuse-list matrix. Sibling: engine-S19b — together the two legs replace engine-S19 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S19 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Ml/MlClient.php` (isAllowedUrl :123-176, isBlockedIp :178-198, postJson :210-269),
  `src/Ml/MlPairClient.php` (same trio :183-240 region), `tests/Unit/Ml/MlClientTest.php`,
  `tests/Unit/Ml/MlPairClientTest.php`.
- **Shared resources:** behavior flip: fail-open-on-DNS-failure → fail-closed breaks previously-working
  hostnames whose DNS was down-only-at-check (accepted per finding; disclose). Server/JobQueue `ml_pair_url`
  admission is shell/NET (Outside) — reference only. docs/ML.md posture prose = report-only row.
- **Fix shape:** canonical-literal-or-resolve ≥1 record; `inet_pton` bit checks over every A/AAAA
  (loopback/RFC1918/CGNAT/link-local/ULA/IPv4-mapped); `CURLOPT_RESOLVE`/`bindto` pinning of the
  checked IP; write-callback cap (~1 MB) on curl + stream transports; docblock softened to matches.
- **Acceptance:** unit matrix: `http://0x7f000001/`, `http://[::1]/`, `http://[0:0:0:0:0:0:0:1]/`,
  `http://[::ffff:127.0.0.1]/`, `fc00::/7`, `169.254.*`, RFC1918, CGNAT all REFUSED; unresolvable
  host refused (was allowed); local-listener integration-style test (loopback server started in-test
  or a mock-socket seam) proves the previously-poisonous `0x7f000001` connect is blocked and that a
  pinned-IP request succeeds for legit hosts; oversized-body test (stream + curl seam) → malformed
  (null), no RSS balloon; existing Ml suites updated + green.

### engine-S19b — ML SSRF gate rebuild + response-size caps (high, demonstrated PoC) — half B: write-callback size caps + CURLOPT_RESOLVE/bindto pinning + oversized-body test + fail-open→fail-closed flip.

- **Queue rank:** 131 of 247
- **Severity mix:** high=1 medium=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-012, php-dup-finder-engine-015
- **Prereqs:** engine-S19a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: write-callback size caps + CURLOPT_RESOLVE/bindto pinning + oversized-body test + fail-open→fail-closed flip. Sibling: engine-S19a — together the two legs replace engine-S19 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S19 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Ml/MlClient.php` (isAllowedUrl :123-176, isBlockedIp :178-198, postJson :210-269),
  `src/Ml/MlPairClient.php` (same trio :183-240 region), `tests/Unit/Ml/MlClientTest.php`,
  `tests/Unit/Ml/MlPairClientTest.php`.
- **Shared resources:** behavior flip: fail-open-on-DNS-failure → fail-closed breaks previously-working
  hostnames whose DNS was down-only-at-check (accepted per finding; disclose). Server/JobQueue `ml_pair_url`
  admission is shell/NET (Outside) — reference only. docs/ML.md posture prose = report-only row.
- **Fix shape:** canonical-literal-or-resolve ≥1 record; `inet_pton` bit checks over every A/AAAA
  (loopback/RFC1918/CGNAT/link-local/ULA/IPv4-mapped); `CURLOPT_RESOLVE`/`bindto` pinning of the
  checked IP; write-callback cap (~1 MB) on curl + stream transports; docblock softened to matches.
- **Acceptance:** unit matrix: `http://0x7f000001/`, `http://[::1]/`, `http://[0:0:0:0:0:0:0:1]/`,
  `http://[::ffff:127.0.0.1]/`, `fc00::/7`, `169.254.*`, RFC1918, CGNAT all REFUSED; unresolvable
  host refused (was allowed); local-listener integration-style test (loopback server started in-test
  or a mock-socket seam) proves the previously-poisonous `0x7f000001` connect is blocked and that a
  pinned-IP request succeeds for legit hosts; oversized-body test (stream + curl seam) → malformed
  (null), no RSS balloon; existing Ml suites updated + green.

### engine-S20 — ML remote numeric laundering + scoreBatch alignment

- **Queue rank:** 132 of 247
- **Severity mix:** medium=2
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-engine-013, php-dup-finder-engine-014
- **Prereqs:** engine-S19b (same files).

- **Files:** `src/Ml/MlPairClient.php` (:103-108, :127-178, :169-175), `src/Ml/MlClient.php`
  (:94-100), `tests/Unit/Ml/MlPairClientTest.php`, `tests/Unit/Ml/MlClientTest.php`.
- **Shared resources:** none new (consumption at Clusterer :758-760/PrefScoreWorker unchanged).
- **Acceptance:** unit: `{"similarity":1e999}`/NAN/string/array verdicts → null (pair falls back,
  no edge minted — mirrors FUZZ-03 live observation as a pin); `scoreBatch` response count ≠ request
  count → null → honest per-pair retry path; `confidence/safety/anomaly` same domain checks; Ml
  suites + `MlPairTierClustererTest` green; goldens unmoved.

### once-S48 — ML verdict alignment closure + wire-shape docblock truth

- **Queue rank:** 133 of 247
- **Severity mix:** high=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-once-052
- **Prereqs:** engine-S20 (count/is_list core)

**Files:** src/Ml/MlPairClient.php, src/Ml/PairFeatures.php, tests/Unit/Ml/MlPairClientTest.php
**Shared resources:** Ml/*.php chain.
**Acceptance:** engine-S20's refusal units present and passing; docblock now states exact 11-field PairFeatures wire (assert fields-count == PairFeatures::WIRE_KEYS const — introduce const here); short/list responses degrade without creating edges.

### once-S53 — ML sidecar API docs + wire-shape + reference sidecar

- **Queue rank:** 134 of 247
- **Severity mix:** medium=2
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-059, php-dup-finder-once-118
- **Prereqs:** once-S48, engine-S19b, shell-S08

**Files:** docs/SERVER.md, docs/ML.md, tools/ml-sidecar-example.php, src/Ml/MlPairClient.php (docblock)
**Shared resources:** SERVER.md append slot (S17 window then S53); 118 annotated covered-by-reference (pointer row recorded, its legs live in 020→S17, 084→S54, 124→S99).
**Acceptance:** POST /score-batch contract (request/response exact 11-field wire) documented; tools/ gains a runnable ~80-line reference sidecar (stdlib PHP, examples echo-canonical verdicts); ML.md release-id example == current schema version output (ML.md example uses real ids from a /tmp fixture run); 'Wiring—Today' narrative correct per class (MlClient status cites S89 adjudication).

### once-S54 — ml_pair_url: validate shape not DNS; opt-in private ranges

- **Queue rank:** 135 of 247
- **Severity mix:** medium=3
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-076, php-dup-finder-once-083, php-dup-finder-once-084
- **Prereqs:** engine-S19b, engine-S20, once-S48, shell-S22  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/Config.php, src/Cli/ConfigLoader.php, src/Ml/MlPairClient.php, docs/CLI.md, docs/config-schema.json, docs/ML.md, tests/Unit/Cli/MlPairUrlValidationTest.php
**Shared resources:** [OWNER: --ml-pair-allow-private — default off]; six-site flag ritual per add-cli-flag skill (the skill itself stale until S81 — execute with skill-corrected sites); Ml chain order.
**Acceptance:** validate path performs ZERO resolver calls (unit with resolver-mock or timer assert against Config.php:311 path); CGNAT/NAT64/AAAA-only pinned fail-closed in engine-S19 tests re-run here through Config seam; documented localhost/RFC1918 sidecar deploy works WITH opt-in flag and refuses without (help names both); engine-S19's resolve-once+CURLOPT_RESOLVE pin verified.

### once-S51 — config-schema.json completeness final check

- **Queue rank:** 136 of 247
- **Severity mix:** medium=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-057
- **Prereqs:** shell-S31 (parity test), shell-S27, once-S54 (apply/ml keys)

**Files:** docs/config-schema.json
**Shared resources:** S51 is the schema CLOSE-OUT check, not an independent editor of the six keys.
**Acceptance:** shell-S31 parity test green: exclude_reset/ted_weights/normalization/debug_log/apply/plugins (+ any S54/S68-era adds) present with types+defaults+range.

### engine-S21 — Serial ML path: per-pair DNS + fresh TCP/TLS handshakes

- **Queue rank:** 137 of 247
- **Severity mix:** medium=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-032
- **Prereqs:** engine-S20  *(Phase-4 adjustment; see Merged & re-routed)*

- **Files:** `src/Ml/MlPairClient.php` (score :75-109, postJson), `src/Ml/MlClient.php`
  (isAllowedUrl re-check removal), `tests/Unit/Ml/*`. If serial scoring is rerouted through
  chunked `scoreBatch`, `src/Clustering/Clusterer.php:758` is touched → then this step must also
  take the Clusterer-chain prereq (after engine-S10).
  cached, not superseded.
- **Shared resources:** see Clusterer note above; PairScoreWorker retry path is shell group (Outside)
  — keep compatible (public API of score()/scoreBatch() unchanged).
- **Acceptance:** unit: URL decision memoized once per client instance (counting seam); curl handle
  reused (property on the client's handle, no leak; multiple scores → one init); disclosed micro-bench
  against a local echo sidecar (N pairs before/after, not CI-asserted); Ml suites green.


- **Phase-4 Outside merge:** ENGINE-PERF out-of-group 1 — PairScoreWorker's individual-retry-after-batch-miss path inherits the per-pair DNS/handshake cost; the client-instance memo makes it pay only if the worker builds a fresh client per retry: verify one client per worker run (pin in tests/Unit/Parallel/PairScoreWorker* if reachable) and keep score()/scoreBatch() public API unchanged.

### engine-S31a — Dead engine-class catalog: adjudication ledger + Semantic/Similarity/Index-signature batch

- **Queue rank:** 138 of 247
- **Severity mix:** medium=2
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-027, php-dup-finder-engine-028
- **Prereqs:** engine-S22, engine-S23b  *(Phase-4 adjustment; see Merged & re-routed)*


- **Files (decisions per class, delete-or-wire+truthful-docblock):** adjudication PROTOCOL first: the
  lane writes the keep/wire/delete verdict + rationale for ALL fourteen catalog classes (listed in
  output-S31 sibling rows b/c — the full list lives in this plan's engine-S31a/b/c union) into its
  lane report BEFORE touching code, so the sibling lanes execute the same ledger. This sub-lane then
  executes the Semantic/Similarity/Index-signature cluster:
  `src/Semantic/CallGraph.php` (docblock cites non-existent CallGraphSimilarity),
  `src/Semantic/ControlFlowGraph.php`, `src/Similarity/BehaviouralSimilarity.php`,
  `src/Semantic/TypeProvider.php` + `PsalmTypeProvider.php` + `NullTypeProvider.php`,
  `src/Index/LshIndex.php`, `src/Fingerprint/MinHashSignature.php` (:74-86 cost trap — int-mix fix if kept),
  plus tests `tests/Unit/Semantic/*`, `tests/Unit/Index/LshIndexTest.php`,
  `tests/Unit/Fingerprint/MinHashSignatureTest.php`, `tests/Unit/Similarity/BehaviouralSimilarityTest.php`
  — delete alongside deleted classes.
  catalog ledger is written here), engine-S22 (PairFeatures is DataflowSummarizer's only real consumer —
  identity pins land first so the S31c substring fix is measured).
- **Shared resources:** docs/adr/0003-similarity-stack.md + docs/ML.md + README 'fallback tiers' prose
  first touched HERE; the project-wide LEGACY triage belongs to the once group (engine-S31a is the
  engine-path portion only — coordinate to not double-delete, per the ENGINE-PDDETECT out-of-group 3
  routing note). [OWNER] delete-vs-wire per class is a product call surfaced to Phase-5
  (the ledger records the defaults the lane applied).
- **Acceptance:** per-class: either file+tests absent (grep `class <Name>` across src/ tests/ → zero
  hits) or docblock/usage claims corrected + defect 027 fixed with its own unit pin; full suite green;
  psalm/phpstan clean with fewer analyzed classes (deletions shrink scope — allowed, never widen);
  disclosed deletions list in the commit body; the S31a→S31b→S31c serial keeps docs/adr/0003 single-writer.

### engine-S31b — Dead engine-class catalog execution batch B (BloomCandidateIndex, ExternalSort, BlockHeader, IrSimilarity)

- **Queue rank:** 139 of 247
- **Severity mix:** low=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-engine-046
- **Prereqs:** engine-S31a (adjudication ledger — the verdicts are decided there).


- **Files (executing the S31a ledger verdicts):** `src/Index/BloomCandidateIndex.php` (O(n²) scan —
  real Bloom or stay unwired+documented), `src/Index/ExternalSort.php` (mergeRuns :113-116 loud throw +
  fwrite length checks :82-91 if kept), `src/Extraction/BlockHeader.php`, `src/Ir/IrSimilarity.php`
  (sha1→Util\Hash digest family if kept — 046), tests `tests/Unit/Index/{BloomCandidateIndex,ExternalSort}Test.php`,
  `tests/Unit/Ir/IrSimilarityTest.php` (if exists) — delete alongside deleted classes.
- **Shared resources:** docs/adr/0003 rows continue (serial after S31a); BloomFilter sharing noted in S30.
- **Acceptance:** per-class as S31a's protocol (absent-or-corrected+defect-pinned); full suite green;
  analyzer scope never widens; disclosed per-class outcome in commit body.

### engine-S31c — Dead engine-class catalog execution batch C (Normalization/Parsing/Ir/Semantic) + census close-out

- **Queue rank:** 140 of 247
- **Severity mix:** low=1
- **Size:** HH (~130 min)
- **Covers:** php-dup-finder-engine-051
- **Prereqs:** engine-S31b (serial catalog lane), engine-S23b (TokenCache hardening precedes disposition).


- **Files (executing the S31a ledger verdicts):** `src/Normalization/LiteralCanonicalizer.php`,
  `src/Normalization/NameCanonicalizer.php`, `src/Parsing/TokenCache.php` (disposition AFTER S23's
  hardening), `src/Semantic/DataflowSummarizer.php:66-68` (substring over-matching 'log'/'fire' —
  word-boundary or AST-fact check), plus `tests/Unit/Normalization/{LiteralCanonicalizer,NameCanonicalizer}Test.php`,
  `tests/Unit/Parsing/TokenCacheTest.php`, `tests/Unit/Semantic/DataflowSummarizerTest.php` —
  delete alongside deleted classes. Closes the catalog: doc rows (`docs/adr/0003-similarity-stack.md`,
  `docs/ML.md`, README 'fallback tiers' prose) get their final truth-pass after a+b+c.
- **Shared resources:** docs/adr/0003 final writer; DataflowSummarizer change alters ML features —
  disclose feature drift or land on S22's identity pins (S22 prereq already chains via S31a).
- **Acceptance:** per-class as S31a's protocol; 051 word-boundary unit pin; catalog-wide census:
  `grep 'class (CallGraph|ControlFlowGraph|BehaviouralSimilarity|TypeProvider|LshIndex|MinHashSignature|BloomCandidateIndex|ExternalSort|BlockHeader|LiteralCanonicalizer|NameCanonicalizer|TokenCache|IrSimilarity|DataflowSummarizer)' src/`
  returns only the kept set, and every kept class' docblock matches reality; `docs/` grep for the
  deleted class names returns only changelog-style mentions; full suites green.

### once-S32 — Type-4 / semantic-layer advert matches reality

- **Queue rank:** 141 of 247
- **Severity mix:** high=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-036
- **Prereqs:** engine-S31c  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** README.md, docs/adr/0003-similarity-stack.md, docs/algorithms/clustering.md, src/Semantic/, src/Validation/
**Shared resources:** engine-S31 verdict governs code half; here docs/claims only + ADR honest 'planned' rewording; README ToC w/ S13.
**Acceptance:** no advertised surface without a path: either flag documented where wired or README/ADR drop the claim + ADR gains a dated erratum; '--type4' grep zero (or flag exists); Semantic/Validation class docblocks carry the S31 ruling sentence.

### once-S89 — Ml HTTP twin collapse (postJson duplication)

- **Queue rank:** 142 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-112
- **Prereqs:** engine-S19b, engine-S20, engine-S21, engine-S31c, once-S54  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Ml/MlClient.php, src/Ml/MlPairClient.php, src/Util/Http.php (new shared)
**Shared resources:** [OWNER shared: MlClient wire-or-delete w/ S32/engine-S31 — if delete ruling lands first, this step becomes single-client tidy]; Util/* new-file roster coordination.
**Acceptance:** zero duplicated curl/POST lines (grep), both callers green w/ their suites (tests/Unit/Ml/*), divergence bug (whichever postJson was WRONG per finding evidence) fixed by the merge.

### once-S90 — Verdict parsing strict everywhere

- **Queue rank:** 143 of 247
- **Severity mix:** medium=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-once-113
- **Prereqs:** engine-S20, once-S89

**Files:** src/Ml/MlPairClient.php, tests/Unit/Ml/MlPairClientTest.php
**Acceptance:** score() and scoreBatch() share ONE coerce fn (is-numeric finite [0,1], `[0.9]`/'NaN'/true rejected not laundered); fuzz-list provider pins rejections; engine-S20's alignment fence re-run green.

### once-S92 — Response size caps both transports + deadline honesty

- **Queue rank:** 144 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-115
- **Prereqs:** engine-S19b, once-S89

**Files:** src/Ml/MlPairClient.php, src/Util/Http.php, tests/Unit/Ml/MlPairClientTest.php
**Shared resources:** Util/Http shared w/ S89/S74 codec lines (Http.php writers serialize: S89→S92).
**Acceptance:** oversized sidecar response (fake server, capped) → protocol error not unbounded alloc (memory_high water probe); stream_timeout-vs-total semantics fixed in code + docblock; cap constant shared.

### once-S79 — Dead-doc citation sweep

- **Queue rank:** 145 of 247
- **Severity mix:** medium=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-099
- **Prereqs:** engine-S31c, output-S10, shell-S28  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** explicit enumeration (Phase-5 r1, I9 — whole-tree src/ lock retired; list = the citation census): `src/Cli/Command.php`, `src/Cli/Config.php`, `src/Cli/ConfigLoader.php`, `src/Clustering/Clusterer.php`, `src/Extraction/Block.php`, `src/Ir/IrNode.php`, `src/Ir/IrSimilarity.php`, `src/Ml/MlPairClient.php`, `src/Ml/PairFeatures.php`, `src/Semantic/DbOperationTagger.php`, `src/Similarity/BehaviouralSimilarity.php`, docs/CLI.md, docs/CI.md, docs/ML.md, docs/config-schema.json, docs/ml-corpus-format.md, .gitlab/phpdup-ci.yml
**Shared resources:** config-schema + CI.md + gitlab shared windows (serialize AFTER S14's gitlab edit; docblock lines engine/output touched settle first).
**Acceptance:** grep census: zero citations to deleted docs/plans/orm-db-semantic-dedup.md (re-point to docs/algorithms/* or drop the sentence), zero 'UPDATE_PLAN.md' refs (file absent) — each replaced with a live anchor or removed-with-meaning-preservation (20+ sites).

### output-S18 — path/format boundary hardening (patch, diff, JSON evidence, DOT, PlantUML, labels)

- **Queue rank:** 146 of 247
- **Severity mix:** high=1 medium=1 low=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-011, php-dup-finder-output-047, php-dup-finder-output-052
- **Prereqs:** none

- Covers: 011, 047, 052 (GraphvizReporter.php:6 + PlantumlReporter.php:6 unused-import legs only)
- Files: `src/Util/ControlText.php` (new — scrub/reject helper for `\r\n\0\x1b`), `src/Reporting/RefactorPatchReporter.php` (memberHint), `src/Reporting/DiffReporter.php` (header builder + `## member` anchors), `src/Reporting/JsonReporter.php` (:258 evidence header), `src/Reporting/PlantumlReporter.php` (label quoting), `src/Reporting/GraphvizReporter.php` (dotString + import), `src/Reporting/PrometheusReporter.php` (labelValue + `\r`), tests: `tests/Unit/Reporting/{DiffReporterTest,RefactorPatchReporterTest,GraphReportersTest,PrometheusReporterTest,JsonReporterEvidenceTest}.php`, `tests/Unit/Util/ControlTextTest.php`
- Size: HH (150 min)
- Prereqs: none. Early — S19/S20/S22/S35 consume its helper.
- Conflicts: serial with S38/S43 (same reporters).
- Acceptance: `--filter ControlTextTest` (reject/quote each control byte); fixture case with newline-in-dirname: `bin/phpdup analyze <fixture> --patch /tmp/p` output passes `git apply --check`-parseable structure with the path tab-quoted or the member refused at report build with a named diagnostic; generated `.puml` cannot contain a bare `!` directive injected from a name; DOT label round-trip escapes; Prometheus test asserts `\r` neutralized; the `patch -p1` file-creation repro (SEC-03 shape) re-run as regression test ⇒ cannot create files; goldens byte-identical (no such paths in corpora); GATE.

### output-S20 — PrometheusReporter: aggregate-default series, per-cluster detail out of JSON/HTML

- **Queue rank:** 147 of 247
- **Severity mix:** medium=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-output-026
- **Prereqs:** output-S02, output-S18  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 026
- Files: `src/Reporting/PrometheusReporter.php`, `tests/Unit/Reporting/PrometheusReporterTest.php`, `docs/CLI.md` (wording only — no new flag; the deliberate no-flag posture is the plan ruling recorded here)
- Size: HH (60 min)
- Prereqs: S18 (labelValue reuse), S02 (encode posture — no conflict expected; file serial).
- Acceptance: `--filter PrometheusReporterTest` — default emit contains NO `phpdup_cluster_impact{id=...}` lines; aggregate `phpdup_duplicates_total`/by-severity/kind series present; a test documents the metric-name change; goldens byte-identical (prometheus not golden-fed); GATE.

### output-S19 — CliReporter terminal-escape scrub + composer package-name gate

- **Queue rank:** 148 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-046
- **Prereqs:** output-S18  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 046
- Files: `src/Reporting/CliReporter.php` (boundary application at :205-207, :219, :270-276, :313, :322, :328-339), `src/Refactor/PlacementAdvisor.php` (packagesAt :223-233 charset), `tests/Unit/Reporting/CliReporterHonestyTest.php`, `tests/Unit/Refactor/PlacementAdvisorTest.php`
- Size: HH (90 min)
- Prereqs: S18 (helper).
- Conflicts: serial with S07 (same file, lands first).
- Acceptance: `--filter CliReporterHonestyTest` — filename embedding `\x1b]0;pwn\x07` renders scrubbed under BOTH decorated and `--no-ansi` (observable via Symfony BufferedOutput test + `bin/phpdup analyze` on hostile fixture piped to `cat -v` shows `^[[` bytes absent); PlacementAdvisor drops `vendor/na<newline>me` keys; GATE.

### once-S21 — TUI-side control-char scrub (ViewState leg)

- **Queue rank:** 149 of 247
- **Severity mix:** high=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-024
- **Prereqs:** output-S18, output-S19 (CLI/report boundary core)

**Files:** src/Tui/ViewState.php, tests/Unit/Tui/ViewStateTest.php
**Shared resources:** ViewState chain (S21 first writer; then S61/S62/S63/S96).
**Acceptance:** cluster/file labels with ESC+BEL render escaped; width math runs on display-cells AFTER escaping (unit: known bad label → no raw byte in rendered string, column count preserved).

### output-S22 — formula-neutralization in Diff/RefactorPatch/RefactorTest metadata rows (or rule carve-out)

- **Queue rank:** 150 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-035
- **Prereqs:** output-S18  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 035
- Files: `src/Reporting/DiffReporter.php`, `src/Reporting/RefactorPatchReporter.php`, `src/Reporting/RefactorTestReporter.php`, reused guard shape from `src/Reporting/CsvReporter.php:81-93`, tests: the three matching `tests/Unit/Reporting/*Test.php`; (the rule-carve-out alternative became owner-side at Phase-5 r1 — caliber file — so this step executes the guard branch: implement guard on metadata/tabular rows, leave raw hunk bodies untouched)
- Size: HH (90 min)
- Prereqs: S18 (adjacent escaping in same files; land S18 first), after S43/S46-shape decisions if RefactorPatch lines drift (soft).
- Acceptance: guard test per reporter: cluster name `=cmd|'/C calc'` in a metadata row emits `'=cmd…` prefix; unified-diff hunk body lines starting `+`/`-` NOT prefixed (regression pin); goldens byte-identical; GATE.
- **Phase-5 r1 (I3/D3a):** desired `.claude/rules/reporting-patterns.md:12` carve-out wording (if ever preferred) → once-S80 packet rider — lane edits no caliber file.

### once-S58 — CSV-injection defusing on every CSV surface

- **Queue rank:** 151 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-064
- **Prereqs:** output-S18, output-S22 (shared helper venue)

**Files:** src/Reporting/CsvReporter.php, src/Reporting/GitLabSastReporter.php, src/Reporting/CheckstyleReporter.php, tests/Unit/Reporting/CsvReporterTest.php
**Shared resources:** shared defuse helper (S22 adjacency) so formula-neutralization rows agree.
**Acceptance:** helper used by all CSV-emitting paths (grep census); fixture `=cmd|'/m'` + tab-leading + newline-in-name → escaped identically in all three outputs; trim-before-check (leading-space evasion unit).

### once-S61 — TUI layout: clamps that clamp, cell-aware widths, per-region clipping

- **Queue rank:** 152 of 247
- **Severity mix:** medium=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-once-070
- **Prereqs:** once-S06, once-S21

**Files:** src/Tui/PhpdupModel.php, src/Tui/ViewState.php, tests/Unit/Tui/ViewStateTest.php
**Shared resources:** PhpdupModel/ViewState chains; PTY probe via `script -qec` one-shots only.
**Acceptance:** 80x24 and 40x10 probes: no line exceeds region width (capture+awk census zero overflow rows), CJK label stays on its row, min() where floors were meant; unit-level width math vs candy width helpers.

### once-S62 — 't' sort affordance wired or withdrawn

- **Queue rank:** 153 of 247
- **Severity mix:** medium=1
- **Size:** MM (~20 min)
- **Covers:** php-dup-finder-once-071
- **Prereqs:** once-S61

**Files:** src/Tui/PhpdupModel.php, src/Tui/ViewState.php
**Acceptance:** keypress cycles sort (model state assertion of order change) OR help row removed; dead-code census: ViewState::clusterSort has a caller.

### once-S63 — TUI colors survive FlexBox sanitize (trusted-span seam)

- **Queue rank:** 154 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-072
- **Prereqs:** once-S61, once-S21

**Files:** src/Tui/PhpdupModel.php, src/Tui/ViewState.php
**Shared resources:** [external constraint: sugar-core Sanitize::untrusted owned outside this repo — no core edit; ship wrapper/segment model here]; colors vs escapes coupling w/ S21 scrub.
**Acceptance:** seam: theme styling applied at render-time outside the sanitized span (sugar Style composition), colors visible on >70% of previously-dead regions (PTY capture ANSI census up), control chars from S21 test STILL neutralized (both properties coexist — regression pair asserted).

### engine-S18b — Preprocess residency: int postings, gram interning, lowMemory default threshold — half B: gram dictionary/int-bag default + Block.php docblocks + cache-version roster + RSS re-measure.

- **Queue rank:** 155 of 247
- **Severity mix:** high=1
- **Size:** HH (~160 min)
- **Covers:** php-dup-finder-engine-011
- **Prereqs:** engine-S01, engine-S12, engine-S18a, engine-S32  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: gram dictionary/int-bag default + Block.php docblocks + cache-version roster + RSS re-measure. Sibling: engine-S18a — together the two legs replace engine-S18 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S18 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Extraction/Block.php` (:24-30 fields/docblocks), `src/Index/NgramInvertedIndex.php`
  (:42-50 int postings), `src/Fingerprint/NgramFingerprint.php` (master gram dictionary + int-bag
  default logic), `tests/Unit/Index/NgramInvertedIndexTest.php`, `tests/Unit/Fingerprint/NgramFingerprintTest.php`,
  `tests/Unit/Fingerprint/NgramFingerprintModeTest.php`; cross-group rows (report, do not edit):
  default-flip threading at `src/Pipeline/Stages/PreprocessStage.php` + `src/Parallel/PreprocessWorker.php`
  and IndexStore/ClusterCache key-version bumps (the finding routes the wiring decision to the shell group).
  Block.php collision with the canonical-typing question), engine-S32 (64-bit keys fix BEFORE making
  int bags the default — default must ship on the sound keyspace).
- **Shared resources:** persisted cache shapes — serialized Blocks carry ngram bags; changing key
  families MUST bump the IndexStore config-key/`ClusterCache` version (src/Persistence cross-group;
  name the exact roster files in the commit message) and docs/config-schema.json text is NOT affected;
  FingerprintIdMode roster (src/Fingerprint/FingerprintIdMode.php) shares the "bag mode" concept —
  align naming.
- **Acceptance:** unit: postings store int ids; dictionary interning returns stable keys; warm-run
  after cold-run with changed shapes yields identical candidates (cache-version actually bumps —
  IndexStoreStaleLabelsTest + new pin); 80k-block serial RSS re-measured on the reviewer's /tmp
  corpus recipe with disclosed before/after figure (not CI-timed); full suites green.


- **Phase-4 Outside memos:** ENGINE-PERF out-of-group 2 (int-bag default-flip threading lands at the PreprocessStage/PreprocessWorker wiring layer = shell group; cross-group rows stay report-only here) + output's (engine) PERF-24 peak-RSS 382 MB memo is this residency line.

### engine-S09 — APTED per-pair recomputation + unpruned DP at gate sizes (high)

- **Queue rank:** 156 of 247
- **Severity mix:** high=1
- **Size:** HH (~180 min)
- **Covers:** php-dup-finder-engine-009
- **Prereqs:** engine-S08b (memoize AFTER flatten semantics fixed), engine-S30 (popcount fixed before

- **Files:** `src/Similarity/AptedDistance.php` (similarity :59-107, ted :132-151 — spl_object_id-keyed
  memo for nodeCount/sketch/flatten tuple/worstCost; banded DP prune),
  `src/Similarity/TreeEditDistance.php` (grid allocation :139 territory),
  `tests/Unit/Similarity/AptedDistanceTest.php` (+ new memo-consistency test).
  memoizing sketch outputs — avoid pinning wrong sketches).
- **Shared resources:** none beyond files; per-run process-local memo (no cache keys touched).
- **Acceptance:** memoized vs unmemoized results identical (property test across fixture pairs);
  repeated similarity() calls on same block reuse cached inputs (object-identity probe); measured
  improvement note disclosed (symfony-scale /tmp corpus, not CI-timed); full suite + Golden unmoved.

### engine-S25 — Unsigned content-addressed cache inside scan roots (high, PoC-proven poisoning)

- **Queue rank:** 157 of 247
- **Severity mix:** high=1
- **Size:** HH (~180 min)
- **Covers:** php-dup-finder-engine-006
- **Prereqs:** engine-S24 (file chain; trust gate reads the final key derivation).

- **Files:** `src/Parsing/AstCache.php` (read-path trust gate), `src/Cli/Config.php:74`
  (cacheDir default posture), possibly `src/Cli/Command.php` / `docs/config-schema.json` /
  `docs/CLI.md` IF an explicit in-root opt-in flag is minted (six-site flag scaffold — shell-group
  adjacency), `tests/Unit/Parsing/AstCacheTest.php`, `tests/Unit/Cli/ConfigDefaultsExcludesTest.php`
  / `ConfigLoaderTest.php` (default flip fallout).
- **Shared resources:** docs/config-schema.json + roster if a new opt-in key exists (PerDirOverride
  belt: the opt-in MUST NOT be admitted per-directory); default-dir change silently relocates caches
  in CI (composite action caching rows = report-only); **[OWNER]:** relocating the default
  cache outside scan roots vs refuse-read-inside-root-without-flag is a UX-visible policy — Phase-5
  ruling row; TUI/serve code paths that assume `.phpdup-cache` presence need the same treatment (grep).
- **Acceptance:** regression mirrors SEC-01 PoC: tree containing committed `.phpdup-cache/` with a
  planted decoy → analysis reports the REAL code (decoy never served) and no fabricated function
  appears; explicit opt-in (whatever the ruling picks) restores old behavior; fork path
  (`--workers`) honors the gate; `vendor/bin/phpunit` full green incl. Integration CliSmokeTest.

### once-S11 — Supply chain: audit floor + reproducible releases

- **Queue rank:** 158 of 247
- **Severity mix:** high=2
- **Size:** DD (~180 min)
- **Covers:** php-dup-finder-once-014, php-dup-finder-once-015
- **Prereqs:** once-S10

**Files:** .github/dependabot.yml, .github/workflows/ci.yml, .github/workflows/release.yml, bench/run-all.sh, composer.json, .gitignore
**Shared resources:** composer.json (minimum-stability/dev-master sugarcraft block) — [OWNER: upstream tag freeze vs recorded hash compromise]; commit composer.lock OR publish dist hashes in release notes (choice row); ci.yml + release.yml serial rows.
**Acceptance:** dependabot YAML parses + reviews weekly+security for github-actions and composer; `composer audit` job green leg in CI (weekly cron separate); run-all.sh has no `--no-audit` + npm gets `--ignore-scripts`; minimum-stability stable + all 16 dev-master deps pinned (tags or SHAs) — fresh clone `composer install` succeeds offline-ish proof on /tmp copy; build-phar buildTime repro note (date() stamped in metadata file, not in artifact bytes).

### once-S12 — Install paths that actually work (phar assets + composer story)

- **Queue rank:** 159 of 247
- **Severity mix:** high=2
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-010, php-dup-finder-once-016
- **Prereqs:** once-S05, once-S11, shell-S35

**Files:** README.md, .github/workflows/release.yml, src/Cli/UpdateCommand.php, docs/RELEASE.md
**Shared resources:** release.yml asset-attach w/ S05/S11 serial; README install block; UpdateCommand fallback parser parity (same code path S05 rewired).
**Acceptance:** sha256 sidecar written as `<hash>  phpdup.phar` (two spaces) so README's `sha256sum --check` works verbatim — manual check row: dry-run format in bash on /tmp pair; release job attaches artifact to the tag (no more assets:[]); README install text == workflow behavior; composer require path DE-recommended with honest note until S11 stabilizes deps (or re-enabled if tags landed).

### once-S91 — Update download truth (status codes, truncation, backup lifecycle)

- **Queue rank:** 160 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-114
- **Prereqs:** once-S05, once-S12, shell-S36  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/UpdateCommand.php, tests/Unit/Cli/UpdateCommandTest.php, docs/RELEASE.md
**Shared resources:** UpdateCommand 4th writer order (S05→S12→S91→S99); RELEASE.md w/ S05/S12 (append rows).
**Acceptance:** fetchJson checks HTTP status (404/5xx surfaced w/ Retry-After honored); downloadFile validates declared length (truncation = failure path tested by fake server via stream-socket helper, no network group); backup preserved until smoke passes ('Rollback automatic' inverted → honest order); token auth option for private repos documented-or-absent.

### once-S14 — Ship CI integrations that can run: materialize the tool

- **Queue rank:** 161 of 247
- **Severity mix:** high=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-017
- **Prereqs:** once-S12 (downloadable asset exists), once-S10 (ext set truth)

**Files:** .github/actions/phpdup/action.yml, .gitlab/phpdup-ci.yml, docs/CI.md
**Shared resources:** action.yml serial after S15; .gitlab file shared w/ S79 (gitlab leg last); docs/CI.md drop-in claim rows (S95 final polish).
**Acceptance:** action checkout+php-setup then materializes bin/phpdup (composer install of a pinned release zip OR phar download) BEFORE executing; GitLab template does the same + image gains gmp+mbstring (or uses setup step); YAML parses; manual check row: local runner dry-run plan documented; exit code from phpdup mapped, tool-missing → hard error (not silent skip).

### once-S31 — Phar build integrity: shebang, contents, stub story, export-ignore

- **Queue rank:** 162 of 247
- **Severity mix:** high=1 medium=3
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-035, php-dup-finder-once-096, php-dup-finder-once-097, php-dup-finder-once-098
- **Prereqs:** shell-S35 (init-in-stub decision), once-S11 (repro stamping)

**Files:** build-phar.php, box.json, .gitattributes, bin/phpdup
**Shared resources:** box.json [OWNER: delete vs wire — box-stub.php phantom name = 035 root cause]; phar contents list shared w/ S56 CI gate; .gitattributes new file (composer.json untouched here).
**Acceptance:** `/tmp/build/phpdup.phar` first line `#!/usr/bin/env php` (execl test: `./phpdup.phar --version` exit 0 — was ENOEXEC); phar contains profiles/, bin/phpdup-worker, init command reachable, license texts via explicit allow-list not `*licenses*` strip; `git archive HEAD | tar -t` (with new .gitattributes) excludes tests/bench/logo/docs leftovers, `eol=lf` on bin; box.json deleted (build-phar is single source) OR wired — per ruling, docs updated to truth.

### once-S30 — Version identity single-sourced end to end

- **Queue rank:** 163 of 247
- **Severity mix:** high=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-034
- **Prereqs:** output-S03 (Util/Version core), shell-S35, once-S31 (build stamping)

**Files:** bin/phpdup, build-phar.php, src/Reporting/JsonReporter.php, src/Reporting/SarifReporter.php, src/Reporting/GitLabSastReporter.php, src/Reporting/TimeseriesReporter.php, .github/workflows/ci.yml (smoke leg → S56)
**Shared resources:** bin/phpdup w/ S19; reporters are output-chain venues (post S02/S03/S05); downgrade guard lives in S99 (cross-row cited here).
**Acceptance:** disk drift noted: bin/phpdup currently has NO VERSION const — step introduces build-time stamp from git tag into phar metadata only; all reporters emit `Version::STRING`; `--version` == phar metadata == report `phpdup_version` (fixture run on /tmp copy); CI smoke leg compares all three (full job owned S56).


- **Phase-4 Outside merge:** output's (once, shell-adjacent) row — src/Cli/UpdateCommand.php:487 '0.0.0' fallback literal joins the single-source version family (Util/Version from output-S03 feeds it).

### once-S56 — CI artifact gate: the built phar actually runs

- **Queue rank:** 164 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-062
- **Prereqs:** once-S31 (phar integrity), once-S05 (sign story), once-S30 (version check)

**Files:** .github/workflows/ci.yml
**Shared resources:** ci.yml slot after S10→S11→S93.
**Acceptance:** new job: build → `php -d phar.readonly=0`-free exec `php phpdup.phar --version` + analyze /tmp fixture + JSON-compare vs golden (read-only compare, never UPDATE_SNAPSHOTS); missing/failed phar fails the job (proves today's phantom pass closes).

### once-S99 — --pre meaningful, downgrade refused, update test seam real

- **Queue rank:** 165 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-124
- **Prereqs:** once-S05, once-S30, once-S91, shell-S36  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/UpdateCommand.php, tests/Unit/Cli/UpdateCommandTest.php, docs/RELEASE.md
**Shared resources:** UpdateCommand chain final writer (S91→S99); --releases listing endpoint usage [OWNER: list-only vs remove --pre — default remove].
**Acceptance:** downgrade attempt (target < current per version_compare, echo honest tag) → refuse w/ exit 2; if --pre kept: /releases endpoint lists prereleases correctly; UpdateCommandTest refactored to drive real execute() path w/ injected fake transport (109-367 covered); help prose = shipped behavior (no phantom tarball/public-key claims).

### once-S86 — bench corpus supply chain + fuzz measures something

- **Queue rank:** 166 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-108
- **Prereqs:** once-S11 (audit posture), engine-S01 (corpus floor relationship), once-S85 (results re-render)

**Files:** bench/run-all.sh, bench/corpus-pins.txt, bench/corpora, tests/Fuzz/DetectionRateTest.php
**Shared resources:** corpus GT format w/ S09; home-my pin staleness row [owner box — re-pull or note].
**Acceptance:** run-all.sh pins phpcpd by version+SHA256 (verified at extract, mismatch aborts), npm `--ignore-scripts`; digest mismatch FAILS corpus materialization (advisory→enforced); GT absolute /home/sites paths → relative; DetectionRateTest asserts a real recall floor (non-vacuous: mutation check — break extractor, test reddens).

### once-S94 — Async serve actually tested end to end

- **Queue rank:** 167 of 247
- **Severity mix:** medium=1
- **Size:** DD (~180 min)
- **Covers:** php-dup-finder-once-117
- **Prereqs:** once-S02, once-S03, once-S42, once-S46, once-S73  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** tests/Unit/Server/ApplicationTest.php, tests/Unit/Server/AnalysisWorkerTest.php, tests/Unit/Server/JobQueueTest.php, tests/Unit/Watch/WatchRunnerTest.php
**Shared resources:** Server test files (S01-S94 ordering: land features first); loopback bind discipline (ephemeral ports).
**Acceptance:** live-subject battery on 127.0.0.1:0: /jobs async happy-path completes w/ real result bytes, /jobs/{id} states observed, /api/jobs list/shape, failure worker (kill -9 mid) → honest status, /analyze payload exactOnly fixed (S03), 413/400/403 legs from S42 — every async route at least once end-to-end; WatchRunnerTest asserts a REAL re-analysis (event → job-count moves).

### output-S31 — PatternRecognizer tag honesty + missing per-tag scenarios

- **Queue rank:** 168 of 247
- **Severity mix:** medium=1 low=2
- **Size:** HH (~180 min)
- **Covers:** php-dup-finder-output-021, php-dup-finder-output-052, php-dup-finder-output-064
- **Prereqs:** none

- Covers: 021, 064, 052 (PatternRecognizer.php:9 Hole-import leg)
- Files: `src/Refactor/PatternRecognizer.php` (all six numbered items: :96-106, :151-157, :162-173, :368-401, :463-471, docblocks :44-69/:289-298/:485), `tests/Unit/Refactor/PatternRecognizerTest.php` (incl. 064's two scenarios + null-AST fail-closed case), `tests/Unit/Reporting/SafetyScorerTest.php` (cross-check only — its fabricated tags stay test-local)
- Size: HH (180 min)
- Prereqs: none — but before S32 (same file).
- Conflicts: pattern_tags are JSON/golden payload: run `--testsuite Golden` FIRST (expect zero-move on notify/sql/optional corpora if their current tags are the honest ones — e.g. sql-exact `sql-builder` tag is the concat+query arm, which S31 preserves; if a tag moves, regen+disclose under the S27-bumped schema state, single new golden delta commit reviewed line-by-line).
- **Phase-5 r1 (I10/D4):** Golden-suite verification runs WITHOUT the `tests/Golden/` token — this lane writes no snapshot; any witnessed movement regens under the regen-window law (disclosed, line-by-line).
- Acceptance: `--filter PatternRecognizerTest` — null-AST member gets no semantic tags; `switch` with non-uniform arms NOT tagged state-machine; "loaded from cache" string alone NOT tagged sql-builder; `thread()`/`preselect()` NOT crud; URL-only config body NOT http-call (URL must be call argument); header docblock == tag() inventory; the two new per-tag scenarios (builder chain; state switch) pass; GATE.

### output-S32 — PatternRecognizer + SolidAnalyzer: one collecting visitor per member

- **Queue rank:** 169 of 247
- **Severity mix:** medium=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-042
- **Prereqs:** output-S31  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 042
- Files: `src/Refactor/PatternRecognizer.php` (≈10 NodeFinder sites), `src/Architecture/Analyzers/SolidAnalyzer.php` (:54-83), new shared collector (private struct acceptable), tests: `PatternRecognizerTest`, `tests/Unit/Architecture/ArchitecturalAnalyzersTest.php`
- Size: HH (150 min)
- Prereqs: S31 (same file).
- Acceptance: tags and SRP findings byte-identical on all fixture runs (`--filter PatternRecognizerTest ArchitecturalAnalyzersTest`); `grep -c "new NodeFinder" src/Refactor/PatternRecognizer.php` → 0 (or documented single retained site); traversal-count spy test optional; goldens byte-identical; GATE.

### output-S33 — analyzer honesty: mixed≠primitive, unions, God/Factory/Decorator claims, SRP verbs, twin line calc

- **Queue rank:** 170 of 247
- **Severity mix:** medium=1 low=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-output-034, php-dup-finder-output-055
- **Prereqs:** output-S32  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 034, 055
- Files: `src/Architecture/Analyzers/AntiPatternAnalyzer.php` (:24, :51-60, :13 docblock), `src/Architecture/Analyzers/DesignPatternAnalyzer.php` (:18 docblock + Decorator), `src/Architecture/Analyzers/SolidAnalyzer.php` (verb matching → word-boundary/AST-name), `src/Reporting/DeclaredTwinAnalyzer.php` (:251 occurrence-aware lines), tests: `tests/Unit/Architecture/ArchitecturalAnalyzersTest.php`, `tests/Unit/Reporting/DeclaredTwinAnalyzerTest.php`
- Size: HH (120 min)
- Prereqs: S32 (SolidAnalyzer rework consumed), S34 optional-ordering note with DeclaredTwin file (either first; chain S33→S34).
- Acceptance: `--filter ArchitecturalAnalyzersTest DeclaredTwinAnalyzerTest` — all-UNKNOWN-hole cluster no longer fires Primitive Obsession (fires "all holes untyped" posture); union `int|string` tested whole; `login()` not flagged 'log'; duplicate @phpdup-ignore lines get distinct correct line numbers; God/Factory/Decorator docblocks match code; architecture findings are NOT golden payload → goldens byte-identical; GATE.

### output-S34 — DeclaredTwinAnalyzer: comment-level scan + shared maps, no full-AST deserialize per run

- **Queue rank:** 171 of 247
- **Severity mix:** high=1 medium=1
- **Size:** HH (~180 min)
- **Covers:** php-dup-finder-output-017, php-dup-finder-output-028
- **Prereqs:** output-S33  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 017, 028
- Files: `src/Reporting/DeclaredTwinAnalyzer.php` (:92-118, :325-369, wiring :189-203), `src/Pipeline/Stages/ReportStage.php` (:174 wiring only), `tests/Unit/Reporting/DeclaredTwinAnalyzerTest.php`
- Size: HH (180 min)
- Prereqs: S33 (same file, line calc first). Deliberate NO-new-flag ruling (six-edit-site avoidance): fix = token-level claim harvest + build path/symbol maps once; parsed results may repopulate AstCache (write-back) per the finding's menu, decision recorded in commit.
- Acceptances: `--filter DeclaredTwinAnalyzerTest` — identical claim verdicts on fixtures; `--no-cache` run witness: report stage makes zero `parseFile` calls from this analyzer (spy/counting subclass); no new CLI flag; goldens byte-identical; GATE.

### engine-S29 — IR lifter: elseif chain dropped when else present; switch/if token split-brain; catch-all laundering

- **Queue rank:** 172 of 247
- **Severity mix:** medium=2 low=1
- **Size:** HH (~210 min)
- **Covers:** php-dup-finder-engine-024, php-dup-finder-engine-025, php-dup-finder-engine-041
- **Prereqs:** none

- **Files:** `src/Ir/IrLifter.php` (:54-60, :85-100, :140-158, :403-406, liftElseIfChain region),
  `tests/Unit/Ir/IrLifterTest.php`.
- **Shared resources:** IR token vocabulary changes (`==`→`equal`, arms present) — the irBag is
  persisted inside cached Blocks → fold into S18/S23's version-roster concern (note: if S18's
  roster bump already shipped, no extra bump needed; else add `src/Fingerprint/FingerprintIdMode.php`-
  aligned token); ir scorer is opt-in (`--scorer=ir`), goldens unmoved — verify IrTierClustererTest.
- **Acceptance:** pins: `if/elseif/else` keeps all arms (finding's exact three-arm repro lifts a
  branch with q() present); switch vs its if-chain form produce equal IR token streams for the
  canonical example (post-default case included — pin the PHP-fallthrough-semantics version);
  `catch (\Throwable)` narrowed to `\Error|\TypeError` + refusal counter observable (unit asserts
  an injected \Exception is NOT swallowed); `--filter 'Ir|Clusterer'` green; Fuzz suite unmoved.

### engine-S03 — Strict-mode leaks (match/switch + `__FN` wipe) and block-extractor gate honesty

- **Queue rank:** 173 of 247
- **Severity mix:** medium=1 low=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-engine-036, php-dup-finder-engine-057
- **Prereqs:** engine-S02b (same file Normalizer.php — mode gating must land on the redesigned

- **Files:** `src/Normalization/Normalizer.php` (leaveNode :219-235, enterNode gate :237-259,
  canonicalizeVariables `__FN` branch), `src/Extraction/BlockExtractor.php` (:229-233 exemption +
  :27-28 docblock), `src/Index/BlockIndex.php` (docclaim vs symlink reality),
  `src/Refactor/PatternRecognizer.php` (trivial-accessor tag — cross-group file, add-pattern-tag
  skill pattern) + `tests/Unit/Refactor/PatternRecognizerTest.php`,
  `tests/Unit/Normalization/NormalizerTest.php`, `tests/Unit/Extraction/BlockExtractorTest.php`.
  canonicalizeVariables; one-writer chain).
- **Shared resources:** PatternRecognizer tag roster (new tag string reaches JSON — values-only, no
  schema bump); aggressive-mode goldens: default-mode behaviour intentionally UNCHANGED — verify no
  golden drift (if 057's arrow-exemption adds blocks to a fixture, regen + disclose).
- **Acceptance:** strict mode: match-vs-switch pair no longer EXACT (unit asserts distinct canonical
  streams under `--mode strict`); two same-shape differently-named methods not EXACT under strict;
  aggressive+default streams byte-unchanged (diff check); small arrow-function declaration survives
  min-size filter (unit); getter cluster carries `trivial-accessor` tag (unit); `--filter
  'Normalizer|BlockExtractor|PatternRecognizer|BlockIndex'` green.

### engine-S04 — copyNode reflection cost + inert --low-memory pooling

- **Queue rank:** 174 of 247
- **Severity mix:** high=1 medium=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-engine-008, php-dup-finder-engine-030
- **Prereqs:** engine-S03 (file chain); benefits from S02 being in (leafKey must not fight the new

- **Files:** `src/Normalization/Normalizer.php` (copyNode :144-169, applyNodePool :113-137),
  `src/Util/CanonicalNodePool.php`, `tests/Unit/Util/CanonicalNodePoolTest.php`,
  `tests/Unit/Normalization/NormalizerTest.php`,
  `tests/Unit/Clustering/TedSizeGateTest.php`/`BlockAstLoaderTest` as regression riders (pool is
  shared via BlockAstLoader mirrors).
  sentinel numbering — same authors ideally).
- **Shared resources:** pooled-leaf identity must NOT alias line-bearing attributes (finding's
  caveat — pinning this is part of acceptance; affects --apply/JSON line reporting).
- **Acceptance:** pooling actually splices: two identical leaves under `--low-memory` share
  `spl_object_id` and the parent child-slot references the pooled instance (regression pin the
  finding asks for); pooled key includes attributes/startLine (distinct-line leaves NOT aliased —
  line reporting pin); copyNode ~5× micro-bench note disclosed (no CI timing assert); full Unit
  green; goldens unmoved (stream equality property check).

### engine-S05 — PHP-8.1-floor convention: drop return types on three leaveNode overrides

- **Queue rank:** 175 of 247
- **Severity mix:** medium=1
- **Size:** MM (~15 min)
- **Covers:** php-dup-finder-engine-039
- **Prereqs:** engine-S04 (final Normalizer shape), engine-S06b (DbOpCanonicalizer), engine-S07

- **Files:** `src/Normalization/Normalizer.php:219`, `src/Normalization/TrinityCollapser.php:98`,
  `src/Normalization/DbOpCanonicalizer.php:90`, their three unit tests (behavior must be identical).
  (TrinityCollapser) — the three edited functions' owners land first.
- **Shared resources:** none (pure signature deletions; `return null` is a no-op per NodeVisitorAbstract).
- **Acceptance:** `grep -n 'function leaveNode(Node $node):' src/Normalization/*.php` → zero hits;
  `--filter 'Normalizer|Trinity|DbOp'` suites green; phpstan+psalm clean.

### once-S24 — low-memory honesty + NormalizationPlugin per-mode execution

- **Queue rank:** 176 of 247
- **Severity mix:** high=2
- **Size:** HH (~75 min)
- **Covers:** php-dup-finder-once-027, php-dup-finder-once-029
- **Prereqs:** shell-S16, engine-S05 (Normalizer chain tail)

**Files:** src/Normalization/Normalizer.php, src/Normalization/NormalizationPlugin.php, docs/CLI.md, src/Cli/Command.php (help text)
**Shared resources:** Normalizer NOT touched after engine chain; CLI.md leg; AGENTS.md plugin-contract prose = REPORT-ONLY row (S80).
**Acceptance:** unit: registered plugin VISITS in all three modes (incl the early-return path — the loop relocates); `$mode` param used or dropped from interface (interface docblock truth); --low-memory help/CLI.md states 'may reduce detection sensitivity' + 159→92 disclosure example; cache-key tie to S23 test updated.

### engine-S16a — BlockAstLoader: O(B·file) walks + O(C·L) re-normalizations in the rebase pass — half A: single-walk findStrict + NodeTraverser::STOP_TRAVERSAL at match sites + batched span collection.

- **Queue rank:** 177 of 247
- **Severity mix:** high=1 medium=2
- **Size:** HH (~140 min)
- **Covers:** php-dup-finder-engine-031, php-dup-finder-output-015, php-dup-finder-output-040
- **Prereqs:** engine-S04  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: single-walk findStrict + NodeTraverser::STOP_TRAVERSAL at match sites + batched span collection. Sibling: engine-S16b — together the two legs replace engine-S16 (280 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S16 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Extraction/BlockAstLoader.php` (:281-328 findStrict, :507-525 candidateMatches,
  :720-735 escalation), `tests/Unit/Extraction/BlockAstLoaderTest.php`, `tests/Fixtures/` only if a
  new multi-block file fixture is warranted.
  re-does — build the spl_object_id memo once, over the final clone machinery).
- **Shared resources:** none; relocation semantics unchanged (040 next in file).
- **Acceptance:** unit: resolve() on a file with B blocks walks the file once (probe via counting
  visitor or memo identity); candidate's canonical hash computed once per poolKey (memo identity
  unit); same rebase outcomes on BlockAstLoaderTest + RefactorTestSkeletonCensusTest (Integration)
  green; goldens unmoved.


- **Phase-4 merge (absorbs output-S23 + output-S24 — output-015, output-040):** engine-031, output-015 and output-040 are the same BlockAstLoader defect family (walk the file once; STOP_TRAVERSAL at match sites; memoize candidate normalize+hash per pass keyed by spl_object_id). The output annotator's own Outside row asked for exactly this: 'Phase-4 must give ONE lane this file'. Inherit the absorbed acceptance legs: NodeTraverser::STOP_TRAVERSAL present at match sites; resolution vector pin (strict + reanchored results identical); batched span collection for liveCandidateNodes (per-file, not per-node); memoized match decisions over the duplicate-hash-collision fixture; normalize() call counter drops on the multi-class rebase fixture; --testsuite Integration + Golden byte-identical; counting-visitor visit reduction. Refactor/apply lanes keep reading the loader's existing public API unchanged (no signature change). Size rises HH→DD (est 280) as one coherent rewrite.

### engine-S16b — BlockAstLoader: O(B·file) walks + O(C·L) re-normalizations in the rebase pass — half B: spl_object_id candidate memo per pass + normalize()-count drops + census pins (memo over the final clone machinery).

- **Queue rank:** 178 of 247
- **Severity mix:** high=1 medium=2
- **Size:** HH (~140 min)
- **Covers:** php-dup-finder-engine-031, php-dup-finder-output-015, php-dup-finder-output-040
- **Prereqs:** engine-S04, engine-S16a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: spl_object_id candidate memo per pass + normalize()-count drops + census pins (memo over the final clone machinery). Sibling: engine-S16a — together the two legs replace engine-S16 (280 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S16 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Extraction/BlockAstLoader.php` (:281-328 findStrict, :507-525 candidateMatches,
  :720-735 escalation), `tests/Unit/Extraction/BlockAstLoaderTest.php`, `tests/Fixtures/` only if a
  new multi-block file fixture is warranted.
  re-does — build the spl_object_id memo once, over the final clone machinery).
- **Shared resources:** none; relocation semantics unchanged (040 next in file).
- **Acceptance:** unit: resolve() on a file with B blocks walks the file once (probe via counting
  visitor or memo identity); candidate's canonical hash computed once per poolKey (memo identity
  unit); same rebase outcomes on BlockAstLoaderTest + RefactorTestSkeletonCensusTest (Integration)
  green; goldens unmoved.


- **Phase-4 merge (absorbs output-S23 + output-S24 — output-015, output-040):** engine-031, output-015 and output-040 are the same BlockAstLoader defect family (walk the file once; STOP_TRAVERSAL at match sites; memoize candidate normalize+hash per pass keyed by spl_object_id). The output annotator's own Outside row asked for exactly this: 'Phase-4 must give ONE lane this file'. Inherit the absorbed acceptance legs: NodeTraverser::STOP_TRAVERSAL present at match sites; resolution vector pin (strict + reanchored results identical); batched span collection for liveCandidateNodes (per-file, not per-node); memoized match decisions over the duplicate-hash-collision fixture; normalize() call counter drops on the multi-class rebase fixture; --testsuite Integration + Golden byte-identical; counting-visitor visit reduction. Refactor/apply lanes keep reading the loader's existing public API unchanged (no signature change). Size rises HH→DD (est 280) as one coherent rewrite.

### engine-S17a — Relocation-miss hard failure on a mainstream corpus (needs-repro) — half A: hint/anchor + escalation→always-skip+note in BlockAstLoader + unit pins (no opt-in flag).

- **Queue rank:** 179 of 247
- **Severity mix:** medium=1
- **Size:** HH (~140 min)
- **Covers:** php-dup-finder-engine-040
- **Prereqs:** engine-S16b  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: hint/anchor + escalation→always-skip+note in BlockAstLoader + unit pins (no opt-in flag). Sibling: engine-S17b — together the two legs replace engine-S17 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S17 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Extraction/BlockAstLoader.php` (hint/anchor logic + escalation throw ~:732-740),
  `tests/Unit/Extraction/BlockAstLoaderTest.php`, `tests/Integration/` (end-to-end probe).
- **Shared resources:** report surface: a degrade-to-skip adds a report/skip note (DebugLogger /
  skipNotes wording — coordinate with shell group flag-plumbing if a new opt-in controls strictness;
  prefer silent-loud: always skip + note).
- **Acceptance:** root-cause first: `bin/phpdup analyze vendor/nikic/php-parser vendor/symfony`
  (the repo's own vendor tree IS the finding's corpus family) either relocates everything or emits
  the located-degrade note and exits 0 with a complete report — no uncaught RuntimeException; a
  unit pin reproduces the degenerate single-line `kind if` hint (Php7.php shape) from a checked-in
  mini-fixture or generated string; disclose run transcript.

### engine-S34a — AstSerializer prefix-free stream + property tests — half A

- **Queue rank:** 180 of 247
- **Severity mix:** medium=1
- **Size:** HH (~140 min)
- **Covers:** php-dup-finder-engine-037
- **Prereqs:** engine-S02b, engine-S04, engine-S25  *(Phase-4 adjustment + Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5+I1):** owns the stream redesign + its pins only: prefix-free `s:<len>:<raw>` incl. `L:` segments in `src/Util/AstSerializer.php` (scalarPart :111-116); the PD-07/FUZZ-04 collision pairs as pins; property test: distinct token lists ⇒ distinct streams (random strings containing \x1E \x1F | # and UTF-8 edges).

**Files:** `src/Util/AstSerializer.php`, `tests/Unit/Util/AstSerializerTest.php`, `tests/Unit/Fingerprint/NgramFingerprintTest.php`.
**Shared resources:** every structuralHash downstream of the stream format — the roster bump + golden re-pin are the sibling half (engine-S34b); never split one golden regen across halves.
**Acceptance:** the two published collision pairs now hash differently (unit); round-trip property test green; no cache/golden writes in this half.

### engine-S17b — Relocation-miss hard failure on a mainstream corpus (needs-repro) — half B: vendor probe transcript + tests/Integration e2e + locate run exit 0.

- **Queue rank:** 181 of 247
- **Severity mix:** medium=1
- **Size:** HH (~160 min)
- **Covers:** php-dup-finder-engine-040
- **Prereqs:** engine-S16b, engine-S17a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: vendor probe transcript + tests/Integration e2e + locate run exit 0. Sibling: engine-S17a — together the two legs replace engine-S17 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to engine-S17 denote the leg pair. Execute only the legs assigned to this half.

- **Files:** `src/Extraction/BlockAstLoader.php` (hint/anchor logic + escalation throw ~:732-740),
  `tests/Unit/Extraction/BlockAstLoaderTest.php`, `tests/Integration/` (end-to-end probe).
- **Shared resources:** report surface: a degrade-to-skip adds a report/skip note (DebugLogger /
  skipNotes wording — coordinate with shell group flag-plumbing if a new opt-in controls strictness;
  prefer silent-loud: always skip + note).
- **Acceptance:** root-cause first: `bin/phpdup analyze vendor/nikic/php-parser vendor/symfony`
  (the repo's own vendor tree IS the finding's corpus family) either relocates everything or emits
  the located-degrade note and exits 0 with a complete report — no uncaught RuntimeException; a
  unit pin reproduces the degenerate single-line `kind if` hint (Php7.php shape) from a checked-in
  mini-fixture or generated string; disclose run transcript.

### engine-S34b — Cache-identity roster bump + golden re-pin — half B

- **Queue rank:** 182 of 247
- **Severity mix:** medium=1
- **Size:** HH (~160 min)
- **Covers:** php-dup-finder-engine-037
- **Prereqs:** engine-S02b, engine-S04, engine-S25, engine-S34a  *(Phase-4 adjustment + Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I1+I5+I8 — GOLDEN-STEP):** owns the cache-identity roster bump + the three golden re-pins: structural-hash stream change ⇒ every cache identity changes — bump required. `config/CacheKey/FingerprintIdMode.php` was a phantom path (FingerprintIdMode knob lives in `src/Cli/Config.php`/`ConfigLoader`; the roster home is `src/Fingerprint/`) — corrected at Phase-5 r1.

**Files:** `.phpdup-cache-key.version` + `src/Fingerprint/FingerprintIdMode.php` roster, `tests/Unit/Cli/FingerprintIdModeConfigTest.php`, `tests/Golden/` (notify-exact, sql-exact, optional-near-dups regen with disclosed diffs), `tests/Unit/Util/AstSerializerTest.php` (final-state re-run).
**Shared resources:** EVERY golden + IndexStore/ClusterCache key families (cross-group roster bump) + fuzz corpus hashes; run after engine-S02b so one combined re-pin event lands, not two.
**Acceptance:** cold-cache + warm-cache analyze of src/ gives identical report; all three goldens regen with disclosed diffs (fixture literals clean → cluster *structure* unchanged; ids shift); Fuzz suite green; roster files carry the new version token.

### once-S87 — Duplicate/overlapping roots deduped; shuffle byte-identity fence

- **Queue rank:** 183 of 247
- **Severity mix:** medium=1
- **Size:** HH (~75 min)
- **Covers:** php-dup-finder-once-109
- **Prereqs:** engine-S17b, engine-S34b, engine-S38, shell-S38  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Pipeline/Stages/ScanningStage.php, src/Util/PathCanonical.php, src/Extraction/BlockAstLoader.php, tests/Unit/Extraction/BlockAstLoaderTest.php, tests/Unit/Util/AstSerializerTest.php
**Shared resources:** PathCanonical w/ S40; shuffle-tree sentinel shared w/ S102 (S87 lands, S102 verifies); 'no double-count' census.
**Acceptance:** `analyze src src --json`: no duplicate block ids + no BlockAstLoader 'could not relocate' 255-crash (dedupe by realpath; overlapping prefix warns w/ census); deterministic shuffle fixture → identical normalized bytes + ids (unit golden-free, in-test compare).

### engine-S11 — Clusterer bookkeeping perf: edge buckets, medoid counters, strcmp/micro, dead var

- **Queue rank:** 184 of 247
- **Severity mix:** medium=1 low=3
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-engine-029, php-dup-finder-engine-043, php-dup-finder-engine-044, php-dup-finder-engine-052
- **Prereqs:** engine-S13 (file chain: UnionFind guards land before the loop that feeds them is

- **Files:** `src/Clustering/Clusterer.php` (:277-304 generator keys, :327/:344 dead var, :382-408
  union pass bucketing, :668-689 pickMedoid, :795-814 buildPairsFromEdges + :302-304 docblock),
  `tests/Unit/Clustering/ClustererTest.php`, `ClustererGeneratorTest.php`, `ClustererMedoidSplitTest.php`,
  `ClustererIdDeterminismTest.php`.
  rebuilt).
- **Shared resources:** cluster-id determinism (`contentClusterId` must stay stable — S11 changes
  emission plumbing, NOT membership; pin via ClustererIdDeterminismTest + goldens unmoved).
- **Acceptance:** identical cluster sets + identical ids on fixtures pre/post (Golden suite green,
  no regen); buildPairsFromEdges is O(edges) (code-read + chain-shaped synthetic component in a
  unit where edge count ≪ k² and output equal to old behavior); medoid round picks max-remaining-
  degree counter result equal to old O(k²) scan (property unit); no behavioral field changes → goldens
  byte-identical (this step must PROVE non-drift, unlike its neighbors).

### engine-S14 — ContainmentSimilarity double-pass on the Jaccard-reject branch

- **Queue rank:** 185 of 247
- **Severity mix:** medium=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-engine-035
- **Prereqs:** engine-S11 (Clusterer chain; consumer line lives where S11's edge work reads).

- **Files:** `src/Similarity/ContainmentSimilarity.php`, `src/Clustering/Clusterer.php:735-737`
  (consumer switch to combined return), `tests/Unit/Similarity/ContainmentSimilarityTest.php`.
- **Shared resources:** none (value-preserving refactor; goldens unmoved — verify).
- **Acceptance:** one-pass method returns identical (containment, sizeRatio) tuples vs old two-pass
  (property test on random int bags, empty-bag edge cases); `--filter 'Containment|Clusterer'` green;
  Golden byte-identical.

### engine-S15 — Tier honesty: never-emitted MatchTier::Ted + TED-reject blocks lower lenses; exact-clone label loss

- **Queue rank:** 186 of 247
- **Severity mix:** low=2
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-engine-054, php-dup-finder-engine-056
- **Prereqs:** engine-S14 (Clusterer chain), engine-S01 (compare against the live optional golden).

- **Files:** `src/Clustering/Clusterer.php` (cascade :695-760, labels :457-470/:621-645),
  `src/Clustering/MatchTier.php` (:24), `tests/Unit/Clustering/TedSizeGateTest.php`,
  `IrTierClustererTest.php`, `MlPairTierClustererTest.php`, `ClustererTest.php`,
  `tests/Golden/optional-near-dups.json` (matchTier/exact fields in pairs may shift — GOLDEN-MOVES-likely).
  Both changes alter what `--scorer=ir` / `--ml-pair-url` see, so the existing Ir/Ml tier clusterer
  tests need re-reading, not just re-running.
- **Shared resources:** tests/Golden (near-dup pair labels); JSON pairs already carry exact/matchTier
  keys → values-only, SCHEMA_VERSION unmoved.
- **Fix note:** 054's min(a,b) re-anchoring was struck as non-issue by consolidators (see
  Verified list); what remains is the exact-sub-bucket emission + triadic-closure consideration —
  implement the emission half; leave triadic closure as a documented non-goal (scope guard).
- **Acceptance:** unit: a Jaccard-pass + TED-fail pair is now evaluated by containment/IR/ML arms
  (with `--scorer=ir` it can mint an edge; previously dead); MatchTier::Ted emitted on
  Jaccard→TED-confirmed path (or case deleted — pick per product story, disclose); component
  containing an ExactHash-stamped pair reports the exact tier (unit from finding's ride-in-90%
  scenario); Golden regen disclosed; `vendor/bin/phpunit --testsuite Fuzz` green (label distribution).

### engine-S10 — No wall-time/aggregate bound on the TED tier (high) + cap default review

- **Queue rank:** 187 of 247
- **Severity mix:** high=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-engine-010
- **Prereqs:** engine-S09, engine-S15  *(Phase-4 adjustment; see Merged & re-routed)*

  already executed in S09 context — final number decided HERE since the const lives here)
- **Files:** `src/Clustering/Clusterer.php` (scorePairsSerially accumulator + throw reusing
  CandidateBudgetException :281-285 posture; `DEFAULT_MAX_TED_SIZE` const :157),
  `src/Cli/Config.php:238` (default 800 mirrors const — keep in sync),
  `tests/Unit/Clustering/GenerationBudgetTest.php`, `tests/Unit/Clustering/TedSizeGateTest.php`.
  engine-S15 (Clusterer one-writer chain position). **[OWNER]:** the aggregate-budget
  default (and whether lowering DEFAULT_MAX_TED_SIZE 800→300-400 is a behavior change customers
  feel) deserves a Phase-5 ruling row; a NEW user-facing flag would trigger the six-site CLI-flag
  scaffold + docs/config-schema.json + docs/CLI.md (shell group) — prefer internal default.
- **Shared resources:** goldens (if max-TED default lowered, near results may shift — GOLDEN-MOVES-possible);
  exit-code contract: reuses the existing loud `phpdup:` error-line render of CandidateBudgetException
  (Command::execute) — no exit-contract change.
- **Acceptance:** unit: with a small injected cap, a storm of large near-identical blocks trips the
  exception with cost in the message; the finding's pathological corpus (regenerate a 47KB/3k-nested-if
  file under /tmp) now terminates under the default knobs in bounded time with the loud refusal
  (previously >400s rc=124) — transcript disclosed; `--filter 'Budget|TedSize|Clusterer'` green.


- **Phase-4 Outside context:** shell's (engine) row 'ClusterStage APTED cost ~1,241 s on the saturated synthetic corpus' is sized INTO this step's wall-time budget (post-S09 memo costs).

### once-S102 — Residual audit: RefactorStage merge parity + PsalmTypeProvider per ruling

- **Queue rank:** 188 of 247
- **Severity mix:** low=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-127
- **Prereqs:** engine-S10, engine-S31c, once-S31, once-S87, output-S02  *(Phase-4 adjustment; Phase-5 r1 I10: `tests/Golden/` token removed — verification only, no regen here; zero-move proof lives in acceptance)*

**Files:** src/Pipeline/Stages/RefactorStage.php, src/Refactor/RefactorWorker.php, src/Semantic/PsalmTypeProvider.php, src/Validation (as ruled), build-phar.php (key-separator leg), tests/Unit/Parallel/RefactorWorkerTest.php
**Shared resources:** PsalmTypeProvider disposition 100% per engine-S31 ruling (this step EXECUTES the doc'd outcome: fix-or-delete, incl (file,line) collision overwrite + regex-scrape + zero-ctor note); XP-09 phar key separator = S31-context verify.
**Acceptance:** fork-vs-serial refactor parity test (cluster order + signature byte-identity); shuffle-identity sentinel from S87 re-run green as witness; PsalmTypeProvider matches ruling (either absent from src + refs zero, OR collision-safe keyed + typed + wired behind documented gate); `--testsuite Golden` runs WITHOUT UPDATE_SNAPSHOTS and without the token — byte-identical is the proof (Phase-5 r1, I10/D4).

---

### once-S33 — Candidate budget BEFORE pair materialization (scale guard real)

- **Queue rank:** 189 of 247
- **Severity mix:** high=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-once-037
- **Prereqs:** engine-S09, engine-S10, engine-S11, shell-S37  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Pipeline/Stages/ClusterStage.php, src/Clustering/Clusterer.php, src/Index/BlockIndex.php, tests/Unit/Clustering/GenerationBudgetTest.php
**Shared resources:** ClusterStage late chain (after shell S37); [OWNER: budget semantics — pairs generated vs pairs scored — recorded for Phase-5]; CandidateBudgetException exit posture (3→1 today per Command::execute rendering — exit-class row for S95).
**Acceptance:** synthetic 4 000-block same-file family on /tmp corpus w/ --candidate-budget 50000: RSS bounded (probe `ps -o rss` ceiling asserted), throws Budget BEFORE full n² materialization (peak-pairs instrumentation or memory ceiling as witness), exit matches docs; no OOM exit 255.

### once-S68 — ADR/algorithms promise audit close-out + docs/algorithms rewrite

- **Queue rank:** 190 of 247
- **Severity mix:** medium=2
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-once-078, php-dup-finder-once-105
- **Prereqs:** engine-S10, engine-S15, engine-S18b, engine-S31c, output-S01, shell-S37  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** docs/adr/0001-cooperative-pipeline.md, docs/adr/0002-anti-unification.md, docs/adr/0003-similarity-stack.md, docs/algorithms/clustering.md, docs/algorithms/anti-unification.md, docs/ml-corpus-format.md, docs/config-schema.json, src/Cli/Config.php, src/Extraction/BlockExtractor.php
**Shared resources:** [OWNER: min_segment_length implement-vs-document — implement path touches BlockExtractor + cache-key roster: re-bump check w/ S37 owner]; cancelled-flag leg = shell-S37 verify; psalm-baseline stale entries = output-S01 venue, this step cites.
**Acceptance:** each of 078's promises ships a doc-diff or code-diff: tier-fallthrough doc matches engine-S15 emission (MatchTier::Ted now real), hole-kind docs vs code, clustering.md cache-hit truth (post-S23 keys), medoid default row, size-delta abs()+units, digraph gains containment/IR/ML tiers, nonexistent `BinaryOp\Greater_` name fixed, ml-corpus paths post-1.8, '(0.6)' labels honest, YIELD band/3x-seed/dual-membership drift closed, ADR-0002/0001 dated errata where history lied.

### once-S80 — AGENTS.md/CLAUDE.md staleness → re-derivation packet (REPORT-ONLY)

- **Queue rank:** 191 of 247
- **Severity mix:** medium=2
- **Size:** HH (~105 min)
- **Covers:** php-dup-finder-once-100, php-dup-finder-once-101
- **Prereqs:** once-S10, once-S24, once-S34, once-S54, once-S64, once-S68, once-S79, shell-S24  *(Phase-4 adjustment + Phase-5 r1 absorb of once-S81; see Merged & re-routed)*

**Files:** (none — report in lane output)
**Shared resources:** CALIBER-MANAGED — orchestrator refreshes AGENTS/CLAUDE at closeout; NO step edits these files or `.claude/`; packet enumerates: contentClusterId→FindingId::forMembers, 8.1-floor→^8.3, psalm pin, phpdup.json verify recipe (file absent — recipe cites), Block-kind checklist 4th mirror, caliber hook posture — PLUS the Phase-5 r1 (I3/D3a) rider rows the lanes no longer edit themselves: desired `.claude/rules/server-job-queue.md` list text (was shell-S09), `.claude/rules/reporting-patterns.md:17` consumer list (was output-S21), `:12` carve-out wording record (was output-S22 alternative branch), sanctioned multi-file shape paragraph (was output-S55), `.claude/rules/pipeline-stages.md` YIELD_EVERY fold (was shell-S37), plus the retired once-S81 whole: dead anchors→live, `$get`→OverrideResolver/$flatKeys, `--max-clusters`→registered example, YIELD_INTERVAL→YIELD_EVERY, writeTo/build() signatures per output-S55, 3→4 test suites — one proposed-text row per rules/skill file; owner/caliber applies. Also carries the output-S54 cross row: src/Cli/Command.php mapping ReportWriteException → `phpdup:` line + exit code (shell leg).
**Acceptance:** markdown packet delivered in lane report with one line per stale claim + corrected text, incl. every Phase-5 r1 rider row above (grep the packet: the five rules paths + three skill paths each have a proposed-text row); owner/caliber applies.

### engine-S33 — Walk the canonical tree once per block at preprocess (token-stream reuse)

- **Queue rank:** 192 of 247
- **Severity mix:** medium=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-engine-034
- **Prereqs:** engine-S18b, engine-S32, engine-S34b  *(Phase-4 adjustment; see Merged & re-routed)*

- **Files:** `src/Fingerprint/SubtreeHasher.php`, `src/Fingerprint/NgramFingerprint.php`
  (single `tokens()` walk; per-gram slice/implode → roll-window; int interning hook),
  `tests/Unit/Fingerprint/NgramFingerprintTest.php`, `tests/Unit/Fingerprint/SubtreeHasherTest.php`
  (create if absent), behavior-equality pin.
  (key shape), engine-S34 (stream bytes final — this refactor is VALUE-PRESERVING only against the
  post-escape serializer).
- **Shared resources:** outputs MUST be byte-identical (hashes/grams unchanged → no golden/cache
  bumps — and acceptance requires proving that); per-pair sketch consumers (S09 memo) unaffected.
- **Acceptance:** differential test: structuralHash + ngram bag identical old-vs-new codepath on
  all fixtures + a generated property corpus; preprocess CPU disclosed on the /tmp corpus (20-40%
  claim checked, not CI-asserted); full suites green.

### once-S47 — Watcher coherence: recursive inotify, size-aware poll, exclude-honoring, debounced

- **Queue rank:** 193 of 247
- **Severity mix:** high=1 medium=2
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-once-051, php-dup-finder-once-086, php-dup-finder-once-094
- **Prereqs:** shell-S33, shell-S40

**Files:** src/Watch/FileWatcher.php, src/Watch/WatchRunner.php, tests/Unit/Watch/FileWatcherTest.php, tests/Unit/Watch/WatchRunnerTest.php
**Shared resources:** WatchRunner w/ S08 (S08 first); poll/inotify backends internal split; fd-leak fence in test teardown.
**Acceptance:** units: nested-dir create/edit fires (inotify), wd map drains on IN_DELETE_SELF/IN_IGNORED (no unbounded growth — assert count), poll key includes size (mtime-only touch no longer missed), Config::exclude respected in BOTH backends, per-entry UnexpectedValueException caught (symlink loop fixture), events within debounce window coalesce to ONE re-analysis (WatchRunnerTest instrumented).

### once-S72 — TUI pump smoothness + cooperative ReportStage [OWNER PRE-GATE]

- **Queue rank:** 194 of 247
- **Severity mix:** medium=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-once-087
- **Prereqs:** engine-S10, once-S06, once-S07, once-S61, shell-S12  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Pipeline/Stages/ReportStage.php, src/Tui/PhpdupModel.php, src/Pipeline/CooperativeStageInterface.php, src/Tui/Msg/StagePumpedMsg.php, tests/Unit/Tui/PhpdupModelTest.php, tests/Unit/Pipeline/PipelineTest.php
**Shared resources:** [OWNER PRE-GATE: ReportStage cooperative conversion = AGENTS stage-canonical change; owner approval required before execution]; ReportStage also touched by S73 probe logic (serialize S72→S73).
**Acceptance:** PTY soak: pump intervals < 500 ms under --workers 1 large tree (/tmp copy), no multi-second freeze on ReportStage tail; spinner stops within one tick of completion; buildLiveModel constructs iterator ONCE (constructor-count assert); Stage enum doc row updated.

### once-S76 — Pager integrity: no silent truncation, no TTY writes, honest args

- **Queue rank:** 195 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-092
- **Prereqs:** once-S61, once-S72 (TUI/Report interplay ruled), output-S07 (large output batching changed byte volumes)

**Files:** src/Cli/Pager.php, tests/Unit/Cli/PagerTest.php
**Shared resources:** Pager suppression seam from S06; proc_open shared w/ S74 (child-cleanup pattern reuse).
**Acceptance:** 200 KiB paged output under `less` probe: bytes == source (proc_close exit-status check + short-write loop); MODE_AUTO + no-TTY never spawns/writes raw; `--pager='less -R'` split honors quoting; 'vim -R' no longer mis-detected (word-boundary check unit).

### once-S96 — TUI finish state honest

- **Queue rank:** 196 of 247
- **Severity mix:** low=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-120
- **Prereqs:** once-S06, once-S07, once-S61, once-S62, once-S63, once-S72, once-S75, once-S76  *(Phase-4 adjustment + Phase-5 r1 I11: TUI spine edge S72→S96; see Merged & re-routed)*

**Files:** src/Tui/PhpdupModel.php, src/Tui/ViewState.php, tests/Unit/Tui/PhpdupModelTest.php
**Shared resources:** final TuiPhpdupModel writer (all chains close here).
**Acceptance:** completed run: elapsed clock frozen (ticks-after-done assert), sparkline bars only where measured, verdict sentence present ('N clusters' CountPhrase-clean), resolveTheme delegates to sugar map incl auto + unknown-theme warning path (no silent default), truncations carry ellipsis, singular/plural banners.

### output-S26 — hole observation desync + degraded honesty on back-records

- **Queue rank:** 197 of 247
- **Severity mix:** medium=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-output-018
- **Prereqs:** output-S25  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 018
- Files: `src/Refactor/AntiUnifier.php` (:107-127, :353-369, :431-444), `src/Refactor/Hole.php` (MISSING/degraded surface), `src/Refactor/HoleCoverage.php` (surface degraded), tests: `tests/Unit/Refactor/{AntiUnifierTest,HoleCoverageTest}.php`
- Size: DD (240 min)
- Prereqs: S25 (same file).
- Conflicts: degraded semantics consumed by S45 (apply args) and S51 (gate) — chain.
- Acceptance: new unit test forces hole-after-divergence ordering ⇒ observedValues length == member count (placeholder rows explicit, never silent seed-backfill); seed-remap guard swap happens unconditionally with placeholder-awareness; degraded flag observable on Hole::isDegraded for the misrecord case; `--filter AntiUnifierTest HoleCoverageTest`; goldens byte-identical at THIS step (representation still string-side until S27; if the placeholder choice forces early schema talk, stop and merge into S27); GATE.

### output-S27 — canonical absence: Hole::MISSING end-to-end + schema 1.6→1.7 + GOLDENS

- **Queue rank:** 198 of 247
- **Severity mix:** medium=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-output-019
- **Prereqs:** output-S26  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 019 — GOLDEN-STEP + SCHEMA BUMP
- Files: `src/Refactor/AntiUnifier.php` (:110, :435, :443), `src/Refactor/Hole.php`, `src/Refactor/ParameterSynthesizer.php` (:204), `src/Reporting/JsonReporter.php` (:166-172 observed/present_in_members), `src/Reporting/HtmlReporter.php` (:195), `src/Reporting/CliReporter.php` (summarizeObserved :332), `src/Reporting/RefactorTestReporter.php` (:141-143), `src/Reporting/JsonSchemaSpec.php` (SCHEMA_VERSION + observed docs :201-205), `tests/Golden/notify-exact.json`, `tests/Golden/sql-exact.json`, `tests/Golden/optional-near-dups.json`, tests: `tests/Unit/Reporting/{JsonReporterTest,CliReporterHonestyTest,HtmlReporterDegradedTest,RefactorTestReporterTest}.php`, `docs/algorithms/anti-unification.md`, `docs/adr/0002-anti-unification.md`, `docs/JETBRAINS_PLUGIN.md` (sentinel wording)
- Size: DD (240 min)
- Prereqs: S26. Land before S35/S36/S45 (all read absence).
- Conflicts: sole SCHEMA_VERSION bump in group; only golden-mover that changes observed/present semantics (optional-near-dups.json WILL move — expected: `<absent>` strings replaced by MISSING encoding + present_in_members recomputed; diff must be limited to absence-representation keys — anything else = bug).
- Acceptance: `grep -rn "'<absent>'" src/` → hits only in render-projection layer (reporters' display formatting) or zero; `bin/phpdup analyze tests/Fixtures/optional --json /tmp/j.json` + jq: `observed` entries never contain `<absent>`; `--filter CliReporter` shows honest absent cell (not raw sentinel); after `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`, `git diff --stat tests/Golden` reviewed line-by-line and justified; GATE with schema_version 1.7 in goldens.

### output-S35 — RefactorTestReporter: kill raw-quote passthrough (injection sink)

- **Queue rank:** 199 of 247
- **Severity mix:** critical=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-output-007
- **Prereqs:** output-S18, output-S27  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 007 — CRITICAL
- Files: `src/Reporting/RefactorTestReporter.php` (:139-166, especially :161), `tests/Unit/Reporting/RefactorTestReporterTest.php`, `tests/Integration/RefactorTestSkeletonCensusTest.php`
- Size: HH (60 min)
- Prereqs: S18 (ControlText scrub helper), S27 (canonical absence before repr rework).
- Acceptance: re-run of SEC's shape: `bin/phpdup analyze /tmp/<hostile-fixture> --refactor-tests /tmp/<out> --min-impact 0 --max-df 0.5` → generated skeleton passes `php -l` and does NOT contain expression-shaped raw values (assert `'…touch('` never appears unquoted); unit pin: value `"'' . touch('/tmp/x') . ''"` emits as var_export'd string; census Integration test regenerates cleanly; note: same quote-class in ParameterSynthesizer:62 is typed via S28/S29 work (non-sink, report row). Goldens unaffected (skeletons not golden-fed); GATE.

### once-S35 — Hole bookkeeping residuals: full-kind backfill + id-keyed remap

- **Queue rank:** 200 of 247
- **Severity mix:** high=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-once-039
- **Prereqs:** output-S26 (desync core), output-S27

**Files:** src/Refactor/AntiUnifier.php, src/Reporting/RefactorTestReporter.php, tests/Unit/Refactor/HoleCoverageTest.php, tests/Unit/Refactor/RefactorTestReporterTest.php
**Shared resources:** AntiUnifier after output S25-S27; RefactorTestReporter after output-S35/S43.
**Acceptance:** units: call/identifier holes get observed-path backfill (not optional_block-only), seed reorder (seed 0↔idx swap fixture) keeps holePaths aligned by member id; RefactorTestReporter:141 positional→keyed lookup pinned.

### output-S28 — float repr via var_export (int/float typing honesty)

- **Queue rank:** 201 of 247
- **Severity mix:** medium=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-output-025
- **Prereqs:** output-S27  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 025
- Files: `src/Refactor/AntiUnifier.php` (:524 repr), `src/Refactor/ParameterSynthesizer.php` (:58-72 cascade awareness — 1e25 shape), tests: `tests/Unit/Refactor/{AntiUnifierTest,ParameterSynthesizerTest}.php`
- Size: HH (60 min)
- Prereqs: S27 (observed-value semantics settled; avoids double golden churn).
- Acceptance: `--filter ParameterSynthesizerTest` — hole of `1.0` vs `1` classifies float-vs-int distinctly; `1.0E+25` reprs and types float (not mixed); repr stable under `php -d precision=14` and `=17` (test both via subproc or ini_set scope); goldens byte-identical expected (no float holes in the three corpora — VERIFY `grep -E '1\.0E|\.0"' tests/Golden/*.json` empty; if corpus floats exist, regen disclosed); GATE.

### output-S36 — RefactorTestReporter: sanitized class name + bool optional rows

- **Queue rank:** 202 of 247
- **Severity mix:** medium=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-output-033
- **Prereqs:** output-S17, output-S35  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 033
- Files: `src/Reporting/RefactorTestReporter.php` (:37-48, :141-143+:165), tests: `RefactorTestReporterTest.php`, `tests/Integration/RefactorTestSkeletonCensusTest.php`
- Size: HH (60 min)
- Prereqs: S35, S17 (safeSlug single-source — class name derives from the same sanitization).
- Acceptance: `--filter RefactorTestReporterTest` — legacy-shaped odd id yields valid PHP class name == filename stem; optional_block present rows emit bare `true`/`false` (census test: every generated skeleton `php -l`-clean and PHPUnit-collectable against a stub function, if the census harness executes — else its parse-level pin). GATE.

### once-S37 — Honest scan/parse/replay signals in the report (+schema 1.8 owner)

- **Queue rank:** 203 of 247
- **Severity mix:** high=1 medium=2
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-041, php-dup-finder-once-080, php-dup-finder-once-081
- **Prereqs:** engine-S17b, engine-S27, engine-S34b, output-S27, shell-S26, shell-S34  *(Phase-4 adjustment + Phase-5 r1 I2: S34b before the combined golden event; see Merged & re-routed)*

**Files:** src/Scanning/FileScanner.php, src/Pipeline/Stages/PreprocessStage.php, src/Pipeline/Stages/ScanningStage.php, src/Pipeline/PipelineState.php, src/Reporting/JsonReporter.php, src/Reporting/JsonSchemaSpec.php, src/Reporting/CliReporter.php, src/Cli/Command.php, docs/CLI.md, docs/config-schema.json, tests/Unit/Scanning/FileScannerTest.php, tests/Unit/Reporting/JsonReporterTest.php — **GOLDEN-MOVES**
**Shared resources:** **SCHEMA_VERSION bump owner 1.7→1.8 (additive only)** — S55/S70 wait for this window; goldens regen once, disclose; missing-path exit-2 leg w/ shell-S24 scope; skipNotes channel reused by S41/S66/S71.
**Acceptance:** `summary.scan_notes[]`, `summary.parse_failures[]` (with real parser message, not discarded), `summary.clusters_replayed` present on warm runs; nonexistent path → exit 2; typo'd --exclude warns once; skipped-file census visible at default verbosity; after regen goldens additively differ ONLY in new fields.

### once-S41 — Symlink-out-of-root refusal: serve-side closure + note surfacing

- **Queue rank:** 204 of 247
- **Severity mix:** high=1
- **Size:** MM (~10 min)
- **Covers:** php-dup-finder-once-045
- **Prereqs:** once-S37, shell-S01, shell-S20  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Server/Application.php, src/Server/AnalysisWorker.php, docs/SERVER.md, tests/Unit/Server/ApplicationTest.php
**Shared resources:** final Application server-chain leg.
**Acceptance:** live /tmp probe: serve-root w/ symlink to outside tree → job refuses (400) or skip-note appears (per S20 policy), count surfaced in scan_notes; SERVER.md confinement sentence matches shipped behavior verbatim.

### once-S66 — Plugin failures degrade, never crash the run

- **Queue rank:** 205 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-075
- **Prereqs:** engine-S28, once-S37 (skip-note channel)

**Files:** src/Normalization/PluginRegistry.php, src/Pipeline/Stages/PreprocessStage.php, src/Cli/Command.php, tests/Unit/Normalization/PluginRegistryTest.php
**Shared resources:** preprocess/refactor symmetry — refactor-side check only (engine-S28 owns ctor gate).
**Acceptance:** throwing-ctor plugin → single `phpdup:` warning + plugin excluded + report still produced + plugin note lands in scan_notes (S37 channel); NO usage dump; same on refactor-side path.

### once-S67 — per-dir admission honesty: apply or refuse every key (paths replaced bug)

- **Queue rank:** 206 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-077
- **Prereqs:** once-S28, once-S54, once-S66, shell-S22  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/ConfigLoader.php, src/Cli/OverrideResolver (as named on disk at exec time), src/Reporting/BaselineStore.php (baseline key), docs/config-schema.json, docs/CLI.md, tests/Unit/Cli/PerDirOverrideBeltTest.php
**Shared resources:** closes EVERY per-dir key decision from S28/S54/S66/S29(apply)/S50(baseline) — belt list == admitted list == consumed list re-run; ConfigLoader:96 paths-replace fix + profile-detection uses post-merge roots.
**Acceptance:** matrix test: each of the ~17 keys via .phpdup.json → honored (assert observable effect) OR rejected w/ per-dir note; `paths` no longer clobbers CLI args silently; stale-CLI-arg profile-detection fixed.


- **Phase-4 Outside merge:** ENGINE-PHP out-of-group memo — the untrusted-config plugin-class-name admission (which names a .phpdup.json may name; what a hostile ctor does at load) is inventoried + closed by this matrix step (mechanism gate landed in engine-S28).

### once-S70 — ML degraded mode: one probe, backoff, visible status

- **Queue rank:** 207 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-082
- **Prereqs:** engine-S21, once-S37 (1.8 window), once-S89

**Files:** src/Ml/MlPairClient.php, src/Ml/MlClient.php (wire-or-delete note), src/Reporting/JsonReporter.php (status field), tests/Unit/Ml/MlPairClientTest.php
**Shared resources:** summary gains `ml_status`-class additive INSIDE S37's 1.8 (coordinate: same window, no second bump); S53 docs tie; storm = per-pair re-POST until backoff from S19/S21 lands.
**Acceptance:** fake-down sidecar: exactly ONE connect probe then fallback mode (request-count assert), run finishes exit 0 but summary.ml_status='unreachable' + stderr notice — 'unreachable' vs 'no dups' now distinguishable; MlPairTierClustererTest extended.

### output-S29 — ParameterSynthesizer: one classification pass into category counts

- **Queue rank:** 208 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-045
- **Prereqs:** output-S27, output-S28  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 045
- Files: `src/Refactor/ParameterSynthesizer.php` (:49-81, :103-136, :240-263), tests `ParameterSynthesizerTest`
- Size: HH (90 min)
- Prereqs: S28 (same cascade), S27 (absent-skip logic unchanged input).
- Acceptance: `--filter ParameterSynthesizerTest` — inferredType results identical on all fixtures + synthetics (pin vector); preg counter (test double or xdebug-free structural assert: cascade functions reduced to single walk) shows ≤1 classify per value; goldens byte-identical; GATE.

### once-S55 — JSON shape governance (kind/config block/sentinel)

- **Queue rank:** 209 of 247
- **Severity mix:** medium=2
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-once-060, php-dup-finder-once-061
- **Prereqs:** output-S27, once-S37

**Files:** src/Reporting/JsonReporter.php, src/Reporting/JsonSchemaSpec.php, docs/config-schema.json — **GOLDEN-MOVES (within S37's 1.8 window only)**
**Shared resources:** participates in S37's bump (1.8 covers both; never two bumps in one wave); '<absent>' sentinel [DECISION sanction-in-shape vs replace]; governance retro-note in JsonSchemaSpec docblock.
**Acceptance:** `kind` declared nullable == emitted (examples include null case); summary config block carries max_hole_coverage/score_mode/scope; example cases lowercase db keys match pack reality or vice versa; phpdoc shape annotations pass psalm; goldens contain ONLY S37∪S55 declared additive deltas.

### output-S30 — walkers: shared path-carry helper (AntiUnifier, SignatureBuilder, HoleCoverage)

- **Queue rank:** 210 of 247
- **Severity mix:** medium=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-039
- **Prereqs:** output-S25, output-S26, output-S28, output-S29  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 039
- Files: `src/Refactor/AntiUnifier.php` (:235-301, :499-502), `src/Refactor/SignatureBuilder.php` (:132-179), `src/Refactor/HoleCoverage.php` (:148-179, :202-205), (shared helper placement: private trait in src/Refactor or `src/Util/NodePath.php`), tests: `tests/Unit/Refactor/{AntiUnifierTest,SignatureBuilderTest,HoleCoverageTest}.php`
- Size: HH (150 min)
- Prereqs: S25 (walk()/clone structure first), S26. Serial with S28/S29 by file.
- Acceptance: paths and pathKey outputs byte-identical (property test: same trees, old-vs-new key on random ASTs); `--filter HoleCoverageTest` unchanged coverage numbers on fixtures; goldens byte-identical (hole paths/signature material identical); GATE.

### output-S57 — RefactorStage: cap members fed to anti-unification per cluster (route giant clusters through representatives)

- **Queue rank:** 211 of 247
- **Severity mix:** (re-routed passthrough leg — no consolidated finding ID)
- **Size:** HH (~180 min)
- **Covers:** —
- **Prereqs:** output-S26, output-S30  *(Phase-4 adjustment; see Merged & re-routed)*


- Covers: (no consolidated ID — routed from ENGINE-FUZZ-X01 'Outside this group' high: src/Refactor/
  RefactorStage O(members²) on one giant exact cluster; 590 KB/1000-same-shape-function file →
  refactor 63.08 s; N=500→22 s, N=1000→87 s, N≥2000 never finished 300 s; candidate budget and
  --exact-only do NOT protect — the storm dies downstream of clustering)
- Files: `src/Refactor/AntiUnifier.php` (representative selection + counted-tail policy),
  `src/Pipeline/Stages/RefactorStage.php` (per-cluster member cap + honest tail narration),
  `tests/Unit/Refactor/AntiUnifierTest.php`, `tests/Unit/Pipeline/Stages/RefactorStageTest.php`
- Size: HH (180 min)
- Prereqs: output-S26 (degraded/hole honesty settled before membership capping changes what reaches
  the unifier), output-S30 (shared walker refactor lands first — same file serial).
- Shared resources: AntiUnifier chain (after S25-S30); RefactorStage.php is also in shell-S37's
  reindent set (lock overlap serializes; the cap logic lands before or after the reindent — either).
- Acceptance: the finding's repro shape regenerated on /tmp (file with 1000 same-shape functions):
  `bin/phpdup analyze <file> --no-cache` refactor stage completes in bounded time (target: seconds,
  never 300 s timeout) with a disclosed members-cap + counted-tail note in the cluster report
  ('N members, M used as representatives'); --stats surfaces the cap; existing golden corpora
  (≤ few members per cluster) byte-identical — cap never bites at current sizes (prove zero-move);
  GATE.

### output-S38 — shared per-run SourceTextStore: single read path, symlink-confinement on reads, detectUnsafe memoization, honest source-unavailable posture

- **Queue rank:** 212 of 247
- **Severity mix:** high=1 medium=2
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-output-016, php-dup-finder-output-043, php-dup-finder-output-048
- **Prereqs:** output-S18, output-S22  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 016, 043, 048
- Files: `src/Reporting/SourceTextStore.php` (new), `src/Reporting/HtmlReporter.php` (:244-255), `src/Reporting/DiffReporter.php` (:95-107), `src/Reporting/RefactorPatchReporter.php` (:234-246 + memoize :87/:139 verdicts), `src/Reporting/JsonReporter.php` (sourcePayload :227), `src/Reporting/Report.php` or PipelineState/passing decision (store lifetime — planning seam), `src/Pipeline/Stages/ReportStage.php` (construction/injection), tests: `tests/Unit/Reporting/{HtmlReporterTest,DiffReporterTest,RefactorPatchReporterTest,JsonReporterEvidenceTest}.php` + `tests/Unit/Reporting/SourceTextStoreTest.php`
- Size: DD (240 min)
- Prereqs: S18 (path hardening at read boundary), S22 (formula guard order independent — same-file serial), after S43/S50 (RefactorPatch shape) OR design the store to accept their current calls — ruling: land S38 BEFORE S50 (fail-closed detectUnsafe then reads through store).
- Conflicts: (xg: durable fix = FileScanner FOLLOW_SYMLINKS Outside row — this step is the defensive read-side half only); ReportStage wiring shared with S34/S42.
- Acceptance: `--filter SourceTextStoreTest` — mtime/size revalidation, link divergence ⇒ skip + skip-note (mirrors FileScanner::skipNotes posture), one read per file per run (read-count spy over 30-cluster synthetic fixture: ≤ file count, not ≤ cluster×file); symlinked out-of-root member ⇒ its source NOT embedded in HTML/`.diff`/`.patch` (explicit unfetched marker, `grep` of output for out-of-root content empty); detectUnsafe invoked once per cluster (counter test); DiffReporter placeholders now behind `sourceAvailable`-style explicit posture — JSON values change only in previously-placeholdered cases (none in goldens → byte-identical); GATE.

### output-S44a — ApplyExtractor: single-pass per-file rewrite, fail-loud, transaction + rollback, honest tallies — half A: single-pass per-file rewrite — group by file, one parse/prettyPrint, count-verify, `@`-swallow gone, diagnostics for the :332-351 silent returns.

- **Queue rank:** 213 of 247
- **Severity mix:** critical=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-004
- **Prereqs:** output-S26, output-S38  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: single-pass per-file rewrite — group by file, one parse/prettyPrint, count-verify, `@`-swallow gone, diagnostics for the :332-351 silent returns. Sibling: output-S44b — together the two legs replace output-S44 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to output-S44 denote the leg pair. Execute only the legs assigned to this half.

- Covers: 004 — CRITICAL
- Files: `src/Refactor/ApplyExtractor.php` (:66-73, :330-362 restructure: group members by file, one parse, node-identity collection, one prettyPrint, count-verify, refuse cluster on any member miss; @-swallow removal), `src/Reporting/RefactorPatchReporter.php` (:99-113 Applied tally honesty), tests: `tests/Unit/Refactor/ApplyExtractorTest.php`, `tests/Unit/Reporting/RefactorPatchReporterTest.php`
- Size: DD (300 min)
- Prereqs: S26 (degraded available for honest refusal), S38 (read confinement via store).
- Conflicts: opens the ApplyExtractor serial chain: S44→S45→S46→S47→S48→S49; the trait-write routing of apply's write sites lands in S54, which is sequenced after this chain (S54 prereqs include S49).
- Acceptance: `--filter ApplyExtractorTest` — two members in one file: BOTH rewritten from a single parse (stale-range miss scenario now succeeds — explicit regression test); every silent return (`:332-334`, `:337-339`, `:343-345`, `:349-351`) now emits a diagnostic (assert message count/content); IO error on read or write throws (chmod-fixture); partial-failure leaves pre-first-write snapshots restored (rollback test via read-only dir or injected failure); RefactorPatch summary counts != appliedMembers unless verified. GATE; apply outputs not golden-fed → goldens byte-identical.

### output-S44b — ApplyExtractor: single-pass per-file rewrite, fail-loud, transaction + rollback, honest tallies — half B: transaction + rollback (snapshots restored byte-for-byte) + RefactorPatchReporter :99-113 honest tallies.

- **Queue rank:** 214 of 247
- **Severity mix:** critical=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-004
- **Prereqs:** output-S26, output-S38, output-S44a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: transaction + rollback (snapshots restored byte-for-byte) + RefactorPatchReporter :99-113 honest tallies. Sibling: output-S44a — together the two legs replace output-S44 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to output-S44 denote the leg pair. Execute only the legs assigned to this half.

- Covers: 004 — CRITICAL
- Files: `src/Refactor/ApplyExtractor.php` (:66-73, :330-362 restructure: group members by file, one parse, node-identity collection, one prettyPrint, count-verify, refuse cluster on any member miss; @-swallow removal), `src/Reporting/RefactorPatchReporter.php` (:99-113 Applied tally honesty), tests: `tests/Unit/Refactor/ApplyExtractorTest.php`, `tests/Unit/Reporting/RefactorPatchReporterTest.php`
- Size: DD (300 min)
- Prereqs: S26 (degraded available for honest refusal), S38 (read confinement via store).
- Conflicts: opens the ApplyExtractor serial chain: S44→S45→S46→S47→S48→S49; the trait-write routing of apply's write sites lands in S54, which is sequenced after this chain (S54 prereqs include S49).
- Acceptance: `--filter ApplyExtractorTest` — two members in one file: BOTH rewritten from a single parse (stale-range miss scenario now succeeds — explicit regression test); every silent return (`:332-334`, `:337-339`, `:343-345`, `:349-351`) now emits a diagnostic (assert message count/content); IO error on read or write throws (chmod-fixture); partial-failure leaves pre-first-write snapshots restored (rollback test via read-only dir or injected failure); RefactorPatch summary counts != appliedMembers unless verified. GATE; apply outputs not golden-fed → goldens byte-identical.

### output-S45 — apply call-sites: observed values, canonical param order, typed literals

- **Queue rank:** 215 of 247
- **Severity mix:** critical=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-output-001
- **Prereqs:** output-S26, output-S27, output-S28, output-S44b  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 001 — CRITICAL
- Files: `src/Refactor/ApplyExtractor.php` (buildCallReplacement :429-455: consume SignatureBuilder's CodeParam order, `observedValues[$memberIdx]` re-parsed nodes, ConstFetch true/false keyed on MISSING/absent per-member, Int_ for ints), `tests/Unit/Refactor/ApplyExtractorTest.php` (hole-order≠param-order regression: optional-before-required cluster binds correctly per member)
- Size: DD (240 min)
- Prereqs: S44 (pass structure), S27 (MISSING/absent canonical form to key presence on), S28 (float literal shapes honest), S26 (per-member observation trustworthy).
- Acceptance: `--filter ApplyExtractorTest` — generated call sites for a 2-member cluster pass DIFFERENT argument expressions (observed-value substitution pinned in output AST); `php -l` on every rewritten buffer (trait of test); optional hole: member-with segment ⇒ its own value; member-without ⇒ `false` literal and the whole rewrite under `declare(strict_types=1)` fixture passes a runtime smoke (generated+rewritten files executed, no TypeError); rewritten file's call-site argument ORDER matches the emitted signature's order (structural assert via nikic Printer); GATE.

### output-S46 — apply declaration replacement: replace bodies, not declarations

- **Queue rank:** 216 of 247
- **Severity mix:** critical=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-002
- **Prereqs:** output-S44b, output-S45  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 002 — CRITICAL
- Files: `src/Refactor/ApplyExtractor.php` (:444-451 replacement construction: keep ClassMethod/Function_ node, set stmts to `[new Stmt\Return_($call)]` / Expression for void; Closure/arrow members: refuse (typed reason)), `tests/Unit/Refactor/ApplyExtractorTest.php`, `tests/Integration/ApplyExtractionTest.php` (new: end-to-end apply on fixture tree, `php -l` all outputs, external-caller smoke run)
- Size: HH (150 min)
- Prereqs: S44, S45 (call construction inside replacement first).
- Acceptance: `--filter ApplyExtractorTest ApplyExtractionTest` — method-in-class fixture: rewritten file `php -l`-parses and class still declares the method (signature preserved, delegating body) — old behavior produced `unexpected token "return"`: assert no top-level Return_ in class body by AST check; file-scope function member: original function still callable after rewrite (delegation), later declarations survive include-time (exec smoke test); Closure member ⇒ refusal diagnostic, file untouched; GATE.

### output-S48 — hole placement correctness (setNodeAtPath) + extraction-failure honesty + pre-write re-parse guard

- **Queue rank:** 217 of 247
- **Severity mix:** critical=1
- **Size:** DD (~240 min)
- **Covers:** php-dup-finder-output-005
- **Prereqs:** output-S44b  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 005 — CRITICAL
- Files: `src/Refactor/ApplyExtractor.php` (:119-185 setNodeAtPath parent/key tracking + `=== $target` assert; :213-228 property-aware array pick; :279-312 extractBodyLines throw-instead-of-stub; :70-73 + rewritten buffers re-parse guard via nikic Parser before any write), `tests/Unit/Refactor/ApplyExtractorTest.php` (2-segment `['stmts', i]` optional_block placement case — today untested)
- Size: DD (240 min)
- Prereqs: S44. Chain before S49 (both touch generated-body construction).
- Acceptance: `--filter ApplyExtractorTest` — optional_block hole lands as direct sibling of template statements (printed-body pin; the docblock "bool gates nothing" made true or param deleted); silent placement-miss now aborts/refuses cluster with diagnostic (assert); body-extraction failure refuses cluster (no `// (body extraction failed)` stub path — `grep -c` assert removal); corrupted generated buffer (injected) ⇒ apply halts BEFORE writing any file (write-count-0 test via unwritable dir witness or vfs-lite fixture); `php -l` on all emitted files in existing happy-path tests; GATE.


- **Phase-4 Outside merge:** ENGINE-SEC out-of-group 3 — --apply must never consume block ASTs from cache without the fresh-bytes relocation check; the pre-write re-parse guard here is that check's apply-side half (cache-side trust gate = engine-S25).

### output-S49a — promote members' shared formal parameters into the abstraction — half A: intersected-params derivation in AntiUnifier + SignatureBuilder consumption.

- **Queue rank:** 218 of 247
- **Severity mix:** critical=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-006
- **Prereqs:** output-S26, output-S45, output-S48  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half A (Phase-5 r1, I5/D2a):** this half owns: intersected-params derivation in AntiUnifier + SignatureBuilder consumption. Sibling: output-S49b — together the two legs replace output-S49 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to output-S49 denote the leg pair. Execute only the legs assigned to this half.

- Covers: 006 — CRITICAL
- Files: `src/Refactor/AntiUnifier.php` (or new `ParamIntersection` unit under src/Refactor/), `src/Refactor/ApplyExtractor.php` (buildFunctionFile :245-277 param list; call site :429-455 passes member locals), `src/Refactor/SignatureBuilder.php` (param source consumed), tests: `tests/Unit/Refactor/{AntiUnifierTest,ApplyExtractorTest,SignatureBuilderTest}.php`
- Size: DD (300 min)
- Prereqs: S45 (call-arg construction rewritten first), S48 (body construction stable), S26 (hole honesty).
- Acceptance: `--filter ApplyExtractorTest` — PDREF S1-shape fixture (`send_user($value, $user)` with member param `$user`): generated function signature includes the intersected params; emitted call passes the member's own locals in positional order; non-intersecting params (name/type differ) NOT promoted (negative case); applied fixture executes under strict_types with same behavior as originals (smoke harness from S46 reused); goldens: cluster signatures gain params on fixtures WITH parameterized members — run Golden suite without UPDATE first to verify zero-move on current corpora (notify/sql members take params: expect signature VALUE changes only if extraction feeds signatures — it does not (goldens carry SignatureBuilder output on hole params; promotion changes hole/param split → possible value movement); if move, regen+disclose as golden-step addendum); GATE.

### output-S49b — promote members' shared formal parameters into the abstraction — half B: ApplyExtractor param list + call-site wiring + strict_types smoke + goldens zero-move check.

- **Queue rank:** 219 of 247
- **Severity mix:** critical=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-output-006
- **Prereqs:** output-S26, output-S45, output-S48, output-S49a  *(Phase-5 r1 split; see Merged & re-routed)*

> **Half B (Phase-5 r1, I5/D2a):** this half owns: ApplyExtractor param list + call-site wiring + strict_types smoke + goldens zero-move check. Sibling: output-S49a — together the two legs replace output-S49 (300 min). Header lines + locks above govern this half; the parent detail preserved below describes the whole fix, and prose references to output-S49 denote the leg pair. Execute only the legs assigned to this half.

- Covers: 006 — CRITICAL
- Files: `src/Refactor/AntiUnifier.php` (or new `ParamIntersection` unit under src/Refactor/), `src/Refactor/ApplyExtractor.php` (buildFunctionFile :245-277 param list; call site :429-455 passes member locals), `src/Refactor/SignatureBuilder.php` (param source consumed), tests: `tests/Unit/Refactor/{AntiUnifierTest,ApplyExtractorTest,SignatureBuilderTest}.php`
- Size: DD (300 min)
- Prereqs: S45 (call-arg construction rewritten first), S48 (body construction stable), S26 (hole honesty).
- Acceptance: `--filter ApplyExtractorTest` — PDREF S1-shape fixture (`send_user($value, $user)` with member param `$user`): generated function signature includes the intersected params; emitted call passes the member's own locals in positional order; non-intersecting params (name/type differ) NOT promoted (negative case); applied fixture executes under strict_types with same behavior as originals (smoke harness from S46 reused); goldens: cluster signatures gain params on fixtures WITH parameterized members — run Golden suite without UPDATE first to verify zero-move on current corpora (notify/sql members take params: expect signature VALUE changes only if extraction feeds signatures — it does not (goldens carry SignatureBuilder output on hole params; promotion changes hole/param split → possible value movement); if move, regen+disclose as golden-step addendum); GATE.

### output-S43 — RefactorPatchReporter: correct hunk header, distinct summary artifact, WritesReportFile trait

- **Queue rank:** 220 of 247
- **Severity mix:** medium=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-output-022
- **Prereqs:** output-S17, output-S38  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 022
- Files: `src/Reporting/RefactorPatchReporter.php` (:61-63, :76, :86, :106-114, :123, :136-139, :160), tests: `tests/Unit/Reporting/RefactorPatchReporterTest.php`
- Size: HH (60 min)
- Prereqs: S38 (trait+store ordering), S17 (slug site).
- Conflicts: (xg: engine — ReportStage.php:283-284 `apply diff →` announcement must follow the new artifact names; that line is an Outside row — report for Phase-4).
- Acceptance: `--filter RefactorPatchReporterTest` — emitted `@@ -0,0 +1,N @@` == actual added lines (multi-line signature fixture), `patch -p1 --dry-run` (or git apply --check) accepts a single-member patch; apply-mode summary lands as `apply-summary.md` (name decided in impl, pinned) not `.diff`; `grep -c "file_put_contents\|@mkdir" src/Reporting/RefactorPatchReporter.php` → 0 (all via trait); GATE.


- **Phase-4 Outside merge:** engine's row 'ReportStage.php:283-284 prints `apply diff →` while the F1b branch writes a non-diff SUMMARY' — fix the announcement line to follow the new artifact names in this lane (files += src/Pipeline/Stages/ReportStage.php).

### output-S47 — apply: fully-qualified call name + generated-file loading story; hint/apply share it

- **Queue rank:** 221 of 247
- **Severity mix:** critical=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-003
- **Prereqs:** output-S43, output-S44b  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 003 — CRITICAL
- Files: `src/Refactor/ApplyExtractor.php` (:444-447 name node + :68-73 generated file emission: bootstrap stub (`$outDir/Refactored/_autoload.php` or per-file `require` injection at rewrite sites — impl decision pinned in commit msg) or explicit emitted instructions), `src/Reporting/RefactorPatchReporter.php` (:227 hint text sourced from same name builder), tests: ApplyExtractorTest + RefactorPatchReporterTest hint/apply name-equality pin
- Size: HH (90 min)
- Prereqs: S44; S43 (hint region settled) — same-file serial.
- Acceptance: `--filter ApplyExtractorTest RefactorPatchReporterTest` — namespaced member fixture: applied rewrite + require stub, then executing the rewritten file resolves the function (PDREF's `App2\Refactored\s7a` fatal scenario now calls `\Refactored\s7a` — run smoke: require stub, invoke, no undefined-function error); dry-run hint line and applied call text byte-equal for the same cluster; GATE.

### output-S37 — single-line signatures: SignatureBuilder + ApplyExtractor de-flatten + GOLDENS

- **Queue rank:** 222 of 247
- **Severity mix:** low=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-058
- **Prereqs:** output-S27, output-S30, output-S44b, output-S45, output-S46  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 058 — GOLDEN-STEP (value bytes; NO schema bump — string field shape unchanged)
- Files: `src/Refactor/SignatureBuilder.php` (:54-55), `src/Refactor/ApplyExtractor.php` (:263 collapse removal, :314-325 extractParameters simplification), tests: `tests/Unit/Refactor/SignatureBuilderTest.php`, `tests/Unit/Reporting/{JsonReporterTest,CliReporterHonestyTest}.php` (signature render pins), `tests/Golden/notify-exact.json`, `tests/Golden/sql-exact.json`, `tests/Golden/optional-near-dups.json` (signature lines reflow — expected-only-those-keys diff)
- Size: HH (90 min)
- Prereqs: S30 (SignatureBuilder path walk first), S44+S46 (ApplyExtractor rewrite chain has settled the call-construction; run this AFTER the apply wave to freeze one golden regen post-S27 — recorded ordering), S45 (the hole-order≠param-order regression test from output-001 must exist so flattening is provably order-safe).
- Acceptance: `--filter SignatureBuilderTest` params `", "`-joined single line; docblock shows no multi-space artifact; `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden` → diff limited to `"signature"` string values (asserted via `git diff -U0 tests/Golden | grep -v signature` containing only context/header lines); `extractParameters` greedy regex removed or simplified per plan; GATE.

### once-S39 — docs/JETBRAINS_PLUGIN.md regenerated from shipped schema

- **Queue rank:** 223 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-043
- **Prereqs:** output-S27, output-S37, once-S37 (schema 1.8 final)

**Files:** docs/JETBRAINS_PLUGIN.md
**Shared resources:** JETBRAINS page exclusive here; exit-table merge w/ S95 cites this file.
**Acceptance:** doc's schema_version, field list, example payload and exit-code table diffed against `JsonSchemaSpec::summaryShape()` + live fixture bytes — zero mismatch; stale '1.0' prose gone.

### once-S13 — README ToC anchors vs final docs tree

- **Queue rank:** 224 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-011
- **Prereqs:** once-S17, once-S18, once-S24, once-S39, once-S41, once-S53, once-S57, once-S73  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** README.md, .github/workflows/ci.yml
**Shared resources:** ci.yml final slot (after S10→S11→S56→S93); README final content slot before S95.
**Acceptance:** every ToC href resolves against post-wave docs/CLI.md + docs/*.md (local script or manual grep census: ~22 anchors now-to-be-live or removed); CI gets anchor-resolution leg (grep/awk over README links vs generated slug set of docs tree, fail-loud).

### output-S41 — JsonReporter: cap before Differ; coverage tallies only when needed

- **Queue rank:** 225 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-044
- **Prereqs:** output-S30, output-S38  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 044
- Files: `src/Reporting/JsonReporter.php` (:101, :227, :240-260), `tests/Unit/Reporting/{JsonReporterTest,JsonReporterEvidenceTest}.php`
- Size: HH (90 min)
- Prereqs: S30 (pathKey cost — the finding notes 039's fix reduces the coverage cost; land after), S38 (source via store; cap windows from stored lines).
- Acceptance: `--filter JsonReporter` — evidence bytes identical on all fixtures (byte-level pin vs pre-change on tests/Fixtures corpora — this is a cost-reduction, not a shape change); synthetic huge-file fixture completes with source windows sliced before diffing (no full-file Differ; counter test); `HoleCoverage::coverage` still emitted for all clusters by default (no shape change, no flag) OR moved behind the planning-flag decision — if behind flag: schema decision + S27 coordination; expected this step keeps emission and only optimizes. Goldens byte-identical; GATE.

### output-S42 — ClusterScope: one spread computation per cluster per run

- **Queue rank:** 226 of 247
- **Severity mix:** low=1
- **Size:** HH (~75 min)
- **Covers:** php-dup-finder-output-059
- **Prereqs:** output-S34, output-S38, output-S41  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 059
- Files: `src/Reporting/ClusterScope.php` (memo side-map), consumers verified unchanged: `src/Reporting/Ranker.php`, `src/Reporting/SafetyScorer.php`, `src/Reporting/JsonReporter.php`, `src/Pipeline/Stages/ReportStage.php`
- Size: HH (75 min)
- Prereqs: S38 + S34 (ReportStage serial), after S41 (JsonReporter order).
- Acceptance: `--filter ClusterScopeTest` — memoized results identical to fresh computes (per-run purity preserved per class docblock posture — memo must be request-scoped, not static-across-runs); walk counter test; JSON output byte-identical on fixture (no shape change); GATE.

### output-S52 — apply write-side confinement: links refused, root-checked paths, chmod

- **Queue rank:** 227 of 247
- **Severity mix:** high=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-010
- **Prereqs:** output-S44b  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 010 — HIGH
- Files: `src/Refactor/ApplyExtractor.php` (write sites :70-73, :361 + entry: is_link refusal, realpath-under-configured-root, applyDir absolutization+realpath check, 0644 chmod generated), (xg: shell `src/Cli/Command.php:529-530` + Config/ConfigLoader root key plumbing — required for "explicitly configured root, never CWD-default")
- Size: HH (90 min)
- Prereqs: S44.
- Acceptance: `--filter ApplyExtractorTest` — symlinked member file refused with diagnostic; member whose realpath escapes configured root refused; generated file mode 0644 (stat in test); applyDir symlink refused. Until the shell root-plumbing lands, constructor takes an explicit root (default ⇒ refuse all writes — fail-closed), which the test pins. GATE.

### once-S101 — Timeseries/Prometheus/diff-path reporter odds

- **Queue rank:** 228 of 247
- **Severity mix:** low=1
- **Size:** MM (~30 min)
- **Covers:** php-dup-finder-once-126
- **Prereqs:** output-S02, output-S03, output-S05, output-S20, output-S52  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Reporting/TimeseriesReporter.php, src/Reporting/PrometheusReporter.php, src/Reporting/JsonReporter.php (evidence diff join), src/Util/PathCanonical.php, tests/Unit/Reporting/{Timeseries,Prometheus,JsonReporter}Test.php
**Shared resources:** Timeseries w/ S38; Util/PathCanonical w/ S40/S87 (last small leg).
**Acceptance:** timeseries carries schema_version + no blank line on failure; Prometheus per-cluster id label gone (output-S20 aggregate default re-verify) — exposition parses; `--- a//abs` double-slash gone (join via PathCanonical); three unit pins.

### output-S39 — HtmlReporter: batched writes + one shared Differ + capture-group branching

- **Queue rank:** 229 of 247
- **Severity mix:** medium=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-output-041
- **Prereqs:** output-S27, output-S38  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 041
- Files: `src/Reporting/HtmlReporter.php` (:23-37, :257-271, :293-345), `tests/Unit/Reporting/HtmlReporterTest.php`
- Size: HH (120 min)
- Prereqs: S38 (source reads via store), S27 (observed rendering settled), S19 (scrub posture if page text shares helper — pages escape via htmlspecialchars already; soft).
- Acceptance: `--filter HtmlReporterTest` — HTML output bytes identical (full-page fixture diff before/after); Differ instance count == 1 per run (counter); N-cluster directory test writes same content with buffered/streaming writer; GATE.

### output-S40 — HtmlReporter highlighter: token_get_all, single keyword source, .c class, sort-column contract

- **Queue rank:** 230 of 247
- **Severity mix:** low=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-output-053
- **Prereqs:** output-S39  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 053
- Files: `src/Reporting/HtmlReporter.php` (:293-345, :361-376, :572), `tests/Unit/Reporting/HtmlReporterTest.php`
- Size: HH (120 min)
- Prereqs: S39 (same file; batching settled).
- Acceptance: `--filter HtmlReporterTest` — single-quoted strings with newlines tokenized atomically (fixture); `'use'` appears once in keyword source (`grep` assert + shared const single-list test); comment class `.c` now emitted (fixture comment renders wrapped); table sort test pins the column-index contract (JS contract documented + assertion on generated header markup); GATE.

### once-S60 — Checkstyle/PlantUML/HTML non-UTF-8 + injection closure

- **Queue rank:** 231 of 247
- **Severity mix:** medium=3
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-067, php-dup-finder-once-068, php-dup-finder-once-069
- **Prereqs:** output-S18, output-S40

**Files:** src/Reporting/CheckstyleReporter.php, src/Reporting/PlantumlReporter.php, src/Reporting/HtmlReporter.php, tests/Unit/Reporting/CheckstyleReporterTest.php, tests/Unit/Reporting/GraphReportersTest.php, tests/Unit/Reporting/HtmlReporterTest.php
**Shared resources:** HtmlReporter w/ S65/S84 — serialize S60 → S65 → S84.
**Acceptance:** Latin-1 + `\001` fixtures: checkstyle document parses strict XML, empty-name bug gone; plantuml label injects nothing top-level (quote/newline closure re-check of output-S18 leg); html <pre class='src'> non-empty for Latin-1 file (ENT_SUBSTITUTE post token_get_all).

### once-S84 — HTML affordances tell the truth

- **Queue rank:** 232 of 247
- **Severity mix:** medium=1
- **Size:** HH (~60 min)
- **Covers:** php-dup-finder-once-104
- **Prereqs:** once-S60, output-S35, output-S39, output-S40  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Reporting/HtmlReporter.php, tests/Unit/Reporting/HtmlReporterTest.php
**Shared resources:** HtmlReporter third writer (S60→S65→S84).
**Acceptance:** copy-button label == payload preview truth; clipboard handler await+catch (failure visible); zero-cluster report renders honest empty-state row not empty tbody; null-signature members have no data-copy=''.

### output-S50 — detectUnsafe: AST-based, fail-closed on unreadable members

- **Queue rank:** 233 of 247
- **Severity mix:** high=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-output-008
- **Prereqs:** output-S38, output-S44b  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 008 — HIGH
- Files: `src/Reporting/RefactorPatchReporter.php` (:193-214 rewrite: parse via same pipeline/loader, NodeFinder for `$this`, closure `$this` binding, ArrowFunction, `Arg->byRef`, `Static_`, `Global_`, Yield, Break_/Continue_ under hoist; text-heuristics demoted to unverified⇒unsafe), `tests/Unit/Reporting/RefactorPatchReporterTest.php`
- Size: HH (120 min)
- Prereqs: S44 (tally/branch stable), S38 (member reads through store; unreadable ⇒ explicitly unsafe verdict memo).
- Acceptance: `--filter RefactorPatchReporterTest` — unreadable-member file ⇒ cluster UNSAFE + reason (never silently safe); `bind($this, $x)` method ⇒ unsafe (PDREF repro fixture); `$this->` inside comment ⇒ NOT flagged; `fn() => $this->x` ⇒ flagged; comment-in-string false-positive case from old regex retired with pin; GATE.

### output-S51 — F1b apply gate: hole-coverage floor, degraded refusal, exactness requirement, refusal reasons in summary

- **Queue rank:** 234 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-output-020
- **Prereqs:** output-S26, output-S27, output-S50  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 020 — HIGH (gate-before-F1b)
- Files: `src/Reporting/RefactorPatchReporter.php` (apply branch :78-107 add gates), `src/Refactor/HoleCoverage.php` (threshold semantics consumed), tests: RefactorPatchReporterTest (gate matrix: coverage-below ⇒ refuse; degraded-hole ⇒ refuse; near-dups (non-exact) ⇒ refuse)
- Size: HH (90 min)
- Prereqs: S26/S27 (degraded representation), S50 (gate stack), Config::maxHoleCoverage surface already exists (Config.php:248) — no new key, no six-edit-site.
- Acceptance: `--filter RefactorPatchReporterTest` — engineered mostly-hole cluster and degraded cluster each produce named refusal reasons visible in summary; safe+exact+covered cluster still applies; GATE.

### output-S53 — end-to-end apply regression suite (the coverage this group always lacked)

- **Queue rank:** 235 of 247
- **Severity mix:** high=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-output-009
- **Prereqs:** output-S44b, output-S45, output-S46, output-S47, output-S48, output-S49b, output-S50, output-S51, output-S52  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 009 — HIGH
- Files: `tests/Integration/ApplyEndToEndTest.php` (new: multi-member same-file, method-in-class, namespaced call-site, strict_types runtime smoke, rollback-on-failure, `php -l` every buffer), possibly `tests/Fixtures/apply/` corpus (new fixtures)
- Size: HH (120 min)
- Prereqs: S44, S45, S46, S47, S48, S49, S50, S51, S52 (all).
- Acceptance: the suite itself IS the check — `vendor/bin/phpunit --testsuite Integration --filter ApplyEndToEndTest` green; it must be mutation-sensitive (spot-check: revert S47's FullyQualified change locally ⇒ namespaced case fails). The CLI exposure of `--apply` remains gated pending the (xg) shell/docs row — do NOT flip `applyDryRun` here. GATE.

### once-S04 — Real apply path: safety closure (dormant stays dormant until gated)

- **Queue rank:** 236 of 247
- **Severity mix:** critical=1
- **Size:** MM (~60 min)
- **Covers:** php-dup-finder-once-004
- **Prereqs:** output-S44b, output-S45, output-S46, output-S47, output-S48, output-S49b, output-S50, output-S51, output-S52 (corruption cures; S44b/S49b are the final legs of split parents), output-S53 (e2e lane), shell-S28  *(Phase-5 r1 retarget)*

**Files:** src/Cli/Command.php, src/Refactor/ApplyExtractor.php, tests/Integration/ApplyEndToEndTest.php
**Shared resources:** applyDryRun flip is output-S53's explicit "do NOT flip" row — this step keeps `applyDryRun=true` and adds refusal-safety around it; Command.php lock ledger; exit-class note for S95.
**Acceptance:** with a temporarily force-enabled /tmp harness (never the checkout): apply refuses on dirty target tree, backs up before write, post-rewrite `php -l` gate fails-closed with rollback; residual corruption legs from the finding (stale ranges, @-swallow, bare return) verified cured by output-chain tests; 'Applied: N' count equals re-parsed diff hunks. [OWNER: apply posture — refuse-vs-backup default recorded for Phase-5.]

### once-S29 — Apply surface wired honestly (--dry-run, config apply, applyDir root)

- **Queue rank:** 237 of 247
- **Severity mix:** high=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-033
- **Prereqs:** once-S04, output-S52, shell-S27, shell-S28  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Cli/Command.php, src/Cli/ConfigLoader.php (consumption leg w/ shell-S27), .gitignore, docs/CLI.md
**Shared resources:** applyDryRun stays true (flip gated by output-S51/S53 + S04 closure); .gitignore single row `refactored/`; F1a/F1b help scrub coordinate w/ S83.
**Acceptance:** `--dry-run` observable (writes nothing, distinct summary line), config-level `apply` honored same, applyDir resolved relative to scan root not cwd; refactored/ ignored by git; --help has no roadmap shorthand; exit 0 honest 'dry-run' phrasing.

### once-S88 — Cross-run apply collisions + atomic apply.diff

- **Queue rank:** 238 of 247
- **Severity mix:** medium=1
- **Size:** MM (~45 min)
- **Covers:** php-dup-finder-once-111
- **Prereqs:** once-S04, once-S29, output-S52  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** src/Refactor/FunctionNameRegistry.php, src/Reporting/RefactorPatchReporter.php, src/Cli/Command.php, tests/Unit/Refactor/FunctionNameRegistryTest.php
**Shared resources:** refactored/ dir reuse policy (S29 gitignore first) [OWNER: persist-registry vs refuse-existing-dir — default refuse-existing-dir loud].
**Acceptance:** two applies to same out-dir → second refuses w/ explanation (or persisted registry names collision-free — per ruling, redeclare-in-php -l test green); apply.diff written tmp+rename.

### once-S27 — Profile precedence = documented intermediate tier

- **Queue rank:** 239 of 247
- **Severity mix:** high=1
- **Size:** HH (~120 min)
- **Covers:** php-dup-finder-once-031
- **Prereqs:** shell-S22, shell-S25, once-S26, once-S29

**Files:** src/Cli/Command.php, src/Cli/Config.php, src/Cli/ConfigLoader.php, docs/CLI.md, tests/Unit/Cli/ProfilePrecedenceTest.php
**Shared resources:** Config layering shared w/ engine-S24/S25 rulings; [OWNER: add profile tier between defaults and file]; precedence diagram/docs row.
**Acceptance:** test matrix defaults<profile<config-file<CLI flag each wins/loses as documented; --profile no longer beats --config; no regression of shell-S22 per-dir layering.

### output-S54 — kill the silent-success write class: WritesReportFile fail-loud + route every writer

- **Queue rank:** 240 of 247
- **Severity mix:** high=1 medium=1
- **Size:** HH (~150 min)
- **Covers:** php-dup-finder-once-040, php-dup-finder-output-024
- **Prereqs:** output-S09, output-S36, output-S39, output-S40, output-S43, output-S44b, output-S49b  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: once-040, 024
- Files: `src/Reporting/WritesReportFile.php` (:38-42 byte-count check, :56 mkdir check; new `src/Reporting/ReportWriteException.php`), `src/Reporting/HtmlReporter.php`, `src/Reporting/DiffReporter.php`, `src/Reporting/RefactorPatchReporter.php`, `src/Reporting/RefactorTestReporter.php`, `src/Refactor/ApplyExtractor.php` (:70,73,361), `src/Testing/FuzzCorpusGenerator.php` (:36,47), tests: `tests/Unit/Reporting/WritesReportFileTest.php` (new) + touch-ups in the affected reporter tests
- Size: HH (150 min)
- Prereqs: header set (S09, S36, S39/S40, S43, S44b, S49b — apply write sites reshaped first; S54 after the S44b/S49b chain legs). S21/S22 are soft only after Phase-5 r1: their caliber rows moved to the once-S80 packet, no rules-file lock remains.
- Conflicts: (xg: src/Cli/Command.php mapping ReportWriteException to a `phpdup:` error line + exit code — shell row, report rider appended to once-S80 packet).
- Acceptance: `--filter WritesReportFileTest` — write into read-only dir (`chmod 0500` fixture dir) and a `/dev/full`-style short-write scenario each throw ReportWriteException with the target path and byte delta in the message; `@mkdir` suppression gone (`grep -c '@mkdir' src/Reporting/WritesReportFile.php` → 0); `grep -rn "file_put_contents" src/Reporting src/Refactor/ApplyExtractor.php src/Testing/FuzzCorpusGenerator.php` → all hits inside the trait or a documented stream-append exception (Timeseries LOCK_EX append may keep its own write but must check the return); behavior observable: `bin/phpdup analyze tests/Fixtures/notify --html /locked/dir` prints a named failure and exits non-zero with NO `phpdup html report →` line; reporter fixtures green; goldens byte-identical; GATE.


- **Phase-4 merge (absorbs once-S36):** once-040 (fail-loud write closure across ALL reporters). Same fix as output-024 raised by the once DOCSYNC/OBS lens; once-S36's acceptance is folded here: after routing all writers through the trait, run the read-only-target probe (chmod 555 /tmp dir) per reporter: exit 1, stderr `phpdup:` line, NO success string on stdout, and the grep census proves zero hand-rolled writers remain.
- Acceptance addendum (once-040): per-reporter chmod-555 probe matrix + holdout-trio grep census green.
- Prereq ruling: S21/S22 legs are soft (caliber rows moved to once-S80 packet at Phase-5 r1); keep S54 after S49b as written.

### once-S65 — Reporter dir writers: stale pages cleared, mkdir collision honest

- **Queue rank:** 241 of 247
- **Severity mix:** medium=1
- **Size:** MM (~40 min)
- **Covers:** php-dup-finder-once-074
- **Prereqs:** output-S54, output-S36

**Files:** src/Reporting/HtmlReporter.php, src/Reporting/RefactorPatchReporter.php, src/Reporting/RefactorTestReporter.php, tests/Unit/Reporting/HtmlReporterTest.php
**Acceptance:** temp dir run 8→2 clusters: final dir has exactly 2+index (no cluster-003..008 survivors — generation manifest or dir-owned wipe); target-path-is-a-file probe: `@mkdir` failure → exit 1 no success line (trait shared w/ S36 closure).

### once-S93 — Vacuous tests fixed (skips fail, real golden inputs, no sleeps-as-proof)

- **Queue rank:** 242 of 247
- **Severity mix:** medium=1
- **Size:** HH (~90 min)
- **Covers:** php-dup-finder-once-116
- **Prereqs:** engine-S01, once-S56, once-S65, once-S75  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** tests/Golden/GoldenTest.php, tests/Unit/Cli/SignalHandlerTest.php, tests/Unit/Watch/FileWatcherTest.php, tests/Unit/Persistence/IndexStoreTest.php, tests/Unit/Server/ApplicationTest.php, phpunit.xml, .github/workflows/ci.yml
**Shared resources:** ci.yml failOnSkipped leg (slot after S93? ci.yml ledger: S10→S11→S93→S56→S13); phpunit.xml own small edit window.
**Acceptance:** GoldenTest consumes tuned config (optional golden non-empty — engine-S01 output pinned here); @group network fails-not-passes when unreachable (CI job sets token or skips loudly); UPDATE_SNAPSHOTS marker honored (skipped ≠ silent); sleep-based tests → event-driven (inotify/poll seam), wall-time drops (assert run-mode flag not sleep count); ApplicationTest restores CWD (chdir leak fence); SignalHandlerTest leaves pcntl_async_signals as found.

### once-S95 — Exit-code doc unification + corpus prose-hygiene pass

- **Queue rank:** 243 of 247
- **Severity mix:** low=2
- **Size:** DD (~180 min)
- **Covers:** php-dup-finder-once-119, php-dup-finder-once-121
- **Prereqs:** once-S13, once-S18, once-S19, once-S29, once-S30, once-S34, once-S37, once-S50, once-S53, once-S57, once-S68, once-S75, once-S79, once-S83, once-S93  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** docs/CLI.md, README.md, docs/SERVER.md, docs/CI.md, docs/JETBRAINS_PLUGIN.md, docs/algorithms/clustering.md, docs/ML.md, docs/tutorials/**
**Shared resources:** single exit-code table everywhere (0/1/2/3/130; exit-1 double-class split per behavior shipped by now), prose corpus: CountPhrase helper use-audit, (s) hacks, snake-in-errors restated as flag names, '1 files' banners gone (S96 landed), threshold/3-notation vocab consistent, CI.md cache-key never-hits claim fixed (post S23), tutorials reachable from README, quickstart-overwrite-config example fixed.
**Acceptance:** `grep` census zero hits for forbidden prose patterns + exit tables == one source of truth (single block, others link-anchor); diff is docs-only; anchor census green w/ S13.

### once-S98 — Suite/build hygiene sweep

- **Queue rank:** 244 of 247
- **Severity mix:** low=1
- **Size:** DD (~180 min)
- **Covers:** php-dup-finder-once-123
- **Prereqs:** engine-S10, once-S44, once-S65, once-S76, once-S93, once-S94, shell-S10  *(Phase-4 adjustment; see Merged & re-routed)*

**Files:** `tests/Unit/Cli/CandidateBudgetFlagTest.php`, `tests/Unit/Parallel/PreprocessWorkerIsolationTest.php`, `tests/Unit/Parallel/WorkerPoolTest.php`, `tests/Unit/Refactor/ApplyExtractorTest.php`, `tests/Unit/Tui/TuiRunnerTest.php` (whole-tree `tests/` lock retired at Phase-5 r1, I9 — the five named litterers/subjects), build-phar.php, src/Cli/Command.php, src/Cli/InitCommand.php, src/Server/ServeCommand.php (legacy src/Cli/ServeCommand.php dedup w/ tests/Unit/Cli/ServeCommandTest.php vs tests/Unit/Server/ServeCommandTest.php naming), tests/Integration/EndToEndTest.php, phpunit.xml
**Shared resources:** EndToEndTest rewrite touches canonical FindingId shape (engine/S31 era ids — positional SUPERSEDED format retirement per plan_review); isAvailable serial branch; TedSizeGate wall-bound (engine-S10 timing model).
**Acceptance:** /tmp litter census: post-suite `phpdup-*` dirs ≤ bound + pruned by tests (worker @fclose timing fixed, candbudget/apply test dirs cleaned w/ providers); `--candidate-budget=-1` accepted both forms, init with nothing to do → 0; duplicate ServeCommand test names disambiguated (one file per class pair — check src/Cli/ServeCommand.php vs src/Server/? BOTH exist: tests/Unit/Cli/ServeCommandTest.php mirrors wrong class; fix import/target), wrong 'Bench' mirror corrected, `--fail-on-deprecation` in phpunit.xml; TuiRunnerTest exercises real run()/runWithModel(); isAvailable serial branch covered.

### output-S55 — reporter scaffold convergence (rule vs code, the four deviants)

- **Queue rank:** 245 of 247
- **Severity mix:** medium=1
- **Size:** HH (~180 min)
- **Covers:** php-dup-finder-output-023
- **Prereqs:** output-S36, output-S38, output-S39, output-S40, output-S43, output-S51, output-S54  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 023 — planning ruling embedded
- Files: `src/Reporting/DiffReporter.php` (add `writeTo(Report, string)` + `build(Report): array<string,string>` pure seam for its per-cluster files), `src/Reporting/RefactorTestReporter.php` (wrap `buildTest(Cluster)` in `build(Report): array` + `writeTo` + trait), `src/Reporting/HtmlReporter.php` (build seam returning path→content map; directory multi-file writer otherwise kept), `src/Reporting/RefactorPatchReporter.php` (entrypoint width — `writeTo(..., bool $apply, bool $dryRun)` reduced to a state-passed construction after S51 gates)
- Size: HH (180 min)
- Prereqs: S54 (fail-loud trait is the shape being converged on), S43, S38, S39, S40, S36 (per-file bytes settled), S51 (RefactorPatch entry surface final).
- Conflicts: default ruling = refactor code TO the rule + document the multi-file array-seam (silent divergence is the defect either way); the desired rule-paragraph text routes to the once-S80 packet — caliber file, owner applies (Phase-5 r1, I3/D3a).
- Acceptance: each of the four classes is `final` + has `writeTo(Report, string): void` calling `$this->writeFile(..., $this->build($report))` (`grep -n "use WritesReportFile" src/Reporting/*.php` → all reporters); output bytes per fixture byte-identical vs pre-change capture (report a diff -r of two runs to /tmp); GATE.
- **Phase-5 r1 (I3/D3a):** sanctioned multi-file shape paragraph → once-S80 packet rider — lane edits no caliber file.

### output-S56a — migrate pipeline-driven reporter tests to synthetic Reports — half A (pure emitters)

- **Queue rank:** 246 of 247
- **Severity mix:** medium=1
- **Size:** HH (~110 min)
- **Covers:** php-dup-finder-output-036
- **Prereqs:** output-S02, output-S03, output-S04, output-S05, output-S17, output-S18, output-S19, output-S20, output-S21, output-S22, output-S27, output-S28, output-S34, output-S35, output-S36, output-S37, output-S38, output-S39, output-S40, output-S41, output-S42, output-S43, output-S51, output-S54, output-S55  *(Phase-4 adjustment; see Merged & re-routed)*


- Covers: 036 (half A)
- Files: `tests/Unit/Reporting/CheckstyleReporterTest.php`, `tests/Unit/Reporting/CsvReporterTest.php`,
  `tests/Unit/Reporting/PrometheusReporterTest.php`, `tests/Unit/Reporting/TimeseriesReporterTest.php`
- Size: HH (110 min)
- Prereqs: the header set (all src/Reporting shape changes landed); this half runs first and output-S56b chains on it.
- Acceptance: the four pure emitters consume synthetic `Report`+`Cluster`+`Block` fixtures (CLAUDE.md
  Testing rule: in-memory Blocks); `grep -l "PreprocessStage\|ClusterStage\|ScanningStage" tests/Unit/Reporting/{Checkstyle,Csv,Prometheus,Timeseries}ReporterTest.php`
  → zero hits; assertions carried across, not weakened (reviewer diffs); Unit suite green and
  measurably faster (timing note in commit). Phase-4 split of DD output-S56 (finding order: pure
  emitters first).

### output-S56b — migrate pipeline-driven reporter tests to synthetic Reports — half B (renderers)

- **Queue rank:** 247 of 247
- **Severity mix:** medium=1
- **Size:** HH (~190 min)
- **Covers:** php-dup-finder-output-036
- **Prereqs:** output-S02, output-S03, output-S04, output-S05, output-S17, output-S18, output-S19, output-S20, output-S21, output-S22, output-S27, output-S28, output-S34, output-S35, output-S36, output-S37, output-S38, output-S39, output-S40, output-S41, output-S42, output-S43, output-S51, output-S54, output-S55, output-S56a  *(Phase-4 adjustment; see Merged & re-routed)*

- Covers: 036
- Files: `tests/Unit/Reporting/{CheckstyleReporterTest,CliReporterVerbosityTest,CsvReporterTest,DiffReporterTest,GitLabSastReporterTest,GraphReportersTest,HtmlReporterTest,JsonReporterTest,PrometheusReporterTest,SarifReporterTest,TimeseriesReporterTest}.php`
- Size: HH (190 min)
- Prereqs: preceded by output-S56a plus the header set (all src/Reporting shape changes landed); the emitter half is S56a — this half is the pipeline-driven renderers.
- Conflicts: `new Block(` fixtures must stay in-memory (CLAUDE.md Testing rule); Fuzz/Golden suites untouched.
- Acceptance: `grep -l "PreprocessStage\|ClusterStage\|ScanningStage" tests/Unit/Reporting/*.php` → zero hits; each migrated test still pins the output bytes it pinned before (assertions carried across, not weakened — reviewer diffs a sample of 3); `--testsuite Unit` fully green and measurably faster (before/after timing note in commit); GATE.


---

## Merged & re-routed (Phase-4 audit, Phase-5 r1 updates)

**Merged across groups (same defect, union of finding IDs kept):**
- `once-S01` (001 · PHP-dup apply/exit chain) → absorbed into `shell-S01`; est re-derived 160 min.
- `once-S36` (040 · TimeseriesReporter append race) → absorbed into `output-S54`; probe addendum appended; est 150.
- `once-S81` (101 · .claude skills+rules repair) → absorbed into `once-S80` packet at Phase-5 r1 (I3/D3a): lanes edit no caliber paths; desired text ships as packet rider rows; once-S80 est 45→105. All `.claude/` lock tokens dropped from shell-S09/S37, output-S21/S22/S55; the S82 untrack pair became owner actions.
- `output-S23` (015) + `output-S24` (040-out) → absorbed into `engine-S16` — both groups explicitly requested ONE BlockAstLoader lane; merged step is DD/280, prereq engine-S04 — split at Phase-5 r1 into engine-S16a/S16b (see Split below).

**Split (DD too large for one agent):**
- `engine-S31` (DD 360, catalogue-wide delete-or-wire) → `engine-S31a` (HH 150, ledger + Semantic/Similarity/LshIndex/MinHash) → `engine-S31b` (HH 120, Index/IR pair) → `engine-S31c` (HH 130, Normalization/TokenCache/Dataflow + final census). Dependents re-aimed at S31c: once-S32, once-S68, once-S79, once-S89, once-S102.
- `output-S56` (DD 300) → `output-S56a` (HH 110, pure emitters Checkstyle/Csv/Prometheus/Timeseries) + `output-S56b` (HH 190, remainder; keeps S56a plus the full original prereq set). output-036 legs in both halves.
- `engine-S02` (DD 300) → `engine-S02a` (HH 150, writer-side sentinel redesign — canonicalizeNames per-arm/positional key sentinels, kills the engine-055 double-rename, `__V0` alias guard, Normalizer unit pins.) + `engine-S02b` (HH 150, GOLDEN-STEP re-pin leg — regenerate the three goldens with disclosed per-snapshot diffs once the writer bytes settle; owns the `tests/Golden/` token.). Dependents re-aimed at S02b: engine-S03, engine-S34a.
- `engine-S06` (DD 300) → `engine-S06a` (HH 160, receiver-evidence gate — folding the get/all/save tier out of pure-name matching (store-vs-logger evidence), CustomDbSymbols + DbOpCanonicalizer pins.) + `engine-S06b` (HH 140, column-set synthetic signature + registry entry shape + profiles/docs/config-schema rows + DbOpRegistryTest; closes both repros.). Dependents re-aimed at S06b: engine-S05, once-S100.
- `engine-S08` (DD 300) → `engine-S08a` (HH 150, flatten/orderedChildren fix + distance>0 + permuted<1.0000 pins + EditCostModel note (core APTED semantics).) + `engine-S08b` (HH 150, ClustererRunningMin tier pin + optional-near-dups regen-if-shift (GOLDEN token) + docs/algorithms consistency check.). Dependents re-aimed at S08b: engine-S09.
- `engine-S16` (DD 280) → `engine-S16a` (HH 140, single-walk findStrict + NodeTraverser::STOP_TRAVERSAL at match sites + batched span collection.) + `engine-S16b` (HH 140, spl_object_id candidate memo per pass + normalize()-count drops + census pins (memo over the final clone machinery).). Dependents re-aimed at S16b: engine-S17a.
- `engine-S17` (DD 300) → `engine-S17a` (HH 140, hint/anchor + escalation→always-skip+note in BlockAstLoader + unit pins (no opt-in flag).) + `engine-S17b` (HH 160, vendor probe transcript + tests/Integration e2e + locate run exit 0.). Dependents re-aimed at S17b: once-S37, once-S87.
- `engine-S18` (DD 300) → `engine-S18a` (HH 140, int postings in NgramInvertedIndex.) + `engine-S18b` (HH 160, gram dictionary/int-bag default + Block.php docblocks + cache-version roster + RSS re-measure.). Dependents re-aimed at S18b: engine-S33, once-S68.
- `engine-S19` (DD 300) → `engine-S19a` (HH 150, SSRF gate rebuild — canonical-literal-or-resolve, inet_pton matrices, unit refuse-list matrix.) + `engine-S19b` (HH 150, write-callback size caps + CURLOPT_RESOLVE/bindto pinning + oversized-body test + fail-open→fail-closed flip.). Dependents re-aimed at S19b: engine-S20, once-S53, once-S54, once-S89, once-S92.
- `engine-S23` (DD 270) → `engine-S23a` (HH 150, crash guard + nested decode + framing/seal + AstCache perms + shared helper home + AstCacheTest + 10k-depth exit 0.) + `engine-S23b` (HH 120, TokenCache parity via the shared helper + tampered→miss + 0700/0600 census under umask 002 + allow-list interplay re-run.). Dependents re-aimed at S23b: engine-S24, engine-S26, engine-S39, engine-S31a, engine-S31c, once-S64.
- `engine-S34` (DD 300) → `engine-S34a` (HH 140, prefix-free stream (`s:<len>:<raw>` incl. `L:` segments) + property tests.) + `engine-S34b` (HH 160, cache-identity roster bump — `.phpdup-cache-key.version` + `src/Fingerprint/FingerprintIdMode.php` + FingerprintIdModeConfigTest + GOLDEN re-pin.). Dependents re-aimed at S34b: engine-S33, once-S37, once-S87.
- `output-S44` (DD 300) → `output-S44a` (HH 150, single-pass per-file rewrite — group by file, one parse/prettyPrint, count-verify, `@`-swallow gone, diagnostics for the :332-351 silent returns.) + `output-S44b` (HH 150, transaction + rollback (snapshots restored byte-for-byte) + RefactorPatchReporter :99-113 honest tallies.). Dependents re-aimed at S44b: output-S45..S48/S50/S52/S53/S54, once-S04 (all final-leg references).
- `output-S49` (DD 300) → `output-S49a` (HH 150, intersected-params derivation in AntiUnifier + SignatureBuilder consumption.) + `output-S49b` (HH 150, ApplyExtractor param list + call-site wiring + strict_types smoke + goldens zero-move check.). Dependents re-aimed at S49b: output-S53, output-S54, once-S04.

**New step minted from passthrough:** `output-S57` (HH 180) — RefactorStage/AntiUnifier member-count cap, from the engine group's "Outside this group" ENGINE-FUZZ-X01 row (owner group: output).

**"Outside this group" re-routing (note appended to the owning step body):**
- `engine-S01` ← **Phase-4 Outside merge:** ENGINE-PDDETECT out-of-group 1 (golden governance) — the optional-near-dups re-pin must be a separate disclosed diff in this lane; never let it silently re-pin behind another step's UPDATE_SNAP…
- `engine-S10` ← **Phase-4 Outside context:** shell's (engine) row 'ClusterStage APTED cost ~1,241 s on the saturated synthetic corpus' is sized INTO this step's wall-time budget (post-S09 memo costs).
- `engine-S18` ← **Phase-4 Outside memos:** ENGINE-PERF out-of-group 2 (int-bag default-flip threading lands at the PreprocessStage/PreprocessWorker wiring layer = shell group; cross-group rows stay report-only here) + output's (engine) …
- `engine-S21` ← **Phase-4 Outside merge:** ENGINE-PERF out-of-group 1 — PairScoreWorker's individual-retry-after-batch-miss path inherits the per-pair DNS/handshake cost; the client-instance memo makes it pay only if the worker builds a…
- `engine-S39` ← **Phase-4 merge (allow-list posture):** shell-052(a) (curated set replaces the composer-classmap sweep) is the SAME defect as engine-058 viewed from the shell side — this step owns the curation; shell-S39 re-verifies dow…
- `once-S10` ← **Phase-4 Outside merge:** output's (once, DOCSYNC) row — composer.json `^8.3` vs CLAUDE.md/AGENTS.md 'PHP 8.1 floor' — this step owns the manifest truth; doc prose rides the S80 packet.
- `once-S23` ← **Phase-4 Outside merge:** ENGINE-CONV-04 — the IndexStore config-key serialize list at PreprocessStage.php:82-109 omits `normalizationPlugins` (violating the CLAUDE.md plugin contract); add the key + extend this step's …
- `once-S25` ← **Phase-4 Outside merge:** shell's (engine) row — the Clusterer.php:723 serial scoring path gets the fork/serial parity test that shell-S14 ships for the worker side; add the weights-specific serial case here (this step …
- `once-S30` ← **Phase-4 Outside merge:** output's (once, shell-adjacent) row — src/Cli/UpdateCommand.php:487 '0.0.0' fallback literal joins the single-source version family (Util/Version from output-S03 feeds it).
- `once-S64` ← **Phase-4 Outside merge:** ENGINE-SEC out-of-group 1's IndexStore/ClusterCache content-addressed trust half lands here (posture + empty-blob miss + prune decision), built on the shell-S18/S19 read path and engine-S23/S24…
- `once-S67` ← **Phase-4 Outside merge:** ENGINE-PHP out-of-group memo — the untrusted-config plugin-class-name admission (which names a .phpdup.json may name; what a hostile ctor does at load) is inventoried + closed by this matrix st…
- `once-S80` ← **Phase-4 Outside merge:** output's (once, DOC-DEV) rows join the packet — CLAUDE.md Block mutable-fields list omits $rangeHash (caliber twins drifted) and the caliber pre-commit-hook governance gap (no mechanical enforc…
- `output-S01` ← **Phase-4 Outside merge (formalized):** engine's psalm-baseline dead entries (BlockExtractor ×2, FileScanner ×1) fold into THIS single tree-wide re-baseline — Phase-4 ruling: no second baseline lane.
- `output-S05` ← **Phase-4 merge (law):** engine/determinism out-of-group row — 'timestamps inside golden/serial artifacts must be UTC' — this step ships the Timeseries half; the law is recorded in ## Program laws for every artifact-touc…
- `output-S11` ← **Phase-4 Outside merge:** shell's (output) row — Ranker.php:42-43 minSafety range re-throw duplicates the CLI-side admission contract; drop the crash leg here (shell-S24 removes its surface).
- `output-S16` ← **Phase-4 note:** once-S09 (benchmark family-granular recall) consumes this tolerance-bucket core — it depends on output-S16.
- `output-S43` ← **Phase-4 Outside merge:** engine's row 'ReportStage.php:283-284 prints `apply diff →` while the F1b branch writes a non-diff SUMMARY' — fix the announcement line to follow the new artifact names in this lane (files += s…
- `output-S48` ← **Phase-4 Outside merge:** ENGINE-SEC out-of-group 3 — --apply must never consume block ASTs from cache without the fresh-bytes relocation check; the pre-write re-parse guard here is that check's apply-side half (cache-s…
- `shell-S01` ← **Phase-4 Outside merge:** ENGINE-SEC out-of-group 4 (serve job payloads setting ml_pair_url) — add an admission leg: enumerate which config fields the `RUN <jobId> <configJson>` frame accepts; any ml_pair_url admission …
- `shell-S02` ← **Phase-4 note:** ENGINE-SEC out-of-group-1's phpdoc-worker socket-pair confirmation leg rides shell-S39(c) (bind race) + engine-S39 (allow-list).
- `shell-S11` ← **Phase-4 Outside memo:** ENGINE-PERF out-of-group 3 is an explicit no-defect audit memo (parserClasses already memoized) — recorded so nobody double-files it; this step's memo helper is its home.
- `shell-S19` ← **Phase-4 Outside merge:** ENGINE-SEC out-of-group 1 routes the IndexStore/ClusterCache harder landing here (shape guards) + once-S64 (trust posture, empty-blob, prune) + shell-S39(b) (dir perms). WorkerPool fork-transpo…
- `shell-S20` ← **Phase-4 Outside merge:** this step IS the durable root-cause fix for output's '(shell, root-cause) FileScanner FOLLOW_SYMLINKS :63' row — output-S38 (read-side half) and output-S52 (write-side half) depend on this land…
- `shell-S22` ← **Phase-4 Outside merge:** ENGINE-SEC out-of-group 2 (debug_log/baseline/baseline_out/diff_base admitted per-dir) — this step's root-only refusal IS the fix; the confinement-check half lands via shell-S23 (diff_base git …
- `shell-S25` ← **Phase-4 Outside merge:** ENGINE-PDDETECT out-of-group 2 — AutoTuner tiny-corpus tier (:71) relaxes max_df to 0.5 which stays dead for N ≤ 3: floor the tier's candidate generation the same way engine-S01 floors candidat…
- `shell-S28` ← **Phase-4 Outside merge:** ENGINE-FUZZ-X02 — reconcile the lying `--no-cache` option text at Command.php:127 with the engine-S26 behavior that now ships (worker path truly disables writes).

**Prerequisite corrections applied at plan time** (annotator sets were replaced/extended; final sets shown — reason recorded in each affected step body; self-references stripped):

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

- `once-S04` → final prereqs: output-S44b, output-S45, output-S46, output-S47, output-S48, output-S49b, output-S50, output-S51, output-S52, output-S53, shell-S28
- `once-S23` → final prereqs: engine-S24, shell-S16, shell-S37
- `once-S32` → final prereqs: engine-S31c
- `once-S33` → final prereqs: engine-S09, engine-S10, engine-S11, shell-S37
- `once-S41` → final prereqs: once-S37, shell-S01, shell-S20
- `once-S54` → final prereqs: engine-S19b, engine-S20, once-S48, shell-S22
- `once-S67` → final prereqs: once-S28, once-S54, once-S66, shell-S22
- `once-S87` → final prereqs: engine-S17b, engine-S34b, engine-S38, shell-S38
- `once-S93` → final prereqs: engine-S01, once-S56, once-S65, once-S75
- `output-S22` → final prereqs: output-S18
- `output-S27` → final prereqs: output-S26
- `output-S33` → final prereqs: output-S32
- `output-S38` → final prereqs: output-S18, output-S22
- `output-S39` → final prereqs: output-S27, output-S38
- `output-S40` → final prereqs: output-S39
- `output-S48` → final prereqs: output-S44b
- `output-S54` → final prereqs: output-S09, output-S36, output-S39, output-S40, output-S43, output-S44b, output-S49b
- `shell-S17` → final prereqs: shell-S16
- `shell-S22` → final prereqs: shell-S17, shell-S26
- `shell-S39` → final prereqs: engine-S39, shell-S01, shell-S02, shell-S08, shell-S11, shell-S18
- `shell-S40` → final prereqs: shell-S33

## Deferred with reason

Nothing was silently dropped: every item below is either refuted-at-consolidation (kept as a record so Phase-4/5 need not re-litigate), opinion-not-defect, or owner-routed. The findings files are the full authority.

### Deferred from `engine` consolidation



### Deferred from `output` consolidation



### Deferred from `shell` consolidation



### Deferred from `once` consolidation



### Annotations-file tail preserved after `shell-S21` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Config / CLI surface

### Annotations-file tail preserved after `shell-S32` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Watch / TUI

### Annotations-file tail preserved after `shell-S30` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Scanning

### Annotations-file tail preserved after `shell-S40` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Housekeeping / roll-ups / facade / packaging

### Annotations-file tail preserved after `shell-S09` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Parallel execution (WorkerPool / workers / stages)

### Annotations-file tail preserved after `engine-S39` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Steps deliberately NOT taken from this file

- All "## Outside this group" rows (CONV-04 config-key omission → shell; IndexStore/ClusterCache
  trust model → shell; ConfigLoader path admission → shell; ApplyExtractor/serve-job → output/shell-
  NET; PairScoreWorker retry cost note → shell; RefactorStage O(m²) → output; --no-cache help copy →
  shell; untrusted-config plugin policy → once; optional-near-dups golden governance → once/this
  plan's S01; AutoTuner tiny-tier → shell; catalog ownership split → once).
- All "## Verified non-issues" — nothing to do (note: S15's 054 half respects these strikes).
- engine-032's *serial-reroute-through-batching* is scoped client-internally in S21; the full
  consumer-side reroute touching Clusterer.php is deferred as a noted S21 contingency (chain join).

- **Phase-4 merge (allow-list posture):** shell-052(a) (curated set replaces the composer-classmap sweep) is the SAME defect as engine-058 viewed from the shell side — this step owns the curation; shell-S39 re-verifies downstream (WorkerPool + both caches).

### Annotations-file tail preserved after `shell-S39` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Sorting aid (est. minutes; P4 may re-sort — deps must hold)

| Step | Min | Deps (same-group unless noted) |
|------|-----|-------------------------------|
| S38  | 10  | — |
| S29  | 15  | — |
| S07  | 15  | S02 |
| S13  | 25  | S12 |
| S03  | 45  | S02 |
| S09  | 30  | S01, S08 |
| S28  | 30  | S24, S27 |
| S37  | 30  | S12, S15, S16, S23, S30, S34 |
| S06  | 35  | — |
| S04  | 40  | S03 |
| S12  | 40  | S10, S11, S14, S15 |
| S20  | 40  | — |
| S21  | 40  | S20 |
| S27  | 40  | S22, S24 |
| S35  | 45  | S03, S09 |
| S36  | 40  | — |
| S39  | 60  | S01, S02, S08, S11, S18 |
| S40  | 40  | S33 |
| S11  | 45  | S10 |
| S14  | 45  | — |
| S16  | 45  | S15 |
| S17  | 45  | S16 |
| S19  | 60  | S11, S18 |
| S22  | 45  | S17, S26 |
| S23  | 45  | — |
| S25  | 45  | S24 |
| S31  | 45  | S10, S22, S26, S27, S30 |
| S32  | 45  | — |
| S34  | 45  | S20, S21, S23, S28, S33 |
| S02  | 60  | S01 |
| S05  | 50  | S04 |
| S15  | 60  | — |
| S24  | 60  | S22, S23 |
| S30  | 60  | S14, S18 |
| S01  | 150 | — |
| S08  | 120 | S01, S02, S07 |
| S10  | 120 | — |
| S18  | 120 | — |
| S26  | 90  | — |
| S33  | 120 | — |

Immediately startable (zero deps): S01, S06, S10, S14, S15, S18, S20, S23, S26, S29, S32, S33, S36, S38.

### Annotations-file tail preserved after `once-S102` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Phase-4 merge notes

1. **Execution waves suggested** (shortest-first respecting prereqs, not binding):
   W1: S01,S20,S49,S52,S69,S78,S46,S41 + server base S02,S03 · W2: S05,S06,S08,S17,S19,S22,S25,S30,S36,S38,S51 · W3: S07,S09,S10,S14,S15,S16,S18,S23,S26,S34,S39,S43,S44,S45,S48,S50,S56,S57,S58,S59,S60,S65,S66,S76,S77,S79,S80,S82 · W4: S04,S11,S12,S21,S24,S27,S28,S29,S31,S32,S35,S42,S54,S64,S68,S83,S87,S88,S90,S91,S92,S96,S97,S100,S101 · W5: S13,S33,S70,S71,S73,S74,S75,S84,S85,S86,S89,S93 · W6: S47,S61,S62,S63,S72,S94,S95,S98,S99,S102.
2. **Serial locks that must NOT overlap** (file-ownership): composer.json (S10→S11), release.yml (S05→S11→S12), ci.yml (S10→S11→S93→S56→S13), action.yml (S15→S14), profiles/ (S16→S100), TUI (S07→S21→S22→S61→S62→S63→S72→S96), Ml/* (S48→S54→S70→S89→S90→S92), UpdateCommand (S05→S12→S91→S99), docs/SERVER.md + docs/CLI.md + docs/config-schema.json chains per ledger above, Command.php once-chain listed in ledger.
3. **Schema/golden single window**: output-S27 (1.7) → once-S37 (1.8 owner, GOLDEN-MOVES) → once-S55 (+same 1.8) → once-S39/S95 (docs regen from final schema). No other step bumps SCHEMA_VERSION or touches tests/Golden.
4. **engine-S34 goldens** (AstSerializer stream) must land BEFORE once-S37 golden regen (one combined re-pin event, else two).
5. **apply chain integrity**: output-S44..S53 → once-S04 → once-S29 → once-S88 — never enable applyDryRun without all four closed + Phase-5 GO.
6. **Report-only rows owed** (owner/caliber lanes at closeout): AGENTS/CLAUDE re-derivation (S80 packet), improve_logic_info.md self-contradiction + §8 collision-map appends for once-S68/S83/S103-adjacent, composer.lock policy (S11), BlockAstLoader 4th-mirror checklist (S100 rider), .vhs re-render if TUI screens recorded differ (S61/S72 disclosure), home-my corpus pin staleness (S86).

#### Report-only lines (never steps)

- **AGENTS.md / CLAUDE.md** — all 100-claims via S80 packet only.
- **improve_logic.md / improve_logic_info.md** — owner files (staged) — contradiction/§8 rows reported, not edited.
- **code-chat/prompt-kit style meta files** — out of scope entirely.
- **Dropped section items** — no step (REL-07, TUI-09, D-10-15kinds, GAP-05-apply-wired, UXD theme-verified, RELEASE/UPDATE.md content, D-09-exact-counts, QA-M2 scanner-loop, 08307a74f citations, ARCHITECTURE.md-premised, DW-14, API-02-emphasis, DOCP-13-timeout, GAP-08-standalone, UP-05..10 standalone, PKG-16, API-04-validator).
- **118** — pointer-only record (legs consolidated: 020→S17, 084→S54, 124→S99); no independent work.

#### OWNER/DECISION rows for Phase-5

S04 apply refusal posture (clean-tree/back-up) · S05 keypair generation + secret custody ·
S11 sugarcraft tag freeze vs hash-record compromise · S15 canonical fail-on-impact formula ·
S18 hand-rows+parity-test (chosen) vs generated CLI.md · S27 profile tier precedence (add, as
documented) · S28 per-dir exclude implement-vs-drop · S31 box.json delete-vs-wire ·
S32+S89+engine-S31 shared wire-or-delete adjudication (Semantic, Validation, MlClient) ·
S33 budget-before-generation semantics · S43 serve concurrency model (default documented-serial) ·
S54 --ml-pair-allow-private default-off (chosen) · S64 prune flag vs TTL vs documented rm ·
S68 min_segment_length implement-vs-document · S71 default-token vs documented-local-only ·
S72 cooperative ReportStage architecture · S82 root-doc git-rm vs archive move ·
S88 persisted-registry vs refuse-existing-dir · S99 keep-or-remove --pre.

#### Bucket tally (recounted at write)

MM=65 · HH=30 · DD=7 · steps=102.

### Annotations-file tail preserved after `output-S56b` (not steps; listed here for Phase-5)

- **Banner (Phase-5 r1):** Historical annotation text — superseded; the queue and catalogue govern. Bucket/step tallies, chain orders, and startable lists below are pre-merge artifacts (see ## Merged & re-routed for the corrected audit).

#### Phase-4 merge notes

- Finding **052** is split deliberately: Graphviz/Plantuml import legs inside S18, the PatternRecognizer `Hole` import leg inside S31 (that finding already owns the file's housekeeping bullet).
- Golden movers: S27 (semantic, + SCHEMA_VERSION bump), S37 (signature value bytes), S31/S28/S49 (expected zero-move; must run Golden suite WITHOUT UPDATE to prove it, regen only on witnessed movement, disclosed).
- The apply chain S44→S53 is one serial workstream; S45/S48/S49 are DD and each may be split by the planner at file-region level if a lane proves too large — prerequisites were sized for whole-finding coherence.
- S23/S24 share BlockAstLoader with the engine group's loader backlog (Outside row); S01 shares the psalm baseline; S38/S34/S42/S52 touch engine/shell files through seams already listed — Phase-4 must assign each hot file to exactly one lane.
- Cross-group prereq rows to materialize once shell/engine IDs exist: Command.php:529-530 (S52, S53), FileScanner FOLLOW_SYMLINKS (S38 durable half), ReportStage:283-284 announcement (S43), UpdateCommand:487 (S03), UTC-artifact law (S05).

- Phase-4 note: half B of the split DD (covers output-036 jointly with output-S56a).

**Plan-level deferrals:** engine-032 (serial per-pair scoring) lands inside engine-S10/S15 measurement legs, not a standalone step; once-118 is a pointer-record (no code); CONV-10 sub-claim guarded against double-count with output-021; ENGINE-PERF outside-row #3 was a no-defect memo (routed into engine-S10 body note, nothing to build); report-only lines with no repo change stay report-only (their steps say so).

## Owner decisions (Phase-5 gate — from once OWNER/DECISION rows + engine riders)

Steps marked [OWNER] in their bodies execute the recorded DEFAULT; once-S72 carries [OWNER PRE-GATE] — owner approval BEFORE execution:

- once-S04 apply refusal posture: clean-tree refusal vs auto-back-up (default: refuse + document). · once-S05 keypair generation + secret custody for REL signing. · once-S11 sugarcraft `dev-master` tag freeze vs recorded-hash compromise. · once-S15 canonical `fail-on-impact` formula for the CI gate. · once-S18 hand-authored CLI.md rows + parity test (chosen default) vs generated doc. · once-S27 profile tier precedence: add the documented tier (default) vs drop the doc. · once-S28 per-dir `exclude`: implement reset-semantics vs drop the promise. · once-S31 box.json: delete vs wire (stub missing). · once-S32 + once-S89 + engine-S31a/b/c: shared wire-or-delete adjudication for Semantic / Validation / MlClient classes (ledger decided per class before any code move). · once-S33 budget-before-generation semantics (pairs generated vs pairs scored). · once-S43 serve concurrency model (default: documented serial). · once-S54 `--ml-pair-allow-private` default-off (chosen). · once-S64 cache prune: flag vs TTL vs documented `rm`. · once-S68 min_segment_length: implement vs document. · once-S71 default-token vs documented local-only bind. · once-S72 cooperative-ReportStage architecture change (approval BEFORE execution). · once-S82 root planning docs: git-rm vs archive move (default: rm). · once-S88 persisted plugin registry vs refuse-existing-dir (default: refuse). · once-S99 keep vs remove `--pre` (default: remove).
- engine-S10: aggregate-TED budget default + whether `DEFAULT_MAX_TED_SIZE` 800→300–400. · engine-S25: cache-default relocation vs refuse-read-with-flag. · engine-S31a/b/c: per-class delete-vs-wire (the ledger this lane writes IS the owner decision surface).
- Apply-chain gate: once-S04 + once-S29 + output-S51/S53 must ALL be closed before any flip of `applyDryRun` default; the flip itself is an owner row.
- **Owner actions (not steps):** untrack `.claude/settings.json` + `.claude/scheduled_tasks.lock` (once-S82 residue, D3a) and apply the once-S80 packet's desired `.claude/` text — lanes never edit caliber paths.

## Validation (checks run at plan-build)

- Kahn topological enumeration processed all 247/247 steps ⇒ prerequisite graph is acyclic; an independent three-colour DFS back-edge probe found zero cycles. (Re-run at Phase-5 r1 after the splits, the once-S81 absorb, and the once-S72→once-S96 edge.) Row order now follows owner ruling D1(b): severity-first Kahn — pop-key (CRITICAL-ownership, est_min, id) min-heap over the ready set, so every ready step whose Covers include a CRITICAL finding pops before any non-critical ready step, and otherwise the former shortest-first Kahn(est_min, id) law stands unchanged. Re-derived at the Phase-5 owner-decision pass: 0 prereq-order violations; the five critical roots (engine-S01, engine-S02a, engine-S23a, engine-S06a, shell-S01) and their critical-inheriting b-legs hold rows 1–8; once-S05 rides the slot immediately after its lone prereq shell-S36.
- Every prereq ID exists in the queue (0 dangling). Every final prereq set contains only live steps (deleted/split IDs re-aimed: once-S01→shell-S01, engine-S31→S31c, and at Phase-5 r1 the 11 split parents→their -b legs — dependents per the Split rows above).
- 304/304 consolidated findings covered by a step (output-052 deliberately spans two legs: output-S18 + output-S31; once-101 now rides once-S80 after the Phase-5 r1 absorb; each split finding rides both legs of its parent — the appendix marks the pairs). The appendix below is the ID→step map.
- All est_min values are positive integers (total 21,005 min across 247 steps); bucket labels MM=123 HH=107 DD=17 are the annotation size classes carried per step (labels are not a threshold on est_min — e.g. 110–240 appear under MM/HH/DD alike at plan heritage; the queue numbers are the binding estimates).
- Lock-path existence sampled against `git ls-files` (sample listed in the build log, incl. legitimately-new files such as `src/Util/Json.php`, `src/Util/Version.php`, `src/Util/ControlText.php`, `src/Util/Framing.php`, `src/Reporting/SourceTextStore.php`, `src/Reporting/Slug.php`, `tests/Unit/Parsing/AstCacheTest.php`, `tests/Unit/Bench/BenchmarkScoreCalculatorTest.php`, `.gitattributes` — these do not exist yet by design). Phase-5 r1 roster adds two more legitimately-new paths: `src/Fingerprint/FingerprintIdMode.php` and root `.phpdup-cache-key.version` (engine-S34b). Dotted CI tokens fixed repo-wide (`.github/…`, `.gitlab/…`). Ambiguous basename locks were qualified to repo-relative paths (Block.php→src/Extraction/Block.php etc.); root files (README.md, composer.json, build-phar.php, box.json, psalm-baseline.xml, phpunit.xml) lock by root-relative name.
- `tests/Golden/` overlap token is carried by the regen lanes ONLY (13 steps: engine-S01, engine-S02b, engine-S08b, once-S56, engine-S34b, engine-S15, engine-S10, output-S27, output-S28, once-S37, once-S55, output-S37, once-S93) — Phase-5 r1 I10 stripped it from verify-only once-S102 and kept it off both halves of splits except engine-S02b/engine-S08b/engine-S34b. Steps with no file writes lock `REPORT-ONLY` (only once-S80).
- TUI serialization spine once-S07→once-S21→once-S22→once-S61→once-S62→once-S63→once-S72→once-S96 is eight steps with seven handoffs; the once-S72→once-S96 queue edge (added at Phase-5 r1, I11) makes the terminal handoff machine-enforced — other spine links ride shared locks + queue position.
- Steps sharing a lock token but no prereq edge are deliberately unordered; the scheduler's lock-exclusion serializes them and per-body "Shared resources" notes carry any read-ordering intent. This is by design (Phase-5 r1 I11).
- **Step-size ceiling (owner ruling D2(a)):** no step may carry `est_min` > 240 min — any such step must be split into 2–3 legs at a natural boundary before entering the queue (Split banner row + per-leg `Covers` carve so the 304/304 finding bijection still holds exactly + leg-specific locks ⊆ the parent's + dependents re-aimed at the leg that actually gates them + intra-leg prereq edges serializing same-file writers — the engine-S34a/S34b precedent). Census over the final queue: 0 rows exceed 240 min (max = 240, 13 rows) — the Phase-5 r1 splits already brought every former 270–360 min DD parent under the ceiling, so no further splits were required at the owner-decision pass.

## Coverage appendix (finding → owning step)

**engine** (58):

| finding | step |
|---|---|
| 001 | engine-S01 |
| 002 | engine-S02a + engine-S02b |
| 003 | engine-S06a + engine-S06b |
| 004 | engine-S23a + engine-S23b |
| 005 | engine-S07 |
| 006 | engine-S25 |
| 007 | engine-S08a + engine-S08b |
| 008 | engine-S04 |
| 009 | engine-S09 |
| 010 | engine-S10 |
| 011 | engine-S18a + engine-S18b |
| 012 | engine-S19a + engine-S19b |
| 013 | engine-S20 |
| 014 | engine-S20 |
| 015 | engine-S19a + engine-S19b |
| 016 | engine-S24 |
| 017 | engine-S23a + engine-S23b |
| 018 | engine-S23a + engine-S23b |
| 019 | engine-S26 |
| 020 | engine-S23a + engine-S23b |
| 021 | engine-S28 |
| 022 | engine-S28 |
| 023 | engine-S12 |
| 024 | engine-S29 |
| 025 | engine-S29 |
| 026 | engine-S27 |
| 027 | engine-S31a |
| 028 | engine-S31a |
| 029 | engine-S11 |
| 030 | engine-S04 |
| 031 | engine-S16a + engine-S16b |
| 032 | engine-S21 |
| 033 | engine-S22 |
| 034 | engine-S33 |
| 035 | engine-S14 |
| 036 | engine-S03 |
| 037 | engine-S34a + engine-S34b |
| 038 | engine-S30 |
| 039 | engine-S05 |
| 040 | engine-S17a + engine-S17b |
| 041 | engine-S29 |
| 042 | engine-S32 |
| 043 | engine-S11 |
| 044 | engine-S11 |
| 045 | engine-S35 |
| 046 | engine-S31b |
| 047 | engine-S36 |
| 048 | engine-S37 |
| 049 | engine-S13 |
| 050 | engine-S24 |
| 051 | engine-S31c |
| 052 | engine-S11 |
| 053 | engine-S38 |
| 054 | engine-S15 |
| 055 | engine-S02a + engine-S02b |
| 056 | engine-S15 |
| 057 | engine-S03 |
| 058 | engine-S39 |

**output** (64):

| finding | step |
|---|---|
| 001 | output-S45 |
| 002 | output-S46 |
| 003 | output-S47 |
| 004 | output-S44a + output-S44b |
| 005 | output-S48 |
| 006 | output-S49a + output-S49b |
| 007 | output-S35 |
| 008 | output-S50 |
| 009 | output-S53 |
| 010 | output-S52 |
| 011 | output-S18 |
| 012 | output-S02 |
| 013 | output-S01 |
| 014 | output-S25 |
| 015 | engine-S16a + engine-S16b |
| 016 | output-S38 |
| 017 | output-S34 |
| 018 | output-S26 |
| 019 | output-S27 |
| 020 | output-S51 |
| 021 | output-S31 |
| 022 | output-S43 |
| 023 | output-S55 |
| 024 | output-S54 |
| 025 | output-S28 |
| 026 | output-S20 |
| 027 | output-S12 |
| 028 | output-S34 |
| 029 | output-S13 |
| 030 | output-S14 |
| 031 | output-S15 |
| 032 | output-S16 |
| 033 | output-S36 |
| 034 | output-S33 |
| 035 | output-S22 |
| 036 | output-S56a, output-S56b |
| 037 | output-S21 |
| 038 | output-S03 |
| 039 | output-S30 |
| 040 | engine-S16a + engine-S16b |
| 041 | output-S39 |
| 042 | output-S32 |
| 043 | output-S38 |
| 044 | output-S41 |
| 045 | output-S29 |
| 046 | output-S19 |
| 047 | output-S18 |
| 048 | output-S38 |
| 049 | output-S04 |
| 050 | output-S05 |
| 051 | output-S06 |
| 052 | output-S18, output-S31 |
| 053 | output-S40 |
| 054 | output-S07 |
| 055 | output-S33 |
| 056 | output-S11 |
| 057 | output-S08 |
| 058 | output-S37 |
| 059 | output-S42 |
| 060 | output-S07 |
| 061 | output-S09 |
| 062 | output-S10 |
| 063 | output-S17 |
| 064 | output-S31 |

**shell** (55):

| finding | step |
|---|---|
| 001 | shell-S01 |
| 002 | shell-S01 |
| 003 | shell-S23 |
| 004 | shell-S14 |
| 005 | shell-S10 |
| 006 | shell-S20 |
| 007 | shell-S04 |
| 008 | shell-S15 |
| 009 | shell-S18 |
| 010 | shell-S26 |
| 011 | shell-S11 |
| 012 | shell-S25 |
| 013 | shell-S24 |
| 014 | shell-S24 |
| 015 | shell-S27 |
| 016 | shell-S03 |
| 017 | shell-S36 |
| 018 | shell-S30 |
| 019 | shell-S30 |
| 020 | shell-S29 |
| 021 | shell-S02 |
| 022 | shell-S02 |
| 023 | shell-S33 |
| 024 | shell-S33 |
| 025 | shell-S34 |
| 026 | shell-S22 |
| 027 | shell-S16 |
| 028 | shell-S10 |
| 029 | shell-S26 |
| 030 | shell-S21 |
| 031 | shell-S19 |
| 032 | shell-S08 |
| 033 | shell-S05 |
| 034 | shell-S11 |
| 035 | shell-S17 |
| 036 | shell-S31 |
| 037 | shell-S32 |
| 038 | shell-S10 |
| 039 | shell-S28 |
| 040 | shell-S23 |
| 041 | shell-S12 |
| 042 | shell-S37 |
| 043 | shell-S25 |
| 044 | shell-S38 |
| 045 | shell-S35 |
| 046 | shell-S09 |
| 047 | shell-S05 |
| 048 | shell-S06 |
| 049 | shell-S07 |
| 050 | shell-S13 |
| 051 | shell-S40 |
| 052 | shell-S39 |
| 053 | shell-S27 |
| 054 | shell-S37 |
| 055 | shell-S28 |

**once** (127):

| finding | step |
|---|---|
| 001 | shell-S01 |
| 002 | once-S02 |
| 003 | once-S03 |
| 004 | once-S04 |
| 005 | once-S05 |
| 006 | once-S06 |
| 007 | once-S07 |
| 008 | once-S08 |
| 009 | once-S09 |
| 010 | once-S12 |
| 011 | once-S13 |
| 012 | once-S10 |
| 013 | once-S10 |
| 014 | once-S11 |
| 015 | once-S11 |
| 016 | once-S12 |
| 017 | once-S14 |
| 018 | once-S15 |
| 019 | once-S16 |
| 020 | once-S17 |
| 021 | once-S18 |
| 022 | once-S19 |
| 023 | once-S20 |
| 024 | once-S21 |
| 025 | once-S22 |
| 026 | once-S23 |
| 027 | once-S24 |
| 028 | once-S25 |
| 029 | once-S24 |
| 030 | once-S26 |
| 031 | once-S27 |
| 032 | once-S28 |
| 033 | once-S29 |
| 034 | once-S30 |
| 035 | once-S31 |
| 036 | once-S32 |
| 037 | once-S33 |
| 038 | once-S34 |
| 039 | once-S35 |
| 040 | output-S54 |
| 041 | once-S37 |
| 042 | once-S38 |
| 043 | once-S39 |
| 044 | once-S40 |
| 045 | once-S41 |
| 046 | once-S42 |
| 047 | once-S43 |
| 048 | once-S44 |
| 049 | once-S45 |
| 050 | once-S46 |
| 051 | once-S47 |
| 052 | once-S48 |
| 053 | once-S09 |
| 054 | once-S49 |
| 055 | once-S50 |
| 056 | once-S50 |
| 057 | once-S51 |
| 058 | once-S52 |
| 059 | once-S53 |
| 060 | once-S55 |
| 061 | once-S55 |
| 062 | once-S56 |
| 063 | once-S57 |
| 064 | once-S58 |
| 065 | once-S03 |
| 066 | once-S59 |
| 067 | once-S60 |
| 068 | once-S60 |
| 069 | once-S60 |
| 070 | once-S61 |
| 071 | once-S62 |
| 072 | once-S63 |
| 073 | once-S64 |
| 074 | once-S65 |
| 075 | once-S66 |
| 076 | once-S54 |
| 077 | once-S67 |
| 078 | once-S68 |
| 079 | once-S69 |
| 080 | once-S37 |
| 081 | once-S37 |
| 082 | once-S70 |
| 083 | once-S54 |
| 084 | once-S54 |
| 085 | once-S71 |
| 086 | once-S47 |
| 087 | once-S72 |
| 088 | once-S73 |
| 089 | once-S74 |
| 090 | once-S75 |
| 091 | once-S74 |
| 092 | once-S76 |
| 093 | once-S77 |
| 094 | once-S47 |
| 095 | once-S78 |
| 096 | once-S31 |
| 097 | once-S31 |
| 098 | once-S31 |
| 099 | once-S79 |
| 100 | once-S80 |
| 101 | once-S80 |
| 102 | once-S82 |
| 103 | once-S83 |
| 104 | once-S84 |
| 105 | once-S68 |
| 106 | once-S85 |
| 107 | once-S85 |
| 108 | once-S86 |
| 109 | once-S87 |
| 110 | once-S77 |
| 111 | once-S88 |
| 112 | once-S89 |
| 113 | once-S90 |
| 114 | once-S91 |
| 115 | once-S92 |
| 116 | once-S93 |
| 117 | once-S94 |
| 118 | once-S53 |
| 119 | once-S95 |
| 120 | once-S96 |
| 121 | once-S95 |
| 122 | once-S97 |
| 123 | once-S98 |
| 124 | once-S99 |
| 125 | once-S100 |
| 126 | once-S101 |
| 127 | once-S102 |

## Fixer notes (r1)

All 14 reviewer issues applied (fixed=14, skipped=0). Adaptations worth recording:

- **output-S02** also lost its `tests/Golden/` token: its acceptance is verify-only ("goldens byte-identical (GATE; no UPDATE_SNAPSHOTS run)"), which the rewritten I10 law forbids on token-holders; the regen-holder roster (13 steps) is data-derived from the final queue so law and rows cannot drift.
- **output-S56a/b never carried a once-S81 prereq** on disk (both rows checked pre-edit); the I6 fix for that clause was therefore vacuous and nothing was removed there — only the Size/prereq detail lines were corrected.
- **Splits (I5):** both half-legs carry the parent's full Covers and full severity mix, so the coverage set stays 304/304 with zero appendix row-content loss; 19 appendix cells retargeted to compound "Xa + Xb" ownership (and once-101 → once-S80).
- **[DECISION] normalization (I4):** only owner-ruling tokens were rewritten to `[OWNER ...]` (22 sites) + once-S72 relabeled `[OWNER PRE-GATE]`; genuinely lane-level decisions (shell-S32 pack-vs-drop, once-S55 sentinel sanctioning) keep their wording by design.
- **I11:** encoded as one real queue edge once-S72→once-S96 (tsv + catalogue) plus a Validation sentence; the TUI spine is eight steps with seven handoffs — pairwise lock edges were not enumerated.
- **D1 (severity-first ordering) NOT applied** — it is a `## Decisions for the user` row awaiting the owner ruling; the queue keeps the pure Kahn (est_min, id) order and the order note says so.
- Mechanical re-checks at close: Kahn 247/247 acyclic; row order == Kahn order; catalogue block order == row order; 0 dangling prereqs; 5 tab-columns × 247 data rows; all statuses `pending`; tallies MM=123 HH=107 DD=17, total 21,005 min (splits preserve the parent sums; once-S80 +60 absorbs once-S81 −60); est/Size and Prereqs lines match their queue rows in all 247 blocks.

## Fixer notes (Phase-5 owner decisions — D1, D2, r3 NIT-1)

Owner ruling: "go with recommendations for all" (`## Decisions for the user`, reviews/plan-review.md).

- **D1(b) applied:** queue order re-derived as severity-first Kahn (Validation, first bullet). All 247 tsv rows
  re-emitted in the new order; every `**Queue rank:** n of 247` header and the catalogue block order renumbered to
  match the queue 1:1; the queue-file header comment and the catalogue order note now state the severity-first law.
  Prereqs, locks, Covers, severity mixes and est_min values are byte-unchanged.
- **D2(a) applied:** the >240 min = must-split ceiling is codified in the Validation section. Re-derived census: zero
  remaining steps violate it (Phase-5 r1 had already split all 11 >240 DD parents, issue I5) — no new splits were
  needed, so M stays 247 and the Covers/appendix bijection is untouched by this decision.
- **r3 NIT-1 applied:** `plan_review.queue.tsv` is committed alongside this note (previously untracked), closing the
  durability gap the round-3 review flagged.
- Re-checks at this pass: tsv rows == catalogue headers 1:1 and same order; 5 tab-columns × 247 data rows; est_min
  positive ints, none > 240; all statuses `pending`; severity-first Kahn re-processes 247/247 reproducing the row
  order exactly; 0 dangling prereqs and 0 prereq-order violations; all five critical roots inside the first wave
  (rows 1–8) with no non-critical row ahead of them; `**Covers:**` headers vs coverage appendix re-run — 304/304
  bijection exact; total still 21,005 min; `## Orchestrator instructions` and `## Program laws` byte-identical.
