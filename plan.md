# phpdup — Remediation Execution Plan

**Companion to:** [findings.md](findings.md) (49 findings from the 2026-06-30 review).
**Nature of this document:** an execution spec for a **multi-agent, serial, one-PR-per-change** remediation run. It defines *what* changes (phases → steps, each mapped to a finding ID) and *how* each change is driven (the orchestration protocol). Nothing here mutates code; execution begins only when the operator says so.

---

## 0. Operating principles

1. **Serial, always.** Exactly one step is in flight at a time. One step = one finding (or a tightly-coupled finding group) = one branch = one PR = one merge. No parallel code changes — this keeps `master` linear and conflict-free.
2. **`master` is the resting state.** Each step transiently creates a `fix/…` or `feat/…` branch purely as the vehicle for its PR, then merges and returns. **After every step the working tree is back on `master`, synced with `origin/master`, clean.**
3. **Delegate, don't do.** The main session is a thin **driver**. It spawns one **phase orchestrator** per phase. Each orchestrator spawns **worker agents** for every unit of work and *does nothing substantive itself* — it only reads agent results, decides the next agent to spawn, and enforces gates. Orchestrators may do trivial glue (read a file to confirm an agent's claim, run a one-line status command) but never author code, tests, or docs directly.
4. **Every step passes the same lifecycle** (§3) before it can merge: implement → review⇄fix loop → tests → docs → integrate.
5. **Green gates are non-negotiable.** A step cannot merge unless `phpunit` (all suites), `phpstan`, and `psalm` are all clean, and `psalm-baseline.xml` has not grown.
6. **Stop-anywhere.** Phases are ordered by value and dependency. The run may halt cleanly after any *merged* step; a half-done step is never left on `master`.

---

## 1. Roles & agent roster

| Role | Spawned by | `subagent_type` | Responsibility | May edit files? |
|---|---|---|---|---|
| **Driver** | (main session) | — | Spawn phase orchestrators in order; relay phase summaries to operator; halt on unrecoverable failure. | No |
| **Phase orchestrator** | Driver | `general-purpose` | Run the phase's steps serially; for each step run the §3 lifecycle by spawning workers; enforce gates; produce a phase report. | No (coordination only) |
| **Implement agent** | Orchestrator | `general-purpose` | Make the code change for one step. | **Yes** |
| **Review agent** | Orchestrator | `feature-dev:code-reviewer` | Adversarially review the step's diff vs. its acceptance criteria + project conventions. Return `CLEAN` or a list of concrete problems. | No |
| **Fix agent** | Orchestrator | `general-purpose` | Resolve the review agent's problem list. | **Yes** |
| **Test agent** | Orchestrator | `general-purpose` (may use `add-phpunit-test` skill) | Add/update tests covering the new logic; run all gates; report pass/fail. | **Yes** (tests + minimal src if a gate fails) |
| **Docs agent** | Orchestrator | `general-purpose` | Heavily document changed code (docblocks/inline) and update `README.md` + `docs/` + `CALIBER_LEARNINGS.md` where relevant. | **Yes** (comments + docs) |
| **Integration agent** | Orchestrator | `general-purpose` | Execute the §5 git workflow: branch → commit → push → PR → merge → pull → return to `master`. | **Yes** (git only) |

> **Why `general-purpose` for most roles:** it has full tool access (Edit/Write/Bash/git/gh). The review role uses the dedicated reviewer agent for sharper, confidence-filtered findings. Orchestrators may raise an agent's `model`/`effort` for the hardest correctness steps (C1, C2, P1, S1) and lower it for mechanical ones (Q-nits, filename sanitization).

---

## 2. The driver loop (main session)

```
ensure pre-flight complete (Phase 0)
for each PHASE in order:
    spawn Phase-Orchestrator(phase) and WAIT for its report
    relay a 3-5 line phase summary to the operator
    if orchestrator reports an unrecoverable blocker: HALT, surface it, await operator
verify final state: on master, clean, origin/master synced, all gates green
```

The driver never spawns two orchestrators at once.

---

## 3. Per-step lifecycle (run by the phase orchestrator)

Each step carries a **finding ID**, a **briefing** (the finding text from findings.md + file list + acceptance criteria + test focus + docs focus). The orchestrator passes the briefing verbatim into every worker it spawns for that step — workers start fresh and have no other context.

```
STEP(finding):
  1. IMPLEMENT
       spawn Implement agent with the briefing → it edits files on the working tree (still on master)
  2. REVIEW ⇄ FIX  (loop, max 4 iterations)
       spawn Review agent → returns CLEAN | [problems]
       if CLEAN: break
       spawn Fix agent with [problems] → edits
       (repeat)
       if still not CLEAN after 4 iterations: orchestrator escalates the step to the driver
  3. TESTS
       spawn Test agent → add/refresh tests for the new logic; run ALL gates (§4)
       if any gate fails: feed failures to a Fix agent, then re-run Test agent (loop, max 4)
       (if a JsonReporter cluster-shape changed: bump JsonSchemaSpec::SCHEMA_VERSION and
        regenerate Golden with UPDATE_SNAPSHOTS=1 — call out in the briefing)
  4. DOCS
       spawn Docs agent → heavy code docs + README/docs/ updates describing the change & rationale
       (a docs-only change cannot break gates; Test agent already proved green before docs)
  5. INTEGRATE
       spawn Integration agent → §5 git workflow (branch, commit, push, PR, merge, pull master)
       on success: working tree is back on master, synced, clean
  6. RECORD
       orchestrator appends the step result to the §9 progress log (status, PR URL, gate results)
```

**Loop caps** exist so a pathological step can't spin forever. On cap-exceed the orchestrator stops the step, leaves the working tree clean (git stash/discard the partial change — nothing is committed yet), and escalates to the driver with the agent transcripts' key points.

**The orchestrator decides, agents act.** The orchestrator reads each agent's returned summary and chooses the next spawn. It does not open an editor itself.

---

## 4. Quality gates (every step, before merge)

Run from repo root; all must be clean:

```bash
vendor/bin/phpunit                 # all suites (Unit/Integration/Golden) — failOnWarning & failOnNotice are ON
vendor/bin/phpstan analyse         # level 6, src/
vendor/bin/psalm                   # errorLevel 6, src/
git diff --quiet psalm-baseline.xml   # baseline must NOT grow — new errors are FIXED, not baselined
```

Schema rule: any change to `JsonReporter::build()`'s cluster shape ⇒ bump `Phpdup\Reporting\JsonSchemaSpec::SCHEMA_VERSION` and `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`.
PHP floor rule: no standalone `:null` return types, PHP-Parser 5 quirks per CLAUDE.md (`Throw_`, `VarLikeIdentifier`).

---

## 5. Git workflow (run by the Integration agent, per step)

Pre-condition: on `master`, working tree contains this step's (already-green, already-documented) changes, uncommitted.

> **`gh` env caveat:** a `GITHUB_TOKEN` exported in the environment overrides the `gh` keyring login and is frequently scoped wrong (or expired), causing `gh pr create`/`merge` to fail with auth/permission errors. **Likely required once per shell before any `gh` call:** `unset GITHUB_TOKEN` (and `GH_TOKEN` if set) so `gh` falls back to its stored credentials. The Integration agent must `unset GITHUB_TOKEN GH_TOKEN` at the top of its git workflow (it runs in a fresh shell each spawn). Verify with `gh auth status` after unsetting.

```bash
# 0. Ensure gh uses the keyring login, not a stray env token
unset GITHUB_TOKEN GH_TOKEN     # likely needed — see caveat above
# 1. Branch (carries the working-tree changes onto the new branch; master stays clean)
git checkout -b <type>/<ID>-<slug>          # e.g. fix/C1-semantic-edit-cost-model

# 2. Caliber sync (REQUIRED before commit — see §6), then stage
#    (if the pre-commit hook is active, it runs caliber automatically; otherwise do it manually)
caliber refresh && git add -A               # or just `git add -A` if hook-active

# 3. Commit (message format §7)
git commit -m "<type>: <summary> (<ID>)" -m "<body>" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"

# 4. Push
git push -u origin <type>/<ID>-<slug>

# 5. PR
gh pr create --base master --title "<type>: <summary> (<ID>)" --body "<PR body — §7>"

# 6. Merge (squash keeps master history one-commit-per-finding) and delete the remote branch
gh pr merge --squash --delete-branch

# 7. Return to master and pull the merge
git checkout master
git pull origin master
git branch -D <type>/<ID>-<slug>            # delete local branch

# 8. Verify resting state
git status --porcelain                      # must be empty
git rev-parse --abbrev-ref HEAD             # must be 'master'
```

**Branch types:** `fix/` (bug/security/perf/memory/dead-code), `feat/` (new capability), `chore/` (docs-only, tooling), `refactor/` (Q-series quality steps with no behavior change).

**Failure handling in integration:**
- PR checks (CI) fail → spawn Fix agent, push to the same branch, re-check. Do **not** merge red.
- Merge conflict (shouldn't happen under serial execution, but if `origin/master` advanced) → rebase the branch on `master`, re-run gates, then merge.
- `gh` not authenticated / no remote permission → escalate to driver immediately (this is an operator-environment blocker, not a code problem).

---

## 6. Caliber pre-commit constraint (read before Phase 0)

CLAUDE.md mandates Caliber config-sync before every commit, and the Stop hook reports Caliber is **not currently set up on this machine**. Resolve in Phase 0:

- **Preferred:** run `/setup-caliber` once (installs the pre-commit hook → every later commit auto-syncs `CLAUDE.md`/`.claude/` etc.). ~30 s, one-time.
- **Fallback (if setup is declined):** the Integration agent runs `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ .opencode/ 2>/dev/null` before each commit. If `caliber` is absent entirely, the run is blocked until the operator installs it or explicitly waives the requirement.

---

## 7. Commit & PR conventions

**Commit / PR title:** `<type>: <imperative summary> (<finding ID>)`
e.g. `fix: apply edit-cost weights to prefixed AST labels (C1)`

**Commit body / PR body** (the PR body must end with the Claude Code attribution line):
```
## What & why
<1-3 sentences: the finding, the root cause, the fix>

## Changes
- <file>: <what changed>
- tests: <coverage added>
- docs: <docs/README updated>

## Verification
- phpunit: <N tests, green>
- phpstan / psalm: clean, baseline unchanged
- <feature-specific manual check, if any>

Closes finding <ID> (see findings.md).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

---

## 8. Phases & steps

Ordering follows findings.md's roadmap: **correctness → security → broken artifacts → scalability → memory → dead code → quality → tests → features → docs**. Each step lists: finding ID, title, primary files, acceptance criteria (AC), test focus (T), docs focus (D). Full problem descriptions live in [findings.md](findings.md) under the same IDs — the orchestrator copies them into each briefing.

> A step's "files" are the expected blast radius; agents may touch more if needed. Steps within a phase run in the listed order.

### Phase 0 — Pre-flight (no code changes; gate the whole run)
- **0.1** Confirm clean baseline: `git status` clean on `master`; run all §4 gates and record the green baseline (571 unit tests etc.). If anything is already red, surface it before proceeding.
- **0.2** Resolve Caliber per §6 (`/setup-caliber` or fallback). Confirm a trial commit can be created+amended-away, or that the hook path works.
- **0.3** Confirm `gh` works for push/PR/merge on `origin`. **First `unset GITHUB_TOKEN GH_TOKEN`** — a stray exported token overrides the keyring login and commonly breaks `gh` with wrong-scope/expired errors; then run `gh auth status`. Bake the `unset` into the Integration agent's workflow (§5) since each agent spawns a fresh shell. If `gh` still can't authenticate after unsetting → halt and ask the operator.
- **0.4** Confirm the squash-merge + delete-branch policy is acceptable on this repo (branch protection, required reviews). If `master` requires reviews that an automated merge can't satisfy → escalate (the operator may need to relax protection or approve each PR).
- **0.5** Decide execution scope with the operator: **(a)** Phases 1–4 only (the "fix-first" correctness+security+artifacts set), or **(b)** the full plan (Phases 1–12). The plan is authored for (b) but every phase is an independent stopping point.

### Phase 1 — Inert "features that lie" (correctness)
| Step | ID | Title | Files | AC / T / D |
|---|---|---|---|---|
| 1.1 | **C1** | Make `--ted-weights=semantic` actually weight | `src/Similarity/EditCostModel.php`, `tests/Unit/Similarity/EditCostModelTest.php` | AC: real `shortType()` labels (`Expr_MethodCall`, `Stmt_If_`, `Scalar_Int_`) map to 2.0/1.5/0.5. T: assert `cost('Expr_MethodCall')==2.0` **and** AptedDistance similarity is *strictly* different between `default`/`semantic`. D: document the label format contract. |
| 1.2 | **C3** | Real `match`↔`switch` canonicalization (or remove the claim) | `src/Normalization/Normalizer.php`, tests | AC: equivalent `match`/`switch` produce identical serialized tokens; delete the dead `$cases` build. T: a `match`/`switch` pair clusters. D: update CLAUDE.md "Match_ ↔ Switch_" note to reflect the real mechanism. |
| 1.3 | **C5 + C5b** | Implement the `--debug-log` sink + stop dropping it in `withOverrides()` | `src/Cli/Config.php`, `src/Pipeline/*`, `src/Cli/ConfigLoader.php` | AC: debug messages are written to the file; `withOverrides()` passes `debugLog`. T: running with `--debug-log` produces a non-empty file; per-dir override preserves it. D: `docs/CLI.md` + README flag table. |

### Phase 2 — Silently-wrong similarity (correctness)
| Step | ID | Title | Files | AC / T / D |
|---|---|---|---|---|
| 2.1 | **C2** | Fix APTED flatten cache aliasing + leak | `src/Similarity/AptedDistance.php`, tests | AC: cache no longer keyed on `spl_object_id` (use content hash, per-call scope, or remove). T: a regression that frees a node, forces id reuse, and asserts no stale hit; assert no unbounded growth. D: comment the cache-key invariant. |
| 2.2 | **P1** | Stop APTED early-abort from poisoning `treedist` | `src/Similarity/AptedDistance.php`, tests | AC: abort only at `ted()` level; no key-root reads unwritten (zero) cells. T: 3-sibling-subtree case where the middle pair aborts; assert correct TED. D: document the abort/matrix invariant. |

### Phase 3 — Security hardening
| Step | ID | Title | Files | AC / T / D |
|---|---|---|---|---|
| 3.1 | **S2** | Sandbox `serve` paths + require auth on public bind | `src/Server/Application.php`, `src/Cli/ServeCommand.php`, tests | AC: scanned paths confined to a `realpath`'d `--serve-root`; reject `..`/absolute escapes; bearer-token required when `--bind-public`; refuse public bind without a token. T: traversal attempt is rejected; unauth public request is 401. D: `docs/SERVER.md` security section. |
| 3.2 | **S1** | Sign releases + verify signature on self-update | `src/Cli/UpdateCommand.php`, `build-phar.php`, `.github/workflows/*`, docs | AC: detached signature (minisign/cosign) verified before install; missing/invalid sig is a hard failure unless `--allow-unsigned`; SSL context on the checksum fetch. T: tampered phar/sig is refused. D: document the signing key + release process. **(Largest step — may split signing-infra vs. client-verify into 3.2a/3.2b.)** |
| 3.3 | **S4** | ML clients reject non-2xx HTTP | `src/Ml/MlClient.php`, `src/Ml/MlPairClient.php`, tests | AC: check `CURLINFO_HTTP_CODE`/status line; return null unless 2xx. T: a 500-with-JSON-body yields null. D: note the fail-graceful contract. |
| 3.4 | **S3** | Deepen SSRF guard | `src/Ml/MlClient.php`, tests | AC: reject loopback/RFC1918/link-local/`::1`/metadata IPs after host resolution. T: each blocked range. D: SSRF policy note. |
| 3.5 | **S5** | `allowed_classes` on IPC + TokenCache unserialize | `src/Parallel/WorkerPool.php`, `src/Parsing/TokenCache.php` | AC: explicit allow-list (no `=>true`). T: round-trip still works. D: note alignment with SerializedClassAllowList. |
| 3.6 | **S6** | CSV formula-injection guard | `src/Reporting/CsvReporter.php`, tests | AC: cells starting `= + - @ \t \r` are neutralized. T: malicious cell. D: note in reporter docblock. |
| 3.7 | **S7 + S8** | Pager argv (no shell) + filename sanitization | `src/Cli/Pager.php`, `src/Reporting/{Diff,RefactorPatch,RefactorTest}Reporter.php`, `src/Pipeline/Stages/ReportStage.php` | AC: `proc_open` gets argv array; cluster-id filenames `basename()`/slugged. T: an id with `/`/`..` stays in-dir. D: brief notes. |

### Phase 4 — Generated artifacts that don't run/compile
| Step | ID | Title | Files | AC / T / D |
|---|---|---|---|---|
| 4.1 | **C4** | Single-pass HTML syntax highlighter | `src/Reporting/HtmlReporter.php`, tests | AC: keywords inside comments/strings/attributes are not re-wrapped; valid DOM. T: `// new class returns string` renders well-formed. D: note the tokenizer approach. |
| 4.2 | **D3** | Generated PHPUnit tests don't fatal | `src/Reporting/RefactorTestReporter.php`, tests | AC: generated method's arity matches the data-provider rows (params from holes). T: generate-then-`php -l`/parse a fixture cluster. D: note generation contract. |
| 4.3 | **D4** | Refactor signatures are valid 8.1 PHP | `src/Refactor/SignatureBuilder.php`, `src/Refactor/ParameterSynthesizer.php`, tests | AC: `class-string`→`string`, bare `null`→`mixed`/dropped; no standalone `null` type on 8.1. T: synthesized signature passes `php -l`. D: note type-mapping policy. |

### Phase 5 — Performance & scalability
| Step | ID | Title | Files |
|---|---|---|---|
| 5.1 | **P2** | O(edges) running-min per component (drop O(k²) rescore) | `src/Clustering/Clusterer.php` |
| 5.2 | **P6** | `generateCandidatePairs()` as a generator; drop corpus-wide `$seen` | `src/Clustering/Clusterer.php` |
| 5.3 | **P3** | Drop/ memoize per-block whole-file read in `computeRangeHash` | `src/Extraction/BlockExtractor.php` |
| 5.4 | **P4** | Single bottom-up subtree node-count; dedupe the two counters | `src/Extraction/BlockExtractor.php`, `src/Util/AstSerializer.php` |
| 5.5 | **P7** | SWAR popcount in `ShapeletSketch` (GMP fallback) | `src/Fingerprint/ShapeletSketch.php` |
| 5.6 | **P8** | Lazy/short-circuit + cached `detectCpuCount()` | `src/Parallel/WorkerPool.php` |
| 5.7 | **P9** | Avoid throwaway merged-array key union | `src/Similarity/JaccardSimilarity.php`, `src/Similarity/ContainmentSimilarity.php` |
| 5.8 | **P5/P10** | Bound `BloomCandidateIndex` claim/use; fix `ScanningStage` progress accounting | `src/Index/BloomCandidateIndex.php`, `src/Pipeline/Stages/ScanningStage.php` |

> Each P-step's Test agent must add a micro-benchmark or invariant assertion (not just "still green") so the optimization is proven not to change results.

### Phase 6 — Memory & resource leaks
| Step | ID | Title | Files |
|---|---|---|---|
| 6.1 | **M5** | Bound the server job queue (ring/LRU + TTL; don't retain full bodies) | `src/Server/JobQueue.php`, `src/Server/Application.php` |
| 6.2 | **M2** | Reap/kill workers on cancel; deterministic `finally` | `src/Parallel/WorkerPool.php`, `src/Pipeline/Pipeline.php` |
| 6.3 | **M3** | Incremental per-file cache save (drop double block copy) | `src/Pipeline/Stages/PreprocessStage.php` |
| 6.4 | **M4** | Drop redundant `$pureEdges` rebuild | `src/Pipeline/Stages/ClusterStage.php` |
| 6.5 | **M1** | `try/finally` temp-file cleanup in `WorkerPool::run()` (or delete with D2) | `src/Parallel/WorkerPool.php` |

### Phase 7 — Dead / unwired / incomplete code
| Step | ID | Title | Files / decision |
|---|---|---|---|
| 7.1 | **D1** | For each of `TokenCache`, `CanonicalNodePool`, `HoleMap`, `CompactNgramBag`, `SimilarityScore`: **wire-in behind a flag OR delete with tests**. One sub-PR per class (5 steps). Default recommendation: delete `HoleMap`+`SimilarityScore`; wire `CompactNgramBag`/`CanonicalNodePool` behind a `--low-memory` flag; wire `TokenCache` into the parse path (with the S5 allow-list). Operator confirms each disposition before its sub-step. |
| 7.2 | **D2** | Delete `WorkerPool::run()` + its test (folds into 6.5 if "delete" chosen) | `src/Parallel/WorkerPool.php`, test |
| 7.3 | **D6** | `IrLifter`: handle `AssignOp\*` + inc/dec → `AssignIr` | `src/Ir/IrLifter.php`, tests |
| 7.4 | **D7** | Add `$m->ast === null` guards to 3 PatternRecognizer detectors | `src/Refactor/PatternRecognizer.php`, tests |
| 7.5 | **D8** | Reset `$namespace` on `leaveNode` | `src/Extraction/BlockExtractor.php`, tests |

### Phase 8 — Code quality, duplication & complexity (no behavior change)
| Step | ID | Title | Files |
|---|---|---|---|
| 8.1 | **Q2** | `WritesReportFile` trait — dedupe 9 reporters' file-write boilerplate | `src/Reporting/*` |
| 8.2 | **Q3** | Central `Severity::forImpact()/forScore()` consumed by all reporters | `src/Reporting/*` |
| 8.3 | **Q4** | `PipelineState::debug()` + `sampleMemory()` helpers; fix the wrong-counter bug | `src/Pipeline/*` |
| 8.4 | **Q1 + C5b consolidation** | Extract `OverrideResolver` (single key-list + precedence); split `Command::execute()` | `src/Cli/Command.php`, `src/Cli/ConfigLoader.php`, `src/Cli/Config.php` |
| 8.5 | **Q5** | Decompose `ClusterStage::iter()`; unify serial/parallel scoring driver | `src/Pipeline/Stages/ClusterStage.php` |
| 8.6 | **Q6** | Cooperative stages honor `$state->cancelled` between yields | `src/Pipeline/Stages/*` |
| 8.7 | **Q7** | The nits batch: `firstStringArg`, SARIF `0/false` filter, Containment doc/metric, Behavioural empty-band, MinHash seeding, summarizer boundary, delimiter constants, UpdateCommand/ServeCommand cosmetics, CliReporter member guard. One PR per nit *or* grouped by file. |

> Q-steps are `refactor/` — the Review agent's primary AC is **"no observable behavior change"** (Golden snapshots must be byte-identical unless a schema bump is explicitly intended).

### Phase 9 — Testing backfill
| Step | ID | Title |
|---|---|---|
| 9.1 | **T1a** | `Clusterer` tests: determinism (stable cluster ids/order), threshold edges, union-find. |
| 9.2 | **T1b** | `IrLifter`/`IrSimilarity` tests: semantic-equivalence pairs (incl. D6 cases). |
| 9.3 | **T1c** | `ServeCommand`/`Application` tests: path-sandbox + auth (locks in S2). |
| 9.4 | **T1d** | `UpdateCommand` tests: signature verification + tamper rejection (locks in S1). |
| 9.5 | **T1e** | `Architecture/Analyzers/*` + `Semantic/{ControlFlowGraph,DataflowSummarizer}` smoke/behaviour tests. |
| 9.6 | **T2** | Audit & strengthen weak assertions (the `assertLessThanOrEqual`/bare-label class that masked C1). |

### Phase 10 — CI-adoption features
| Step | ID | Title | Files |
|---|---|---|---|
| 10.1 | **F4** | `--fail-on-impact=N` / `--max-clusters=N` non-zero exit gate | `src/Cli/Command.php`, `src/Cli/Config.php`, `src/Cli/ConfigLoader.php`, `docs/config-schema.json`, `docs/CLI.md` (the 6-edit-site flag flow) |
| 10.2 | **F2** | Duplication baseline: `--baseline file.json` (write + compare-new-only) | new `src/Reporting/BaselineStore.php` (or `src/Persistence/`), `ReportStage`, CLI flag |
| 10.3 | **F3** | Diff mode: `--diff-base=<ref>` scopes scan to changed files + clone cohort | `src/Scanning/*`, `src/Cli/*` |

### Phase 11 — Capability features (larger; gate with operator)
| Step | ID | Title | Notes |
|---|---|---|---|
| 11.1 | **F1** | `--apply` — actually extract the anti-unified body + rewrite call sites (PHP-Parser rewrite + pretty-print) | Depends on D4/D5. Biggest feature; design sub-PR first, then implement behind `--apply --dry-run` default. |
| 11.2 | **A1 / F6** | Unify the two watch implementations into one `FileWatcher` (with new-file detection) + optional inotify backend | `src/Watch/WatchRunner.php`, `src/Cli/Command.php` |
| 11.3 | **A2** | Async/queued `serve` (background worker drains the bounded queue) | builds on 6.1 |
| 11.4 | **F5** | `phpdup init` config wizard (uses existing profile detector) | new subcommand |
| 11.5 | **F7** | Per-pair match explainability (which tier + score) surfaced in reports | `src/Clustering/*`, reporters |

### Phase 12 — Documentation & hygiene pass
| Step | ID | Title |
|---|---|---|
| 12.1 | **DOC-1** | Split the 2,650-line `README.md` into an orientation + `docs/` topic links. |
| 12.2 | **DOC-2** | Docblock-truth sweep: every claim corrected during Phases 1–11 is reflected in its docblock (the "docblocks that lie" theme). |
| 12.3 | **DOC-3** | Remove/`.gitignore` committed scratch (`.logs/subtask2.log`); verify `CALIBER_LEARNINGS.md` captures new gotchas. |

---

## 9. Progress log (orchestrators append; one row per step)

| Step | ID | Branch | PR | Gates (unit/phpstan/psalm) | Merged | Notes |
|---|---|---|---|---|---|---|
| 0.x | pre-flight | — | — | baseline 571✓ / ✓ / ✓ | n/a | |
| 1.1 | C1 | `fix/C1-semantic-edit-cost-model` | https://github.com/detain/php-dup-finder/pull/65 | 580✓ / ✓ / ✓ | ☑ (squash-merged e64ea9a) | canonicalizeLabel() strips Expr_/Stmt_/Scalar_ prefixes |
| 1.2 | C3 | `fix/C3-match-switch-canonicalization` | https://github.com/detain/php-dup-finder/pull/66 | 580✓ / ✓ / ✓ | ☑ (squash-merged 984c8a5) | Match_/Switch_ normalize to __MATCH__ FuncCall; dead $cases deleted |
| 1.3 | C5+C5b | `fix/C5-debug-log-sink` | https://github.com/detain/php-dup-finder/pull/67 | 580✓ / ✓ / ✓ | ☑ (squash-merged 673e9a4) | DebugLogger writes to file; withOverrides() passes debugLog |
| 2.1 | C2 | `fix/C2-apted-cache-alias` | https://github.com/detain/php-dup-finder/pull/68 | 580✓ / ✓ / ✓ | ☑ (squash-merged 582fdb3) | remove spl_object_id from AptedDistance::flatten cache |
| 2.2 | P1 | `fix/P1-apted-early-abort` | https://github.com/detain/php-dup-finder/pull/69 | 585✓ / ✓ / ✓ | ☑ (squash-merged ad1efc6) | abort only at ted() level; no key-root reads of unwritten cells |
| 3.1 | S2 | `fix/S2-serve-path-sandbox-auth` | https://github.com/detain/php-dup-finder/pull/70 | 601✓ / ✓ / ✓ | ☑ (squash-merged cc1311d) | realpath serve-root; reject absolute/..; bearer token required on --bind-public |
| … | | | | | | |

(The driver keeps this table current and shows the operator a per-phase rollup.)

---

## 10. Definition of Done

**Per step:** code change merged to `master` via its own squash-merged PR; Review loop ended `CLEAN`; tests added/updated and all §4 gates green; code heavily documented and `docs/`/README updated; working tree back on `master`, clean, synced; progress log row complete.

**Per phase:** every step Done; orchestrator's phase report delivered to the driver; `master` green.

**Whole run (chosen scope):** all in-scope phases Done; `findings.md` items annotated as resolved (or consciously deferred with a reason); final `master` passes all gates; README/docs reflect the new reality.

---

## 11. Risks & guardrails

- **Auto-merge to `master`** is explicitly requested. Guardrail: a step merges only on green gates **and** green PR CI; branch protection that blocks automated merge is surfaced in Phase 0.4 rather than worked around.
- **Serial discipline** prevents the cross-step conflicts that finding Q1/C5b warn about; never run two steps' branches concurrently.
- **Behavior-changing refactors (Phase 8)** are guarded by byte-identical Golden snapshots; any intended snapshot change requires an explicit schema-version bump in the same PR.
- **Loop caps** (§3) bound agent spin; cap-exceed escalates with a clean tree, never a half-committed change.
- **Caliber gate** (§6) must be resolved before the first commit or the whole run is blocked.
```
