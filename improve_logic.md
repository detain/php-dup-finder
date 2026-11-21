# phpdup → refactor-audit: gap analysis and improvement plan

_Written 2026-11-20 against `master` @ `24841ff`._

**Question asked:** two AI-agent audits produced `/home/my/refactor.md` (187 clusters across `/home/my` + `vendor/detain/*` + `vendor/ganesh/*`) and `/home/sites/phlix/refactor.md` (149 findings across `phlix-server` + `phlix-hub`). What has to change in phpdup for it to produce something comparable, and what exactly is involved?

**How this was answered:** I read both reports, read phpdup's detection core, then ran phpdup against the same code the agents audited and compared outputs finding-by-finding. Every number below is from those runs unless it is marked as an estimate. Nothing in this repo was modified; where a bug blocked a run I patched a scratch copy outside the repo (details in [Appendix A](#appendix-a--how-the-measurements-were-taken)).

---

## 1. Summary

1. **phpdup does not currently run on a real codebase.** Three independent crashes on `master`: the default (parallel) run fatals, every numeric CLI flag fatals, and a `switch` containing `case X: return;` aborts the whole run. Two of the three were introduced by the June remediation PRs and passed all 781 tests. Fix these first ([§4 Phase 0](#phase-0--make-it-run-and-keep-it-running)).
2. **The pair-detection engine underneath is decent.** With the crashes patched, it linked 24 of 26 server↔hub twin files at method level, and found whole-method clone families the agents also reported (`logFailure` ×9, `ServerReaper` sweeps, the `WelcomeEmail` family, 20 of 28 `Vps`↔`Quickservers` endpoint pairs).
3. **It misses the drifted copies, which are the valuable ones.** The 8 `Vps`↔`Quickservers` pairs it failed to link include the four where the agent found dropped guards (a live bug). Root cause is one design choice — numbering renamed identifiers by first occurrence — and I confirmed that removing the numbering links all 8 ([§3.3](#33-why-drifted-copies-are-missed-measured)).
4. **Most of what the agents reported is not a "block".** phpdup only compares whole functions, closures, and control structures. Roughly half of the agent findings are something else: a 12-line preamble inside 114 otherwise-different methods, a one-line expression idiom at 30 sites, a helper that exists but is hand-rolled elsewhere, mirrored directories, duplicated literals and config maps, JS and templates.
5. **The output is a list of 2,008 clusters, not a report.** On phlix the top three ranked results are an unusable signature, DTO `toArray()` methods, and route tables. 59% of clusters are named `extractedFunction`. There is no roll-up, no "which copy is canonical", no divergence analysis, no cross-repo view.
6. **Some agent findings are out of reach for static analysis** (parallel implementations with no shared structure, "is this divergence intentional?", architecture judgments). The practical route is phpdup as the evidence engine with an LLM pass on top ([Phase 8](#phase-8--llm-adjudication-layer)).

Recommended order: Phase 0 → 1 → 6.2/6.3 (ranking, subsumption) → 2 → 4 → 3 → 5 → 6.1 → 8 → 7. Rationale and effort are in [§5](#5-roadmap).

---

## 2. What the agent reports contain (the target)

### 2.1 Kinds of finding

Classification of the 187 `/home/my` entries is mine, by keyword on each entry's type label, so treat the counts as approximate. The phlix report labels its own findings (exact / near / same-intent / under-adopted SSOT).

| Class | Share of `/home/my` report | Example from the reports | phpdup today |
|---|---|---|---|
| **A. Copy or near-copy of a whole unit** (function, method, file) | ~87 (47%) | `WelcomeEmail` API family ×9; `logFailure()` ×10; `redactQueueOutput()` ×5 | Found when the unit is a function/method under 800 AST nodes and has not drifted. Missed otherwise. |
| **B. Repeated fragment or idiom inside larger code** | ~36 (19%) | service preamble ×114 (12–20 lines each); admin guard ×76; cron bootstrap ×261; `bin2hex(random_bytes(N))` ×28 | Not a unit phpdup extracts. Shows up, at best, as several unrelated 3-line `if` clusters. |
| **C. Scaffold / class skeleton** | ~16 (9%) | `Plugin.php` shells ×64; payment-gateway Plugin boilerplate ×7 | Reported as N separate method clusters. No class-level view. |
| **D. Parallel / same-intent implementations** | ~21 (11%) | ≥5 IPv6 implementations; 7 hand-rolled async HTTP clients; 4 title normalizers | Not detected (little shared structure). |
| **E. Scattered literals, config maps, wire constants** | small in `/home/my`, large in phlix | TMDB image URL ×4; ticket status map ×7; TLS cipher list ×3; SQL twins | Invisible by design: the normalizer rewrites every literal to `__STR` / `0`. |
| **F. Mirrored files and directories** | (inside A) | `Api/Vps/` ↔ `Api/Quickservers/` 27 pairs; `Common/RateLimit/` in both phlix repos; `MonitorLizzy` ↔ `SshitBot` ~35 files | Pairs are linked method-by-method but never summarized as "these two directories mirror each other". |
| **G. Under-adopted single source of truth** | phlix §4, `/home/my` S1-1, S1-4 | `Response::error` has 91 adopters vs 420 inline copies; `ServiceResolver` exists, 114 sites inline its body; `EnvConfig` has no `bool()` so 9 sites hand-roll one | Not detected. No notion of "a helper already exists for this". |
| **H. Non-PHP** | 142 `.tpl` and 82 `.js` mentions in `/home/my` | `escHtml` ×19 in JS; `update_coupon()` ×10; template twin families; CI workflow copies | PHP only. `*.tpl.php` and `*.blade.php` are excluded by default. |
| **I. Dead code, packaging, architecture** | ~6 | empty package still required; orphaned admin shims | Out of scope for a clone detector. |

### 2.2 What makes each entry useful

Every agent entry has the same four parts. phpdup produces the first and a weak version of the third.

| Part | Agent report | phpdup |
|---|---|---|
| **Locations** | All sites with `file:line` ranges, grouped, with counts | Yes (members with ranges) |
| **Evidence** | The shared code, quoted, plus what varies | Hole table only. JSON carries no source, no template body, no per-member diff. |
| **Proposal** | Named target (`Api\Support\ServiceResolver`), where it lives, whether it already exists, which copy to keep | `function extractedFunction(mixed $expr): mixed` |
| **Risk / divergence** | Which copies differ and why it matters. 66 ⚠ markers in the `/home/my` report; 14 live bugs in phlix, all "fix landed on one copy only" | None. `CoherenceAnalyzer` flags outlier members but does not say what differs. |

Report-level features phpdup lacks: executive summary, top-N table ranked by instances × size × divergence risk, latent-bug register, cross-repo vs intra-repo split, "do not merge" list of intentional divergences, suggested landing order.

---

## 3. Where phpdup stands today (measured)

### 3.1 It crashes before producing results

| # | Symptom | Cause | Introduced |
|---|---|---|---|
| 1 | Default run fatals: `substr(): Argument #1 ($string) must be of type string, null given` at `src/Pipeline/Stages/PreprocessStage.php:200`, preceded by thousands of "incomplete object" warnings | `src/Parallel/WorkerPool.php:191` decodes worker results with `['allowed_classes' => false]`, so every `Block` arrives as `__PHP_Incomplete_Class`. Triggers whenever `pcntl` is available and there are ≥ 8 files, which is the default path. | PR #74 (`11b2f1c`, S5) |
| 2 | `--min-impact`, `--min-block-size`, `--similarity`, `--max-df`, `-j/--workers`, `--fail-on-impact` all fatal with `Config::__construct(): Argument … must be of type int, string given` | `Command::parseCliOverrides()` (`src/Cli/Command.php:234`) passes raw option strings; `OverrideResolver::resolve()` does not coerce; `Config` is strict-typed. The README quick start and the CI smoke step (`.github/workflows/ci.yml:62`, `--min-impact 5`) both use an affected flag. | PR #102 (`8c69b38`, Q1) |
| 3 | Whole run aborts: `PhpParser\Node\Arg::__construct(): Argument #1 ($value) must be of type Expr, null given` | `src/Normalization/Normalizer.php:322` takes `->expr` of a `case X: return;` statement, which is null. Hit in `/home/my`. One file kills the run because a worker exception is rethrown at `WorkerPool.php:193` instead of being recorded as a per-file error. | PR #66 (C3) |

The only way to get output from unmodified `master` today is a config file with `"workers": 1` and no numeric CLI flags.

### 3.2 Baseline numbers

| Corpus | Files | Blocks | Candidate pairs | Clusters | Wall | Peak RSS | Mode |
|---|---|---|---|---|---|---|---|
| `/home/my/include/{Api,ajax}` | 689 | 5,039 | — | 426 | 3m37s | 0.6 GB | unmodified `master`, serial |
| `phlix-server/src` + `phlix-hub/src` | 1,044 | 23,895 | 18,809,360 | 2,008 | 2m32s | 6.2 GB | scratch copy, crash #1 patched, 24 workers |
| same, serial on unmodified `master` | 1,044 | 23,895 | — | — | > 18m40s (I stopped it; still scoring pairs on one core) | 5.8 GB | serial |
| `/home/my` `include`+`scripts`+`public_html` + `vendor/detain` + `vendor/ganesh` | 6,594 | 98,336 | never finished enumerating | none | 14m15s, then out of memory | 80.5 GB (hit the 80G `memory_limit` I set) | scratch copy, crashes #1 and #3 patched, 24 workers |

So the one configuration that works on unmodified `master` (serial) had not finished the phlix corpus in over 18 minutes, against 2m32s with workers, and the full `/home/my` corpus does not complete in either mode.

Shape of the phlix output (2,008 clusters):

- 892 are `if` blocks and 803 are methods; 961 clusters have exactly 2 members.
- 1,184 (59%) have the signature name `extractedFunction`.
- 270 holes have the observed value `<missing>`.
- 264 (13%) are fully nested inside members of another cluster (18% on the `Api`/`ajax` run).
- 550 clusters span both repos. That is the most useful single number in the output and it is not reported; I computed it from the JSON.

### 3.3 Why drifted copies are missed (measured)

`Api/Vps/Start.php` and `Api/Quickservers/Start.php` are identical except that the Quickservers copy is missing two guard blocks. The agent report flags exactly this as a live bug ("Guards dropped in QS copies … PHP fatal on invalid/foreign id").

phpdup put the Vps method in one cluster (with `Vps/Stop`, etc.) and the Quickservers method in another. It never connected them. Its own scores for the pair:

| Fingerprint form | Jaccard | Containment | Linked? (needs J ≥ 0.80 or C ≥ 0.85) |
|---|---|---|---|
| `aggressive` mode, as shipped | 0.613 | 0.840 | No |
| same, with the numeric suffix stripped from `__V`/`__CALL`/`__PROP`/`__CLASS` | 0.826 | 1.000 | Yes |
| real call names kept, variables un-numbered | 0.791 | 0.977 | Yes |

Cause: `CanonicalizingVisitor::getOrAssign()` (`src/Normalization/Normalizer.php:491`) renames identifiers to `__CALL0`, `__CALL1`, … in order of first appearance. The Vps copy calls `json_error` inside its guards, so it becomes `__CALL5`. The Quickservers copy never calls it, so every later call is numbered one lower, and every n-gram after the missing guards differs. An insertion or deletion that introduces one new name wrecks the fingerprint for the rest of the block.

The same experiment on all 8 unlinked `Vps`↔`Quickservers` pairs:

| Pair | Containment as shipped | Containment un-numbered |
|---|---|---|
| Start, Stop, Restart, EjectCd | 0.840 | 1.000 |
| Backup | 0.795 | 0.957 |
| ResetPassword | 0.498 | 0.992 |
| ChangeRootPassword | 0.542 | 0.985 |
| TrafficUsage | 0.369 | 0.885 |

All 8 clear the 0.85 threshold once the numbering is removed. This is the highest-value fix in the engine: type-3 detection exists, but the default normalization mode defeats it.

### 3.4 What it does not look at (measured)

| Blind spot | Size | Where in code |
|---|---|---|
| **Functions over 800 AST nodes** are dropped as units | `/home/my/include`: 340 functions covering 106,379 of 314,568 in-function lines (34%). `vendor/detain`+`ganesh`: 84 functions, 18,040 lines. | `src/Extraction/BlockExtractor.php:117` |
| **Top-level script code** (outside any function or class) is not a block | `/home/my/scripts`: 41,846 lines in 517 files (39% of LOC). `/home/my/public_html`: 38,324 lines in 166 files (65%). | `BlockVisitor::classifyKind()` has no file-level kind |
| **Classes, interfaces, value objects, constants** | `RateLimitState.php` is a 98% textual twin across the phlix repos; cluster coverage of the file is 10%. `ServiceProviderInterface.php` is a 77% twin; zero shared clusters. | Only method bodies and control structures are extracted |
| **Statement sequences** shared by otherwise-different functions | Agent S1-1: 114 sites. phpdup: three unrelated clusters of 122, 106 and 73 three-line `if`s at adjacent lines of the same methods. | No sub-block unit |
| **Expression idioms** | phlix, by grep: 30 `in_array(strtolower(…` truthy checks, 31 `bin2hex(random_bytes(`, 21 `stream_context_create(`, 266 `json(['error' =>`. phpdup clusters for these: none as such. | Same |
| **Literals** | Not measurable from phpdup output | `canonicalizeLiterals()` rewrites all strings and numbers |
| **Files that yield no blocks** | `/home/my` run: 6,594 files scanned, "5,307 processed". The other 1,287 are silently uncounted. | `PreprocessStage.php:164` counts only files that emitted a row |

A six-pattern micro-fixture ([Appendix A](#appendix-a--how-the-measurements-were-taken)) confirms the list: a shared 9-line preamble in 5 otherwise-different methods came back as three separate one-line `if` clusters plus a 3-member "method" cluster with signature `function go(mixed $expr): mixed` and hole value `<missing>`; three identical top-level scripts came back as a `while` cluster and a `foreach` cluster; five inline copies of two existing helpers, a duplicated URL constant and a duplicated status map produced nothing.

### 3.5 Ranking and noise

Top three results on phlix, by phpdup's `impact` ordering:

| Rank | Members | What it is | Verdict |
|---|---|---|---|
| 1 | 167 `if` blocks, similarity 0.80 | Every "return 400 JSON error" guard in the codebase, chained together. Signature: `extractedFunction(mixed $expr, bool $includeIntervalDays = false, bool $includeIntervalDays1 = false)`. Tags: `sql-builder`, `controller-action`. | Real idiom (the agent's E-10), unusable presentation. The helper that already exists, `Response::error`, is not mentioned. |
| 2 | 55 methods | `toArray()` / `serialize()` on unrelated DTOs | False positive |
| 3 | 29 methods and closures | Route registration tables | False positive (the agent report lists route tables under "do NOT merge") |

Meanwhile the coroutine-detection predicate triplicate, which the agent flagged for having two mutually exclusive semantics, is at rank 1,556 of 2,008.

Causes:

- **Single-linkage clustering.** `Clusterer::cluster()` unions every edge (`src/Clustering/Clusterer.php:196-242`), so A~B and B~C puts A and C together regardless of their own similarity. That is how 167 loosely related blocks become one cluster.
- **Impact is in AST nodes, not lines**, and has no notion of spread or risk (`src/Reporting/Ranker.php:59`). The README describes it as "≈ duplicated lines".
- **Cross-namespace spread is penalized** (`Ranker.php:94`, `SafetyScorer.php:62`). For this use case, a clone spanning repos is the top finding, not a suspect one.
- **No subsumption.** A cloned method and its cloned inner `if` and `foreach` are three clusters.
- **Pattern tags are unreliable**: theming DTOs tagged `sql-builder`.

### 3.6 Scale

- Candidate generation treats any single shared 5-gram under the frequency cap as a candidate pair (`src/Index/NgramInvertedIndex.php:61-65`): 18.8 M pairs for 23,895 blocks, of which 90,631 (0.5%) became edges.
- `ClusterStage::generateCandidatePairs()` calls `iterator_to_array()` on the pair generator (`src/Pipeline/Stages/ClusterStage.php:207`), which undoes the lazy-generator work from PR #81, and it runs on one core.
- On the 98,336-block corpus the run never got past this step. Preprocessing took about a minute on 24 workers; candidate enumeration then ran on one core for about 13 minutes, growing by 4–7 GB per minute, and died with `Allowed memory size … exhausted` at `src/Clustering/Clusterer.php:131` when it reached the 80G limit I had set. No pair was scored and no cluster was produced. The dedup map on that line is capped at 200,000 entries (`SEEN_CAP`), so the unbounded part is the materialized pair list, not the map.
- `MinHashSignature` and `LshIndex` exist in `src/` and are not called by the pipeline.

### 3.7 Unwired groundwork

`src/Semantic/` (`ControlFlowGraph`, `DataflowSummarizer`, `CallGraph`, `DbOperationTagger`), `BehaviouralSimilarity`, `MinHashSignature`, `LshIndex`, and `MlClient` have no callers in the pipeline. `CallGraph`'s docblock refers to a `CallGraphSimilarity` class that does not exist. Several items below can reuse these instead of starting from nothing.

---

## 4. Improvements

Effort is my estimate: **S** ≤ 1 day, **M** 2–4 days, **L** 1–2 weeks. Each item lists what to change and how to know it worked.

### Phase 0 — Make it run and keep it running

**0.1 Fix worker result decoding (S).**
`src/Parallel/WorkerPool.php:191`: replace `['allowed_classes' => false]` with the same allow-list `IndexStore` uses (`SerializedClassAllowList::blockCacheClasses()`), extended with whatever `RefactorWorker` returns (`Cluster`, `Hole`). Then treat any `__PHP_Incomplete_Class` in a decoded record as a hard error with a clear message.
*Done when:* `bin/phpdup analyze src` exits 0 with default settings.

**0.2 Coerce CLI overrides (S).**
Give `OverrideResolver` a key → type map (`int`, `float`, `bool`, `string`) and cast there, so CLI strings and JSON values go through one path. Reject non-numeric input with exit code 2 instead of a `TypeError`.
*Done when:* every flag in `docs/CLI.md` is exercised by a test that shells out to `bin/phpdup`.

**0.3 Fix `switch` normalization and isolate per-file failures (S).**
`Normalizer.php:322`: handle a bare `return;`. In `PreprocessWorker::process()`, wrap the per-file extract/normalize/fingerprint loop in `try/catch (\Throwable)` and emit the existing `type: 'error'` row. One unexpected AST shape should cost one file, not the run.
*Done when:* a fixture with `switch ($x) { case 1: return; }` passes, and a deliberately broken normalization plugin produces `parse_errors: 1` and exit 0.

**0.4 End-to-end smoke tests (S–M).**
The three crashes share a cause: no test runs the real binary with real flags on a corpus big enough to fork. Add `tests/Integration/CliSmokeTest.php` that runs `bin/phpdup` (a) with each numeric flag, (b) with `-j 2` on a ≥ 8-file fixture, (c) on phpdup's own `src/` as a crash test. `WorkerPoolTest` currently round-trips only scalars; add a case that round-trips a `Block`.

**0.5 Candidate generation that scales (M).**
- Stream pairs to the scoring workers in chunks instead of `iterator_to_array()`.
- Filter before emitting a pair: require a minimum number of shared n-grams (prefix filtering for a Jaccard threshold is standard), and a size-ratio check (two bags cannot reach Jaccard 0.80 if one is under 0.8× the other; containment needs the 0.6 ratio already in config).
- Replace the "drop n-grams above 1% document frequency" cap with rarest-k seeding per block. The cap is relative to corpus size, so the same two files can pair or not depending on what else is scanned, and a family with more copies than the cap has no seed n-grams at all. I did not isolate a miss caused by this, so treat it as a design risk rather than a measured defect.
- Or wire in the existing `MinHashSignature` + `LshIndex`.
*Done when:* the 98k-block `/home/my` corpus completes under a stated memory budget (suggest 8 GB). Today it does not complete at 80 GB (§3.6).

**0.6 Report what was skipped (S).**
Print "N files produced no comparable blocks" and "N functions exceeded max block size". Today both are silent.

### Phase 1 — Recall fixes in the existing engine

**1.1 Un-numbered identifiers for fingerprints (S–M). Highest value per line changed.**
Keep the current consistently-numbered canonical tree for the structural hash (exact buckets) and for anti-unification. Build the n-gram bag from tokens with the numeric suffix removed. The cheapest implementation is a flag on `AstSerializer::tokens()` that maps `__V12` → `__V` at serialization time, so no second tree is needed.
Evaluate the alternative "keep real function and method names, un-number only variables". It scored almost as well in §3.3 and should cut false positives like `toArray()`-vs-anything, because callee names carry intent.
Touch points: `AstSerializer`, `NgramFingerprint`, `PreprocessWorker::toolFor()` cache key, the `IndexStore` config-key in `PreprocessStage`, `ClusterStage::cacheConfigKey()`, Golden snapshots.
*Done when:* all 28 `Vps`↔`Quickservers` pairs share a method-level cluster (today 20).

**1.2 Stop dropping large functions (M).**
Above `max_block_size`, skip tree-edit distance (the expensive step) but still fingerprint and compare by n-gram Jaccard and containment, and align by statement hashes (`AntiUnifier::lcsAlignTemplate()` already does LCS over per-statement hashes). Add a `max_ted_size` setting separate from `max_block_size`.
*Done when:* `qs_create()` vs `vps_create()` (agent S1-24, 127–168 lines) and the `ViewVPS`/`ViewQS` builders (S1-25) appear.

**1.3 Top-level script blocks (M).**
Add block kind `script`: a file's top-level statements, excluding function and class declarations, wrapped in a synthetic `PhpParser\Node\Stmt\Block` (present in the installed php-parser). Follow the "Block kind" recipe in `CLAUDE.md` (`ALL_KINDS`, `classifyKind()`, `BlockAstLoader::matchesKind()`).
*Done when:* the three identical scripts in the micro-fixture form one 3-member `script` cluster, and the `*_list.php` family (agent S1-43, 5 copies) is found.

**1.4 Replace single-linkage clustering (M).**
After union-find, split each component so every member is within threshold of a medoid (or use average linkage). `CoherenceAnalyzer` already computes the pairwise matrix; promote it from "badge" to "split". Report tightness (min and mean similarity to the medoid) instead of the minimum edge.
*Done when:* no cluster has a member whose similarity to the medoid is below the configured threshold; the 167-member phlix cluster breaks into coherent groups.

### Phase 2 — New units of comparison

**2.1 Statement-sequence clones (L). Unlocks class B, the biggest gap.**
Goal: "these 9 consecutive statements appear in 114 functions".
- In `PreprocessWorker`, for every statement list (function bodies, loop and branch bodies, top-level), emit the sequence of per-statement hashes with line ranges and node counts. Use the un-numbered form from 1.1 with real callee names kept.
- New mining step (a stage between Preprocess and Cluster, or the first step of `ClusterStage`): index k-grams of statement hashes (k = 3 by default), then extend matches left and right to maximal runs, allowing a small gap budget for type-3.
- Emit each maximal run as a `Block` of kind `fragment` wrapping the statements in a synthetic `Stmt\Block`, so `AntiUnifier`, `ParameterSynthesizer` and all reporters work unchanged.
- For the suggested signature, use live-variable analysis: variables read before written inside the fragment are parameters; variables written inside and read afterwards are returns. `DataflowSummarizer` exists and is unwired.
- Suppress a fragment when it equals a whole block already clustered.

A cheap first step (S) that works on today's output: **cluster stitching**. If clusters A and B each have ≥ m members that sit within a few lines of each other, in the same order, in the same enclosing function, merge them into one sequence finding. That alone turns the three 3-line `if` clusters of §3.4 into one "service preamble ×73+" finding.
*Done when:* the micro-fixture preamble is one 5-member fragment covering lines 4–12 with parameters `$id`, `$module` and outputs `$settings`, `$db`, `$serviceInfo`; on `/home/my/include/Api` the S1-1 preamble is one finding with ≥ 100 members.

**2.2 File, class and directory roll-up (M). Unlocks class F.**
Pure post-processing over clusters:
- For each file pair, coverage = lines of A inside clusters shared with B ÷ comparable lines of A. Above 50% both ways → "twin files".
- Group twin files by directory pair. `Api/Vps/` ↔ `Api/Quickservers/` becomes one finding with a 28-row table (file, coverage, what differs).
- Seed with same-relative-path and same-basename pairs (39 and 68 between the phlix repos) and compare them even when clustering did not link them.
- Collapse child clusters under the roll-up in reports.
New `src/Reporting/RollupAnalyzer.php`; new `rollups` field in `JsonReporter::build()` (bump `JsonSchemaSpec::SCHEMA_VERSION`, regenerate Golden).
*Done when:* phlix output contains a "`Common/RateLimit/` mirrored across server and hub (5 files)" finding and `/home/my` contains the Vps↔Quickservers one.

**2.3 Class-level and whole-file comparison (M). Unlocks class C and the VO/interface gap.**
Add block kind `class` with a "shape" fingerprint: member names with arity and visibility, constant names, implemented interfaces, plus whole-class tokens. Two outputs:
- **Declaration twins**: interfaces, value objects and constant classes that have no method bodies to cluster (`RateLimitState.php`, `ServiceProviderInterface.php`).
- **Skeleton families**: N classes sharing a shape. For each shared method name, count distinct structural hashes across the family, giving a table of "identical in all (pull up) / parameterizable (template method) / divergent". That table is the base-class proposal the agent wrote by hand for the 64 `Plugin.php` shells.

### Phase 3 — Idiom mining

**3.1 Frequent expression subtrees (M–L).**
Enumerate expression subtrees between about 6 and 40 nodes. Hash each twice: **exact** (variables blanked, names and literals kept) and **abstract** (literals blanked too). Count; keep those above a site and file threshold that are not contained in an equally frequent parent.
The abstract key groups dialects of one idiom, which is where the bugs are. The phlix report's E-04 is 9 truthy-list checks in differing dialects, one of which misreads `" false"`.
To bound memory, count hashes first and keep locations only for hashes seen at least twice.
*Done when:* on phlix it reports the truthy-list check, `bin2hex(random_bytes(_))`, and base64url encode, with site counts near the grep counts in §3.4.

**3.2 Helper re-implementation detector (M, after 3.1). Unlocks class G.**
For every small function or method (single `return <expr>;` or a handful of statements), replace its parameters with wildcards and look up the resulting pattern in the 3.1 and 2.1 indexes. Each hit outside the helper is "an inline copy of `X::y()`". Then count call sites of the helper to report adoption: "`Env::bool()` — 6 callers, 9 inline re-implementations".
This is the mechanical version of the agents' "already exists, under-adopted" sections, and of entries like S1-4, where `IpOwnership::ownsIp()` has one caller and six hand-written copies.
*Done when:* the micro-fixture reports 5 inline copies each of `Env::bool` and `Env::b64url`.

**3.3 Literal and constant census (M). Unlocks class E.**
A separate pass that does the opposite of the normalizer:
- string literals over a length threshold (URLs, SQL, regexes, messages), repeated;
- array literals with ≥ 3 entries, hashed order-insensitively (status maps, allow-lists, option tables);
- near-duplicate literal families by edit distance, to surface drifting allow-lists (agent S1-57: six copies of one IP list, diverged);
- interpolated strings grouped by their literal skeleton (`AstSerializer::scalarPart()` already produces one) for repeated SQL shapes; `SqlTableExtractor` gives table names for grouping;
- numeric constants repeated in comparable positions (`86400` on 112 lines).

**3.4 Duplicate symbol index (S).**
Same function name, class short name, or file basename defined in more than one place. Feed these pairs to comparison regardless of similarity and report the measured similarity. Covers "same filename, different logic" (S1-46), "page-local function shadows canonical class" (S1-58), and `RateLimitException` (B-12: same name, diverged payload).

**3.5 Call-sequence idioms (M). Partial reach into class D.**
Per function, record the ordered list of callee names (`CallGraph` already collects the set). Mine frequent ordered sub-sequences: `curl_init → curl_setopt… → curl_exec → curl_close`, `ssh2_connect → ssh2_auth_* → ssh2_exec`, `fopen(tmp) → fwrite → rename`. Report each protocol with its sites and a table of constant arguments that differ. That table is how the agent found 79 of 80 cURL bootstraps with `CURLOPT_SSL_VERIFYPEER` off.
Also add callee-set similarity as a scoring tier for blocks with low AST similarity. It is the nearest structural signal for "same job, different code".

### Phase 4 — Divergence and risk

This is what turned the agents' duplicate lists into bug reports. It depends on 1.1; today the drifted pairs are not in the same cluster to begin with.

**4.1 Drift analyzer (M–L).**
For each non-exact cluster, classify what differs between the majority and the minority, using the hole paths `AntiUnifier` already records (`Cluster::$holePaths`, `present_in_members`):

| Drift class | Rule | Agent example |
|---|---|---|
| Missing guard | an `if (…) { return / throw / exit }` present in most members, absent in some | Quickservers `Start/Stop/Restart/EjectCd` |
| Missing sanitizer | an argument wrapped in an escape/cast/validate call in some members, raw in others | `Admin/…/ChangeIp` raw vs `real_escape` copies |
| Missing auth check | a call from a configured auth vocabulary present in some members only | `Quickservers/ReinstallOs` drops the re-auth |
| Option divergence | same call, different constant arguments | TLS verify off; missing timeouts; `==` vs `hash_equals` |
| Copy-paste literal | a string in member A names member B's module | `'Invalid VPS Passed'` inside Quickservers files |
| Odd one out | one member differs where all others agree | `logFailure` ×10, one has an extra parameter |

Put the vocabularies (guards, sanitizers, auth calls, security-relevant options) in the profile JSON; `profiles/db-aware-myadmin.json` shows the mechanism exists. Emit findings through the existing `Cluster::$architecturalFindings` path so reporters pick them up. `Hole::$observedValues` are printed strings today; the analyzer needs the nodes, so keep a node reference per observation.
*Done when:* the `Vps`↔`Quickservers` roll-up lists "missing guard" on the four files the agent flagged.

**4.2 Git history signals (M, opt-in `--history`).**
One `git blame --porcelain` per member file, cached, gives per-line commit and date. Per cluster report: last-changed date per member, members changed alone in the last N months, whether the lone commit message matches `fix|security|bug`, and how often members have changed in the same commit. "One twin was fixed in March, the other was last touched two years ago" is the exact pattern behind all 14 phlix bugs.

**4.3 Declared-twin checker (S).**
Scan comments for `mirror`, `twin`, `ported from`, `keep in sync`, `copy of`, `byte-identical` followed by a class or path. Resolve the reference, measure similarity, and report claims that no longer hold. The phlix report did this by hand several times ("docblock claim FALSE … measured Δ154").

**4.4 Accepted-divergence registry (S–M).**
Both agent reports end with a "do NOT merge" list. Add `@phpdup-ignore <reason>` and an `accepted` list in `phpdup.json`, and render them as an appendix rather than dropping them. This needs stable finding IDs: cluster IDs are currently an md5 of a block ID that embeds the block's list index (`PreprocessStage.php:200`, `Clusterer.php:271`), so they change whenever a file is added. Derive IDs from sorted member content hashes, as `BaselineStore` already does.

### Phase 5 — Multi-repo awareness and SSOT proposals

**5.1 Repo and package labels (S–M).**
Resolve each file's nearest `composer.json` and `.git` to a package and repo label on `Block`. Give each cluster a scope: intra-file, intra-package, cross-package, cross-repo. Report counts by scope in the summary, allow `--scope cross-repo`, and reverse the cross-namespace penalties in `Ranker` and `SafetyScorer` for audit use.

**5.2 Exclude handling (S).**
`DefaultExcludes` patterns are anchored to the scan root, so `vendor/**` does not match `scripts/bots/MonitorLizzy/vendor/`. In `/home/my` there are four nested `vendor/` trees under `scripts/bots/` and two under `vendor/detain/*/`. Make the defaults match at any depth, honor `.gitignore`, and detect vendored third-party copies (identical to a package in `composer.lock`, or carrying a known upstream header) so they go into their own "vendored duplicates" section. The agent's S3-23 (nusoap shipped twice) and S1-38 (a workerman demo tree copied twice) are that class.

**5.3 Where the shared code should live (M).**
Per finding, compute and print:
- an existing canonical candidate: a helper whose body matches (from 3.2), or a member in a shared namespace that the others could call;
- the lowest common namespace or directory of the members;
- for cross-package findings, a package both sides already `require`. Both phlix repos require `phlix/shared`, which is where the agent sent everything;
- the form: function, trait, base class or table. If every hole is a literal, emit the observed values as a data table and say "table-driven".

**5.4 Usable abstraction output (M).**
- Name from the body (dominant callee verbs and nouns, enclosing method names) before falling back to `extractedFunction`.
- If holes cover more than about 40% of the body, the members are not clones of each other. Re-express the finding as a shared prefix/suffix fragment (2.1) or drop it. That is the fix for `function go(mixed $expr)`.
- Never print `<missing>` as an observed value; it means the unifier lost alignment.
- Include the generalized template source and one representative diff per finding in JSON, so downstream consumers have evidence without re-reading files.

### Phase 6 — Reporting

**6.1 Markdown audit report (M).**
New reporter `--audit-md FILE`, following the "Reporter" recipe in `CLAUDE.md`. Sections, mirroring the agent reports: scope and method; summary; top-N table (instances, lines, risk, proposed SSOT); mirrored directories and files (2.2); skeleton families (2.3); fragments and idioms with adoption counts (2.1, 3.1, 3.2, 3.5); literal census (3.3); divergence register (4.1–4.3); accepted divergences (4.4). Each entry uses the four-part shape from §2.2.

**6.2 Ranking (M).**
Score = removable lines × spread (files, packages, repos) × divergence risk × confidence. Express impact in lines. Demote declarative shapes (a body that is a single `return [ … ]` of property reads, route tables, accessor boilerplate, constructor promotion); a simple test is statement-kind entropy plus callee diversity.
*Done when:* on phlix, none of the top 20 is a DTO serializer or a route table, and the coroutine-predicate triplicate is in the first page.

**6.3 Subsumption (S–M).**
When every member of cluster B lies inside a member of cluster A, in the same pairing, fold B under A. Report maximal clones only. Removes 13–18% of clusters on the corpora measured.

**6.4 Themes (M).**
Group findings by shared callee vocabulary and directory into themes ("outbound HTTP", "IP math", "error envelopes") for the summary. This is where an LLM pass does better than heuristics; see Phase 8.

### Phase 7 — Beyond PHP

The `/home/my` report mentions `.tpl` 142 times and `.js` 82 times; the phlix report has a section on CI workflow and test-harness copies.

**7.1 Language-agnostic token tier (M–L).** A lexical detector for any text file: tokenize loosely (identifiers, numbers, strings, punctuation), blank identifiers and literals optionally, hash k-token windows, extend to maximal matches. Type-1 and type-2 only. That is enough for `buy_license.js` ≡ `buy_license2.js`, `escHtml` ×19, template twins and copied workflow YAML. Feed results into the same `Cluster` and reporter path with kind `text`.

**7.2 PHP inside templates (S).** Stop excluding `*.tpl.php` by default for audit runs, and extract inline `<script>` bodies from `.php` and `.tpl` files into the token tier.

Shelling out to `jscpd` and merging its JSON is a faster interim option, at the cost of a Node dependency.

### Phase 8 — LLM adjudication layer

Proposal text, canonical-copy choice, "is this divergence deliberate?", theme grouping, and landing order are judgment. The agents did well at that and poorly at exhaustive recall (they grep and sample). phpdup is the reverse. Combine them rather than hand-coding judgment.

**8.1 Evidence packs (M).** A JSON document per finding: members with source excerpts, generalized template, per-member diff against the template, drift flags (4.1), history (4.2), adoption counts (3.2), scope (5.1). Size-capped, ranked.

**8.2 `phpdup audit` (M–L).** Runs the pipeline, then sends evidence packs to an LLM in batches to fill in title, proposal, risk, effort and "intentional?" per finding, followed by one pass that merges findings into themes and writes the summary and landing order. Cache by evidence-pack hash so reruns only pay for changed findings. The unwired `src/Ml/` client and the `ml_url` config validation are a starting point for the transport.

**8.3 MCP tools for agents (M).** Your current workflow is "ask an agent to find duplication". Give the agent phpdup as tools on top of the existing `phpdup serve`: find clones of this function, list twins of this directory, census this idiom, list re-implementations of this helper. The agent then starts from complete recall instead of grep samples.

### What not to expect from static analysis

- Parallel implementations with no shared structure or call vocabulary (two IPv6 parsers written independently). 3.4 and 3.5 find some; the rest needs 8.2.
- Whether a divergence is intentional. 4.4 records the answer; it cannot derive it.
- Dead packages, layering violations, dependency direction (class I).

---

## 5. Roadmap

| Step | Items | Effort | What it unlocks |
|---|---|---|---|
| 1 | Phase 0 (0.1–0.6) | ~1 week | The tool runs on real code, in CI, at 100k blocks |
| 2 | 1.1, 1.4, 6.2, 6.3 | ~1.5 weeks | Drifted copies link; top of the report is signal; ~15% fewer clusters |
| 3 | 1.2, 1.3, 2.2, 5.1, 5.2 | ~2 weeks | Large functions and scripts compared; mirrored dirs and cross-repo scope reported. Covers most of class A and all of F |
| 4 | 2.1 (stitching first, then the miner), 5.4 | ~2–3 weeks | Class B fragments with real signatures |
| 5 | 4.1, 4.3, 4.4, then 4.2 | ~2 weeks | Divergence register: the "latent bug" half of the agent reports |
| 6 | 3.1, 3.2, 3.4, then 3.3, 3.5 | ~3 weeks | Expression idioms, under-adopted helpers (class G), literals (class E) |
| 7 | 2.3, 5.3, 6.1 | ~2 weeks | Skeleton families (class C), placement proposals, the Markdown report |
| 8 | Phase 8 | ~2 weeks | Proposal and risk prose, themes, partial reach into class D |
| 9 | Phase 7 | ~1–2 weeks | JS, templates, YAML (class H) |

Steps 1–3 are fixes and post-processing on the current architecture. Step 4 onward adds new analysis passes; each is independent of the others except where noted (4.1 needs 1.1; 3.2 needs 3.1).

If you want one thing after Phase 0, do 1.1. It is small, measured, and it is the difference between finding clean copies and finding the copies that have bugs.

---

## 6. Measuring progress

The two agent reports are a ready-made benchmark; without one, "closer" is a matter of impression.

1. **Pin the code.** The phlix report records its scan commits (`375f7992` server, `ba1ae3b8` hub), and most of its findings have since been fixed, so the benchmark must check out those commits. The `/home/my` report is dated 2026-11-20; record the current HEADs of `/home/my` and each `vendor/detain/*` package now.
2. **Extract ground truth.** Convert each report entry to `{id, class (§2.1), members: [file, start, end], drift: bool}`. The `LOCATIONS` lines are regular enough for a script plus an LLM pass, followed by a manual spot-check. Store under `bench/audit-truth/`.
3. **Score.** A finding is recalled if phpdup emits one finding that overlaps at least k of its members (k = 2, or half for large families). Report recall per class and overall, plus the precision of phpdup's top 50 by manual label.
4. **Track per phase.** Add to `bench/run-all.sh`. Hold every phase to "recall in its target class goes up and top-50 precision does not go down".

Starting spot checks from this review, usable as first regression tests:

| Check | Today | Target |
|---|---|---|
| `Vps`↔`Quickservers` same-name pairs linked at method level | 20 / 28 | 28 / 28 (1.1) |
| phlix same-path twins (text similarity ≥ 0.5) linked at method level | 24 / 26 | 26 / 26 (2.3) |
| `RateLimitState.php` twin coverage | 10% | ≥ 90% (2.3) |
| S1-1 service preamble | 3 separate 3-line clusters | 1 fragment, ≥ 100 members (2.1) |
| Micro-fixture: 6 patterns | 1 partly found | 6 / 6 |
| phlix top-20 containing DTO serializers or route tables | 2 of top 3 | 0 (6.2) |
| Clusters named `extractedFunction` | 59% | < 10% (5.4) |

---

## Appendix A — How the measurements were taken

- **Unmodified `master`** was run with a config file (`{"workers":1,"min_cluster_impact":20}`) to avoid crashes #1 and #2.
- **Scratch copy.** For parallel runs I copied `src/` to a session scratch directory and changed two lines: `WorkerPool.php:191` (`allowed_classes => true`) and `Normalizer.php:322` (null-coalesce the bare `return`). Those are measurement shims, not the fixes proposed in 0.1 and 0.3. The repository itself has no changes other than this file.
- **Machine:** 64 cores, 251 GB RAM, PHP 8.3.37. Parallel runs used 24 workers. Wall time and peak RSS are from `/usr/bin/time -v`. The full `/home/my` run was given `memory_limit=80G` and a 40-minute timeout; it hit the memory limit first.
- **Excludes for the `/home/my` run:** `**/vendor/**`, `**/node_modules/**`, `**/.git/**`, `**/cache/**`, `**/logs/**`, `**/*.tpl.php`, `**/smarty_templates_c/**`, passed as roots `include`, `scripts`, `public_html`, `vendor/detain`, `vendor/ganesh`.
- **Twin-file ground truth (phlix):** 39 files exist at the same relative path in both `src/` trees. Text similarity = 1 − (differing lines ÷ total lines) from `diff` after a rough namespace substitution; comments included, so it understates code similarity.
- **Pair scores in §3.3** were computed with phpdup's own `BlockExtractor`, `Normalizer`, `AstSerializer`, `JaccardSimilarity` and `ContainmentSimilarity` on the largest method of each file, 5-grams; the un-numbered variant applies a regex to the token stream before building the bag.
- **Blind-spot sizes in §3.4** come from a php-parser walk counting lines of top-level statements (excluding `use`, `declare`, inline HTML, and function/class declarations) and functions or methods whose `AstSerializer::nodeCount()` exceeds 800.
- **Micro-fixture:** 14 files: five classes whose `go()` methods share a 9-line preamble and differ afterwards; three identical top-level scripts differing in one table name; one `Env` class with two one-line helpers; five classes that each inline both helpers' bodies and repeat one URL property and one status-map constant. Run with `max_df` 0.9 and `min_cluster_impact` 1.
- **Agent-report statistics:** entry counts by `grep` on headings; the class split in §2.1 by keyword match on each entry's type label.
- **Not verified:** whether CI is currently red (inferred from the smoke step using `--min-impact`); the effect of the document-frequency cap in isolation (0.5); effort estimates.
