# improve_logic_info.md — Merged Research Ledger for `improve_logic.md`

**Program:** 15 read-only research seats (filings f01–f15) verified, corrected, and designed-out every item of
`improve_logic.md` (413L, written against master `24841ff`). This file is the build-order bible for the
follow-on implementation program: per-item findings, the memory story, codified bugs, decided design rulings,
the owner-ruling queue, the dependency graph, and the concurrency partition map.

**Fleet base check:** the repo has NOT moved since the doc was written — `24841ff..HEAD` is empty (git log,
scratch-15) — so every citation below was verified against the exact code the doc cites [f15]§base.
Corpora on this host: `/home/sites/phlix/{phlix-server,phlix-hub}` (doc-pinned commits **present locally**),
`/home/my` @ `ad58b221c5` (UNPINNED, moving), `tests/Fixtures` (5 files). Doc's own §3 baselines reproduce
to the file [f01][f03][f05][f09][f15].

---

## Table of contents

1. [Executive digest](#1-executive-digest)
2. [Merged per-item findings](#2-merged-per-item-findings)
   - [Phase 0 — crash & hygiene (0.1–0.6)](#phase-0--crash--hygiene-01-06)
   - [Phase 1 — detection core (1.1–1.4)](#phase-1--detection-core-11-14)
   - [Phase 2 — fragments & roll-ups (2.1–2.3)](#phase-2--fragments--roll-ups-21-23)
   - [Phase 3 — census & idioms (3.1–3.5)](#phase-3--census--idioms-31-35)
   - [Phase 4 — divergence (4.1–4.4)](#phase-4--divergence-41-44)
   - [Phase 5 — multi-repo output (5.1–5.4)](#phase-5--multi-repo-output-51-54)
   - [Phase 6 — reporting & ranking (6.1–6.4)](#phase-6--reporting--ranking-61-64)
   - [Phase 7 — beyond PHP (7.1–7.2 + jscpd)](#phase-7--beyond-php-71-72--jscpd-interim)
   - [Phase 8 — LLM groundwork (8.1–8.3)](#phase-8--llm-groundwork-81-83)
   - [Benchmark program (§6)](#benchmark-program-doc-6--f15)
   - [Effort-comparison table](#effort-comparison-table)
3. [The 80 GB memory story [f03]](#3-the-80-gb-memory-story)
4. [Hard blockers & codified bugs](#4-hard-blockers--codified-bugs)
5. [Design rulings — DECIDED](#5-design-rulings--decided)
6. [Owner-ruling list](#6-owner-ruling-list)
7. [Dependency / ordering graph](#7-dependency--ordering-graph)
8. [The concurrency map](#8-the-concurrency-map)

---

## 1. Executive digest

**Where the doc is right (overwhelmingly):** every load-bearing defect it names was reproduced live on the
scratch copies — the three crashes [f01], the 18.8M-pair / 4–7 GB-per-minute pair-list OOM arithmetic [f03],
the 167-member single-linkage cluster [f06], `extractedFunction` 59 %→56.6 % and `<missing>` 270→397 [f12],
impact-in-nodes ranking with its 3.5× inflation [f13], the nesting/subsumption and cross-repo numbers as
JSON-derivable [f15], line citations verified EXACT at `24841ff` [f08][f09][f12][f13][f15]. The fleet found
**no substantive over-claim** in the diagnosis sections. Effort labels are near-universal matches; the few
upgrades are listed in [§2 effort table](#effort-comparison-table).

**Where it is wrong (the four corrections that change plans):**

1. **Corpus erratum — the flagship acceptance examples are not on the pinned corpora.** The Vps↔Quickservers
   pair-set (1.1/4.1 acceptance, 20/28→28/28) exists **only in `/home/my`** (31/28 files; 28 same-name pairs,
   doc says 27) and is **absent from phlix at HEAD and at both doc-pinned commits** [f04]§verdicts [f08]§corpus.
   The §3.3 headline numbers (J=0.613/C=0.840) **do reproduce verbatim** — on `/home/my`, not on the tree the
   doc's §2.1 labels it — and `/home/my` is unpinned and moving [f08]§verdicts vs [f04]§corpus (adjudicated in
   §2 Phase 1 / §8). Consequence: **1.1/4.1 acceptance requires an owner-pinned snapshot** (or committed
   synthetic stand-ins) [f04][f08][f15]. Likewise the phlix **class-G ground truth is stale**:
   `EnvConfig::bool()` now exists in both repos with 18 call sites, and the base64url inline-copy yardstick is
   down to **1** site — so 3.2's done-when must re-anchor to the **micro-fixture**, which **exists in no repo
   and is itself an authoring deliverable** [f09]§headline-erratum [f15]§micro-fixture.
2. **`src/Scanning/DefaultExcludes.php` is dead code** — zero callers anywhere; the *effective* default list is
   the drifted inline `Config::defaults()` (Config.php:222–227), which lacks `.git` (harmless) and **lacks
   `**/*.blade.php` — blades ARE scanned** as ordinary PHP today; the doc's "blades excluded by default" is
   false, and so is AGENTS.md's citation of the dead class [f14]§phase7-verdicts [f12]§5.2. `FileScanner::looksLikePhp()`
   (:68–71) is a second gate the doc never mentions (extension whitelist {php,php8,phtml,inc}).
3. **The 1.1 fix sketch is unsound as written.** Un-numbering only the n-gram bag *promotes* drifted pairs
   into the TED gate where numbered labels corrupt the score — a pair accepted today via Containment can
   **flip to a J-tier DROP**. The bag and `AptedDistance::labelOf` must un-number together (§5a ruling)
   [f04]§tier-order.
4. **`--no-cache` does NOT stop the disk AST cache** (writes 18 MB of parser caches regardless; no
   `--cache-dir` flag exists — `cache_dir` is config-file-only, defaulting to the **relative** `.phpdup-cache`,
   so any shelled-out run litters the repo) [f01][f02][f12]. This silently corrupts the doc's own CI-smoke
   premise and every test-harness plan built on `--no-cache`.

**Where it is missing (fleet-surfaced, doc-silent):**

- The crash trio is **7 numeric flags, not 6** (doc omitted `--max-clusters`), and `--workers 0` — the
  documented auto-sentinel — crashes; the **CI smoke job is confirmed RED right now** [f01][f02].
- The doc's own candidate-generation fix menu for the OOM (stream pairs / df-cap tune / LSH) is **all three
  rejected or dwarfed** by a lossless multiset upper-bound filter that keeps **2.17–2.35 %** of pairs and loses
  **0 of 640k true edges** (42–46×, 80 GB→≈1.6 GB, zero golden/cache/TUI churn) — §3 [f03].
- `bench/` **already exists** (8 tracked files, incl. `BenchmarkScoreCalculator` with ±2-line member-overlap
  matching) — the benchmark program is an **extension**, not a build [f15]§corpus.
- Two further unwired modules the doc's §3.7 inventory misses: `BloomCandidateIndex` and `ExternalSort`
  (whose own docblock describes this exact streaming-merge use) [f03]; and the `§3.7` table itself is wrong on
  `BehaviouralSimilarity` (misleading docblock claims a wired fallback tier — zero callers) and `MlClient`
  (split verdict: `isAllowedUrl()` IS wired as the SSRF validator) while **omitting** the dead pair
  `DefaultExcludes` + `MutationTester` [f14]§3.7.
- The **partial-block-list cache hazard** (PreprocessStage:181–189 can cache a mid-file-truncated list),
  the **Socketpair Law** (nothing non-scalar ever crosses the worker wire — §4), the **`Cluster::$pairs`
  similarity-is-the-weakest-edge-on-the-path** semantic (0.8000 reported with J(first,last)=0.0000) [f06],
  the **latent IndexStore cache-key bug** (`allowedKinds` missing from the key — toggling `--kinds` on a warm
  cache silently serves stale) [f08]§risk6, and the **dual cluster-id streams** (`X…` content-stable vs `C…`
  index-/arrival-order-unstable) [f13]§4.4-claim [f06]§id.

**Program-level synthesis:** Phase-0 and the memory fix are one week of work with everything verified (§7
order). The deepest structural finding of the fleet is the **shared-mining substrate** (§5c): 2.1, 3.1, 3.2,
and 3.5 are four consumers of one in-worker per-file walker + one index — decided by arbitration BEFORE any
code [f07]§miner [f09]§placement [f04]§seams. The second is the **acceptance-realism rule** [f15]: every
done-when must run against a *pinned, vendored-derivable or locally-archivable* corpus, else the program
repeats the doc's untestable-ground-truth failure.

---

## 2. Merged per-item findings

### Phase 0 — crash & hygiene (0.1–0.6)

#### 0.1 Fix worker decoding (`allowed_classes:false`) — CONFIRMED, fix cheaper than the doc thinks
- Live-reproduced all three doc-listed crash command lines; `analyze src` = exit 255 [f01]§verdicts.
- `WorkerPool.php:191`: `@unserialize($payload, ['allowed_classes' => false])`. Every Block-bearing row
  arrives as `__PHP_Incomplete_Class`; the `@` suppresses warnings, then `:192` throws `RuntimeException`
  — the "thousands of warnings" in the doc's symptom text is wrong (it is one throw) [f01].
- **Fix design (f01):** decode with `SerializedClassAllowList::blockCacheClasses()` — the list already
  exists and is what `IndexStore.php:69` / `ClusterCache.php:68` use (the disk caches already round-trip
  these same objects). `ClusterCache` round-trip precedent proves the needed classes are on it. **The doc's
  "extend the allow-list" framing is a no-op: zero new classes** [f01]§fix [f09]§allowlist-correction.
  Keep the `__error` sentinel path; the fix is one call-site option + one allow-list swap on each of the
  three channels.
- **Acceptance correction [f02]:** done-when "analyze src exits 0" is right, but any test harness must not
  trust `--no-cache` (bug §4), must use absolute `cache_dir` temp configs (repo litter hazard), and any
  **>8-file** hermetic run silently serializes (fork threshold ≥8 files, `f01` §ordering) — the fork path is
  exactly where this bug hides. The 0.4 fork-vs-serial equivalence test is the regression net.

#### 0.2 CLI flag coercion — CONFIRMED, set is 7 not 6; `--workers 0` extra
- All VALUE_REQUIRED numeric options arrive as un-coerced strings into strict-typed `Config::__construct`
  → TypeError exit 255. **7 crashing flags: `--min-block-size --similarity --max-df --min-impact
  --fail-on-impact --max-clusters -j/--workers`** — doc missed `--max-clusters` (PR #102 era list;
  `--max-clusters` joined in the Q1 ConfigLoader rework) [f01]§0.2 [f02].
- **`--workers 0` (documented "auto" sentinel, Command.php:118) also crashes** — must be fixed with the
  trio [f02]§0.4-case2.
- Friendly-error target = **exit 2**, the repo's existing convention for invalid config (cast-failure
  today also lands at 2 after this fix; no new exit code) [f01]§0.2-fix.
- `--no-cache` truth lands in this neighborhood: the flag silences **IndexStore** reuse only; the disk
  `AstCache` keeps writing parser caches (Command.php:436–443 path) [f01][f02]§facts. Fix as part of 0.4/0.6
  docs row or 0.2 (decision: 0.4 harness works around it, 0.6/docs note it — owner-visible wording, not a
  semantics change, unless owner rules otherwise §6).

#### 0.3 Normalizer bare-return — CONFIRMED; companion paths already green
- Guard at Normalizer.php:321 (`count==1 && Return_`), actual **throw at :327** (`new Arg()` on null);
  doc's ":322" is root-cause-close, one line off [f01]§0.3.
- The doc's second done-when ("broken plugin → `parse_errors:1`, exit 0") **already works** —
  PreprocessWorker's per-file try/catch emits error rows end-to-end; only the Normalizer guard + a bare-return
  fixture encoding case are new [f01]§0.3.
- **Design addition [f01]§0.3-fix:** after the guard, bare-return single-statement closures need an explicit
  encoding decision (do NOT let them silently alias to the value-return form); keep `deepClone` copy semantics
  so the guard never mutates cached ASTs.
- **Bug id: this is BUG-A (§4); it poisons real corpora via worker deaths, not just scripts.**

#### 0.4 End-to-end smoke — CLAIM CONFIRMED, effort S–M → **M**, the test-architecture item
- No end-to-end invocation of `bin/phpdup` exists; **the CI smoke step is confirmed RED today** — it runs
  the crashing trio against `src/` with a numeric flag; every green badge on master is despite it being red
  (allowed failure / stale badge — [f02]§ci). **The doc marks CI red as "not verified"; the fleet verifies
  it red** [f01][f02].
- Corrected scope: cover all 7 flags × valid/invalid (exit 2), `--workers 0`, **fork-vs-serial equivalence**
  on a >8-file corpus (the §4-B1/B2 detector), exit-code contract (0 clean / 1 findings / 2 invalid / 3
  gate-trip), and the doc's "self-scan src" case. Test infra is the cost: temp-config absolute `cache_dir`,
  repo never written (index-hygiene), stdin `< /dev/null` per project rules [f02]§harness.

#### 0.5 Candidate generation — CLAIM CONFIRMED with one history nuance; **the doc's three fix options lose**
- `ClusterStage.php:207` `iterator_to_array` exact [f03]; nuance: the materialization was **born dead inside
  PR #81 — the lazy-iterator version never shipped**, so this is not a regression to bisect [f03]§verdicts
  (memory item (b)).
- Full arithmetic, the lossless multiset filter, and the rejected alternatives: **§3 (this file)**.
- Acceptance correction: doc's "98k-block corpus completes under ~8 GB" is **replaced** by the memory-bounded
  CI storm test (§3) — 98k-block phlix-style corpora need not exist; a synthetic storm does the same pinning,
  hermetically, per PR.

#### 0.6 Skipped-file reporting — CONFIRMED + design fixed; 'skipped' row type **pre-declared, never emitted**
- Zero-block vanishing reproduced on the repo itself: 156 files scanned → 150 counted processed; **max-4 +
  min-192 drops are invisible**; `ReportStage` early-return masks the whole skip surface when the corpus
  yields nothing [f02]§0.6.
- **Numbers disagree across surfaces** (scanned vs processed vs reported file counts) — the fix must pick
  one definition of "files" per counter and label it [f02]§0.6.
- Design: one `skipped`/`file_summary` **scalar row per file** through PreprocessStage:155–178 (the row-shape
  was already promised by PreprocessWorker's docblock — "the contract pre-declared"), `?LiteralCensus`-style
  trailing fields only where needed; effort **S** [f02]§0.6-design.

### Phase 1 — detection core (1.1–1.4)

#### 1.1 Un-numbered identifier fingerprints — highest-value item; **fix sketch refuted, corrected in §5a**; effort S–M → **M**
- Mechanism fully confirmed: `getOrAssign` at Normalizer.php:491 (per-block counter map); renumber cascade
  synthetic (VpsStart = QS + 2 guards) [f04]§verdicts. The doc's 8-pair payoff table reproduces in shape —
  **all 8 clear the 0.85 line un-numbered** (e.g. Start/Stop/Restart/EjectCd .840→1.000;
  ResetPassword .498→.992) [f04]§measurements against doc §3.3.
- C=1.000-after-stripping **reproduces** [f04]; J=0.613/C=0.840 **reproduces verbatim on /home/my** [f08]§verdicts —
  but the doc's §2.1 attributes the pair-set to phlix trees where it is **absent at every local commit incl.
  the doc's own pins** [f04]§corpus [f08]§corpus. **Adjudication: f08 wins on "the numbers are real", f04
  wins on the operative conclusion — wrong tree label + unpinned /home/my ⇒ acceptance needs the owner-pinned
  snapshot (§6).** The doc's "20/28 pairs linked today" is unverifiable without that pin [f04][f08][f11]§acceptance.
- The structural refutation (bag-only unsound) and the decided fix: **§5a** [f04]§tier-order.
- **Realnames (doc row 3) is the superior landing state** — it sidesteps the TED gate entirely via the
  containment tier, restores callee identity (DTO `toArray` noise control), and its J=0.791 already clears
  the 0.85-containment line; the §3.3 numbers were taken in realnames mode [f04]§realnames [f04]§measurements.
  v1 = un-number bag+labels (flag, default off); realnames is the 1.1-follow-up third mode (§5a note).
- Consumers census (do-not-break list): shapelet prefilter numbers only in **shortType**, the doc's "keep
  numbered tree for structural hash + anti-unification" holds, ML PairFeatures stays on numbered until later
  [f04]§consumers.
- Landing = the full **cache-coordination packet**: `fingerprintIdMode` knob into `toolFor` sprintf
  (PreprocessWorker:105–117), IndexStore configKey (PreprocessStage:78–95), ClusterStage cacheConfigKey
  (:25–46), `IndexStore PARSER_VERSION parser-v5-apted→v6` MANDATORY, `ClusterCache` v1→v2 (edges shift),
  Golden trio regen [f04]§caches [f06]§cache. Forgetting a key = **mixed-mode bags in one cache, silently**.
- JSON: schema **NO bump** for 1.1 (config block surfaces the mode value inside existing keys; `matchTier`
  strings grep to zero in goldens) [f04]§json; goldens move because pair/cluster content moves — **disclose
  every moved golden** (zero-golden law).
- Effort **M**; the 28/28 done-when is **owner-gated on the pinned corpus** (§6) [f04]§effort.

#### 1.2 Stop dropping large functions — CONFIRMED; measured cost justifies the design; effort M agreed
- The 340-function/34 %-of-include blind-spot numbers reproduce [f05]§3.4-verification.
- **TED measured cost: one 4,506-node pair = 161.4 s / 1.21 GB peak RSS** (treedist matrix; early-exit
  thresholds never fire on true clones) — the doc's suspicion, quantified [f05]§ted-cost.
- **Exactly two TED call sites** — `Clusterer.php:320` (serial/test path) and `PairScoreWorker.php:89`
  (the production path; serial also routes through the worker) — containment/IR/ML tiers are already
  TED-free [f05]§ted-sites.
- Decided v1 design: **config-only `max_ted_size`** (JSON int dodges BUG-C on unpatched master), pairs over
  the cap judged by **jaccard/containment** with a new **`jaccard-unverified` tier token spelled identically
  in BOTH parallel tier vocabularies** (Clusterer enum + PairScoreWorker const — the repo's known
  dual-vocabulary drift trap) [f05]§1.2-design; optional stmt-hash LCS surrogate later (0.1 s vs 160 s)
  [f05]§refinement. Default keeps golden byte-identity (cap ≥ old drop ⇒ nothing NEW is compared at default).
- Acceptance correction: doc's "qs_create/vps_create appear" **needs 1.1** (they are type-3 pairs); with 1.2
  alone the done-when is "large identical copies appear; drifted ones await 1.1" — ship **1.2 before 1.3**,
  interim acceptance documented [f05]§deps.

#### 1.3 Top-level script blocks — CONFIRMED end-to-end; two traps codified; effort M agreed
- Synthetic `Stmt\Block` wrapper round-trips through real Normalizer/SubtreeHasher/AntiUnifier and the
  **worker+disk caches already accept it** (php-parser classes are allow-listed) — probe verified the full
  script→aggressive→unify pipeline [f05]§script-kind-probe.
- `BlockAstLoader` **cannot relocate synthetic blocks under default lazyAst** (RuntimeException in
  RefactorStage) → relocation branch keyed on `kind==='script'` **and** `--apply`/RefactorPatchReporter
  must **refuse script clusters in v1** [f05]§loader-trap (memory item (j): sharpest trap in Phase 1).
- **Goldens stay byte-stable at 1.3** (default exclusion surfaces change block counts → goldens move ONLY if
  `script` joins default kinds; keep it opt-in `--kinds`-able, or accept one disclosed trio regen with the
  wave; seat verdict: byte-stable with opt-in posture) [f05]§goldens.
- Acceptance: the doc's "*_list.php family" example is /home/my-shaped — same pinned-corpus erratum pattern;
  micro-fixture 3-scripts→1-cluster is the hermetic version [f05]§acceptance-correction [f15]§micro-fixture.

#### 1.4 Replace single-linkage — CONFIRMED + mechanism sharpened; design DECIDED §5b; effort M agreed
- 167-member guard-chain **reproduced in a demo** [f06]§167. Code truth: union-find `Clusterer.php:196–242`;
  **reported `Cluster->similarity` is the weakest edge on the connecting path, not member-vs-member**
  (`componentMinSim` :205–224) — a 0.8000-labeled cluster whose first/last members score J=0.0000 [f06]§similarity-semantics.
- **CoherenceAnalyzer's basis is raw all-pairs Jaccard only** — it would *wrongly split* the §3.3 containment
  pairs (Demo C: J=0.667, C=1.000) — any split rule must run on the **tier-composite score** actually used
  for edges, not raw Jaccard [f06]§coherence.
- **Cluster ids are doubly unstable**: `md5($root)` where root = union-find root by **edge arrival order**
  (nondeterministic under parallel workers) on top of the index-embedded block ids (:271 + PreprocessStage:200)
  [f06]§id — strengthens 4.4's rationale and feeds the ID-stability ruling §5e [f06][f11][f08].
- Decided: greedy **medoid-ball inside Clusterer, post-union-find, consuming existing `Cluster::$pairs`**
  (zero new scoring); average-linkage **rejected** (4 h recomputation at phlix scale; containment is
  directed) [f06]§design — full ruling §5b. Lands inside ClusterStage so **both serial+parallel converge**;
  tighten knobs + **re-pin ClusterCache** (§5 cache packet); `splitClusters` default **ON** [f06]§cache.
  In-lane stopgap until 4.4 ships: regenerate cluster ids from **sorted member content hashes** [f06]§id-regen.
- Acceptance: doc's "167 breaks into ≤25 coherent groups + no member below threshold of medoid" is checkable
  **hermetically via the synthetic storm fixture** (the pinned-corpus erratum again) [f06]§acceptance [f03]§plan.
- Ordering: **1.4 with/before 1.1** (f06 §dep; f04 concurs — 1.1 raises edge counts into exactly this disease).

### Phase 2 — fragments & roll-ups (2.1–2.3)

#### 2.1 Statement-sequence fragments — CONFIRMED; becomes the OTHER half of the shared miner; effort L agreed (L+)
- The class-B gap is confirmed end-to-end (no sub-block unit exists; the 114-method × 9-line preamble is
  seen only as standalone if-Blocks or not at all) [f07]§verdicts. `go(mixed $expr)` / `<missing>` behavior
  verified [f07].
- **Doc corrections [f07]§corrections:** `DataflowSummarizer` is NOT unwired (3 callers — but all behind
  `--ml-pair-url`/dead chains) and is **NOT FIT** for live-variable analysis (no read/write distinction,
  no order, descends closures → would fabricate params); `BlockAstLoader` needs a real fragment arm (unknown
  kinds THROW); the doc's "emit in PreprocessWorker" suggestion is **wrong** — master-side derivation gets
  the same data at **zero wire cost** and never re-triggers crash#1 (this is the origin of the Socketpair Law)
  [f07]§design-deviation.
- Design (S-piece ≈1–2 d stitching; L-piece = the miner): per-file walker over every statement list;
  **statement-hash via `AntiUnifier::stmtHash` formula extracted to a shared `src/Util/StmtHash`** (two
  stmt-hash formulas currently coexist — pick deliberately); memory-bounded two-pass index; `fragment_k=3`,
  `fragment_min_sites=3`, `fragment_max_gap=1`, cap 40 stmts/run; suppression via whole-block-equality +
  nested-family + parent-fold **once, shared with 3.1/6.3/7.2** [f07]§miner [f09]§shared-rules.
- **Placement: DECIDED by arbitration (§5c): single in-worker per-file walker + one SequenceIndex; NO new
  Stage enum case** (9-file TUI/enum cost and the stage would run *serially* — the §3.6 failure mode)
  [f07]§arbitration-note [f09]§placement [f04]§seams. The stitching half (f07) runs master-side after
  ClusterStage consuming `Cluster::$pairs`; the mining half feeds 3.1/3.2.
- `--apply` refuses fragment clusters in v1 (same contract as 1.3 script refuse) [f07]§apply.
- Acceptance correction: "≥100 members on /home/my" is the pinned-corpus pattern again; the 5-member
  preamble micro-fixture family is the hermetic pin [f07]§acceptance [f15]§micro-fixture.

#### 2.2 File/class/directory roll-ups — claims VERIFIED EXACT (incl. the 39/68 seed counts); 3 design holes plugged; effort M agreed
- 39 same-relative-path + 68 same-basename seed pairs between the pinned phlix trees: **verified exactly**
  [f08]§2.2-verdicts. RateLimit mirror directory (5 files) verified.
- **Three holes the doc omits, all plugged by f08:** (1) roll-up input must include **all blocks, not just
  clustered** ones (`PipelineState::$blocks` survives — only ASTs are dropped); (2) seeding of
  zero-block/declaration-only files is **partially impossible without 2.3 class-kind + 1.3 script-kind** —
  explicit dependency §7 [f08]§2.2-verdicts2; (3) the doc's stable-id precedent is **wrong**:
  `BaselineStore::computeBlockHash` (:216–220) is **positional** (file.start.end) — rollup ids must key on
  sorted **file+structuralHash** content or they churn on any line shift [f08]§2.2-verdicts3 [f11]§4.4.
- Design: `RollupAnalyzer` in `src/Reporting/` (precedent CoherenceAnalyzer), post-process over clusters+
  blocks; coverage = **interval union of extracted ranges, never naive summation** (Vps/Start: true 29/51,
  naive 74.5 %); twin(A→B) ≥0.5 **both directions**; `mirrored_directory` by dirname-pair grouping;
  `Report` gains **trailing-defaulted `$rollups` param** (keeps ~28 Report-constructing sites compiling);
  opt-in `VERBOSITY_ROLLUPS`; JSON: additive → **one coordinated SCHEMA 1.1→1.2** (this seat may be
  first-lander — §6 schedule ruling); Golden trio regen coupled [f08]§design.
- Acceptance correction: Vps↔QS roll-up done-when = **pinned snapshot** (erratum); `report-only` fallback:
  the phlix **RateLimit mirror** and the vendored 26-twin subset test the same machinery hermetically
  [f08]§acceptance [f15]§check-files.
- **DTO-noise contamination risk:** the roll-up numerator must exclude §6.2-demoted declarative shapes or
  55×`toArray` mega-clusters fake-inflate twin coverage → **land 6.2 demotion before 2.2 ships its headline**
  (ordering §7) [f08]§risk9.

#### 2.3 Class-level whole-file — diagnosis CORRECTED (changes the design); effort M → **M–L**
- RateLimitState twin: textual 58/58 lines confirmed, **coverage 24.1 % not 10 %** (the doc discarded the
  empty-bodied promoted-ctor from its own count); both method blocks extract **byte-identical** structural
  hashes — **clustering already links it**; the real gap is the ~44/58 non-method lines living inside no
  extractable unit [f08]§2.3-diagnosis.
- ServiceProviderInterface "zero shared clusters": **FALSE as detection, TRUE as report** — its abstract
  `register()` lands in an exact-hash cluster **then dies at `min_cluster_impact`** (impact 9 < 20). The
  doc's ≥90 %-by-2.3 target is **unreachable without the floor exemption / lines-impact** ⇒ owner-ruling
  (memory item (a); the roadmap's accident of listing 6.2 earlier must be made EXPLICIT) [f08]§ssi
  [f13]§impact [f04]§verdicts.
- Design: shape fingerprint via **existing** groundwork (SubtreeHasher kind-agnostic;
  `AstSerializer::scalarPart` id:/n:/v: tokens free; `AntiUnifier::shouldSkipSubnode :486–497` already
  special-cases declaration name subnodes — the doc under-credits its own codebase) [f08]§groundwork.
- **Exact shape-equality refuted for the ×64 skeleton-family claim** — probe on 3 real Plugin.php shells:
  every exact shapeHash differs (Oidc adds 3 props + a const; member ORDER differs) yet 6/11 methods are
  byte-identical → ship **shapeCore = member-set Jaccard ≥ ~0.7** (overlap) + shapeExact for declaration
  twins; the "identical/parameterizable/divergent" table is **already computable** from per-method hashes
  [f08]§shape-design [f08]§skeleton-already (f09 concurs: `--class-shape` opt-in posture, §6).
- `×64 Plugin.php` is from the agent-report corpus (/home/my refactor.md), not phlix — **pin the corpus in
  the acceptance line** [f08]§plugin-count.
- Cache-bug landmine (latent, fleet-found): **`allowedKinds` missing from the IndexStore configKey** —
  after adding `class` kind, warm caches serve stale `--kinds` runs silently; fix the key FIRST in the same
  PR [f08]§risk6. Keep class kind **off the scoring hot path** (shape stored on Block as scalars; only bag
  compare when nodes ≤ max_block_size — the §1.2 wall again) [f08]§2.3-design. TED over whole class bodies
  is the cost 1.2 exists to avoid — **0.5/shape-only BEFORE default-on class kind** [f08]§risk9.

### Phase 3 — census & idioms (3.1–3.5)

#### 3.1 Frequent expression subtrees — claims CONFIRMED; the doc's key mechanism is UNUSABLE as implied; effort M–L agreed (L-leaning)
- Idiom prevalence re-counted: 30/31/21/**268** (json(['error' drifted +2 — record **counting rules**, not
  raw numbers, in pins) [f09]§headline-erratum [f15]§number-check.
- **Load-bearing blocker the doc doesn't see:** the strict/default modes it cites as the exact/abstract pair
  are **unusable as subtree keys** — `CanonicalizingVisitor` is a block-scoped state machine (var numbering
  by first appearance in the ENCLOSING block), and SubtreeHasher is **position-free** (whole-tree tokens →
  hash; cannot answer "where") ⇒ idiom keys need a **dedicated bottom-up fold** (DECIDED §5d)
  [f09]§defect.
- Decided emission substrate (DECIDED §5c/§5f): rides **Clusterer's Phase-1 hash-bucket machinery** as
  pseudo-Clusters/pseudo-Blocks — zero scoring, zero candidate-gen load, no new payload type; **but** f09's
  "second consumer of the shared in-worker walker" framing wins the placement question vs 2.1's separate-
  miner suggestion (§5c arbitration record) [f09]§placement [f07]§miner.
- Thresholds: `idiom_min_sites=3`, **`idiom_min_files=2`** (the "5 copies in 1 class" local-idiom trap),
  parent-containment pruning + count-only-then-locations memory discipline [f09]§thresholds.
- **New defect surfaced [f09]§ranking-isolation:** 268-site `json(['error'` **swamps the ranker** (rank-1 by
  a mile) — 3.1 must land **coupled with 6.2 demotion** or it recreates the exact §3.5 noise failure mode;
  also 6.3 subsumption required BEFORE 3.1 ships (f09 §soft-upstream; §7).
- `canonicalizeLiterals` gap: does NOT blank `ConstFetch`/`ClassConstFetch` (PHP_INT_MAX/true/false leak
  into "exact") — fold design must blank at leaf level deliberately [f09]§leaf-blanking.
- Acceptance corrections: doc's idiom examples are phlix-/home-my-counted — replace with **micro-fixture +
  recorded greps on pinned trees**; "counts near grep" survives as the ±15 % rule [f09]§acceptance [f15]§spot-check.

#### 3.2 Helper re-implementation — done-when REFUTED by ground-truth staleness → re-anchor to micro-fixture
- `EnvConfig::bool()` **exists** in both repos (server :92, hub :156) with 18 call sites/12 files; base64url
  inline copies down to **1** site; the doc's G-class gap is partially CLOSED upstream ⇒ its §3.4/§3.2
  numbers are stale [f09]§headline-erratum [f15]§corpus. 3.2's done-when ("5 inline copies Env::bool+b64url
  each") is **only satisfiable on the micro-fixture** — and the doc's own §3.8-vs-§6 contradiction (citing
  EnvConfig as both gap and target) proves it needs re-anchoring [f09]§contradiction.
- Mechanism = **digest reuse, not a new matcher** (§5c consumer; f09 §lookup): fold the helper body with the
  shared 3.1 fold → lookup hits = inline copies; adoption counts need **ordered in-worker** callee capture
  (CallGraph runs post-unload ⇒ nothing under default lazyAst; G-6) + `nameOf` limitations recorded
  (receiver-blind; self/static → '?') [f09]§3.2.
- **Emit `helper_copy` pseudo-Clusters; adoption rides `Cluster::$architecturalFindings` (existing path) —
  avoids the SCHEMA bump the doc assumed necessary for 3.x** [f09]§3.2-emission.
- 3.2 needs 3.1 (§7); strictly after [f09]§seam.

#### 3.3 Literal/constant census — CONFIRMED with one number-rule correction; effort M agreed
- Mechanically true: `canonicalizeLiterals` erases literals in the compared representation (all modes except
  strict) so no existing signal can see them — census must read **RAW AST** in-worker [f10]§3.3-verdicts.
  "86400 on 112 lines": order-of-magnitude claim; record counting rule instead [f10].
- Design: `LiteralCensus` VO + per-file `census` scalar row through the PreprocessStage contract (same
  shape class as 0.6's `skipped` — coordinate); buckets: strings ≥12 chars, numeric ≥2 files
  (grouped by value+unit context), array literals ≥3 elements with **order-insensitive hash**,
  interpolated-SQL skeletons via `AstSerializer::scalarPart` (:119–129) + `SqlTableExtractor` (:35, exists,
  already used by DbOpCanonicalizer/IrLifter) [f10]§3.3-design. Near-dup families by edit-distance
  (Levenshtein) within length bands [f10].
- **Emission = `Report` trailing-defaulted `?LiteralCensus` param + own JSON `census` field; opt-in
  `--literals FILE`; ONE SCHEMA bump shared with 4.3/5.1** [f10]§3.3-emission.
- Default-OFF: zero golden churn (census rows inert without flag) [f10]§3.3-goldens.

#### 3.4 Duplicate symbol index — CONFIRMED trivial; effort S agreed
- All inputs **already resident**: `Block->{name,class,namespace,file}` + ScanningStage file list ⇒
  same-name / same-class-short-name / same-basename maps = post-pass, **no new extraction kind** [f10]§3.4.
  This is exactly why it is S and why it must NOT grow into a 2.3 duplicate [f10]§3.4-scope.

#### 3.5 Call-sequence idioms — CONFIRMED; doc's premise half-wrong (CallGraph unusable as-is); effort M agreed
- CallGraph verdict trio: built but **zero pipeline callers**; unordered signature map (:29–52); its own
  docblock cites `CallGraphSimilarity` — a class that does not exist (same doc-rot family as
  BehaviouralSimilarity) [f10]§3.5-verdicts [f09]§3.7 [f14]§3.7. SolidAnalyzer:57–69 re-rolls its own
  callee walk — consolidation opportunity real [f10].
- Design: **ordered** callee sequences extracted **in-worker before unloadAst** (one walker, §5c), mined by
  sliding windows k=2..4 over the sequence; **replace constant ARGS with the name token** — the protocol
  table ("two clusters differing only in constant arguments": `CURLOPT_SSL_VERIFYPEER true|false`) falls out
  as same-shape-different-name-token families; emit idiom pseudo-Clusters + PatternRecognizer tags +
  adoption counts; report-only tier by default (new SCORING tier needs the §5a-style gate discussion —
  deferred) [f10]§3.5-design [f07]§35-seam.

### Phase 4 — divergence (4.1–4.4)

#### 4.1 Drift analyzer — depends-on-1.1 VERIFIED code-exactly; doc's "keep node references" prescription refuted → descriptor design
- Dependency proof: the §3.3 pair is not in one cluster (J=0.613 below any edge; C=0.840 vs gate 0.85 ⇒
  **no edge exists ⇒ union-find never joins ⇒ zero holes to analyze**) — 4.1's substrate literally does not
  exist before 1.1 [f11]§4.1-dep.
- `present_in_members` is **computed at render from `<absent>` markers, not stored**; positional contract on
  `Hole::$observedValues` is the reliable one [f11]§4.1-substrate.
- **Node-ref refutation:** raw `?Node` on Hole would pickle member subtrees across the socketpair (crash#1
  class) — under lazyAst the parent-side nodes are null ⇒ the doc's prescription "would silently produce
  zero findings in parallel mode". Decided replacement: **scalar OBSERVATION DESCRIPTORS** co-recorded in
  `AntiUnifier` where the node is already in hand (`type`, ≤8 `callNames`, `containsExit`, `scalar`) —
  plain scalars, wire-safe, cache-neutral [f11]§4.1-descriptors [f09]§socketpair-law.
- Predicates (guard-missing via optional_block holes whose template node is If_/Loop_ with
  Return_/Throw_/exit + '<absent>'; sanitizer divergence via `args/<k>` paths + repr comparison;
  copy-paste-literal via literal-hole cross-member contains; odd-one-out via uniqueValueCount + outlier
  seed) all ride `holePaths` + `generalizedAst` (deep clone of ORIGINAL raw member — names survive)
  [f11]§4.1-design.
- Emission via the existing `ArchitecturalAnalyzer` interface + `Cluster::$architecturalFindings`
  (zero new wiring); drift vocabularies as **profile JSON keys** (mechanism verified end-to-end:
  profileToOverrides→whitelist→merge) [f11]§4.1-emission.
- Acceptance: Vps↔QS "missing guard ×4" — pinned-corpus erratum applies; ship the **synthetic
  guard-drift fixture** (same as 1.1's) as the hermetic pin; keep the f08-verified `Start.php` pair as the
  regression fixture [f11]§acceptance [f08]§verdicts.
- **Optional-hole cap 3 (Config.php:50) quantified as a live defect [f05]§caveatB / [f11]§risk: >3 drifted
  members collapse to whole-array holes — the guard signal is LOST not degraded; tune the cap when landing.**
- Effort M–L → **M** (substrate already present) [f11]§effort.

#### 4.2 Git history signals — CONFIRMED feasible; subprocess precedent + failure posture decided; effort M agreed
- Reuse ScanningStage:77–109 git-shell precedent (escapeshellarg, rc!=0 ⇒ warn+skip, never crash);
  `--history` default OFF = zero golden/cache churn [f11]§4.2-verdicts.
- Decided: blame **per cluster-member file only** (bounded), `--porcelain` parsed once →
  line→(sha,author,time,msg); on-disk cache keyed by repo@sha+file under `cache_dir`; co-change via ONE
  `git log --name-only --since` per invocation; "lone-fix commit" detector = the doc's 14-bugs pattern,
  machine-checkable [f11]§4.2-design. Non-git / missing binary ⇒ skip with a note (exit code unchanged).
- Shared primitive with 5.1 (nearest-.git + composer.json resolver) — coordinate or double-impl
  [f11]§4.2-5.1seam [f12]§5.1-design.

#### 4.3 Declared-twin checker — CONFIRMED; comments survive both cache round-trips (verified probe); effort S agreed
- The registry-comment harvesting substrate works on cached data: php-parser 5 keeps comments, AstCache +
  Normalizer deepClone preserve them (probe-verified byte-identical) [f11]§4.3-verdicts.
- **Trap [f11]:** file-header "Mirror of X" comments attach to the FIRST statement (namespace/class decl),
  which is **not an extracted block kind** ⇒ scan whole-file top-statement comment lists, not just Block
  ranges [f11]§4.3-trap.
- Design: regex on doc-comments (mirror|twin|ported|keep in sync) → referenced twin → measured
  pair-similarity (via retained bags) → claim-vs-reality table incl. **FALSE claims** (drifted away);
  `declared_twins` on Report (trailing param, rides the one schema bump) [f11]§4.3-design. Independent of
  1.1 — drifted claims are exactly the un-clustered pairs [f11]§ordering.

#### 4.4 Accepted-divergence registry — instability CONFIRMED at both cited lines + one worse fact; ordering: FIRST in Phase 4
- `PreprocessStage:200` index-embedding verified; `Clusterer:271` md5(root) = **arrival-order nondeterminism
  in parallel mode** (worse than the doc says) [f11]§4.4-verdicts [f06]§id.
- **Decided ID recipe [f11]§4.4-design:** `'F'+sha256(sorted member sha256(file.'|'.structuralHash))` —
  content-keyed (structuralHash NOT positional: the doc's "as BaselineStore already does" is only half true —
  BaselineStore's block hash IS positional (file.start.end) and must NOT be reused as-is) ; accepted
  iff per-member `@phpdup-ignore <id>` (harvested in 4.3's shared comment pass) OR root-config registry list;
  **render to appendix, never silently drop** [f11]§4.4.
- FindingId **early** — consumed by 6.1 anchors, 8.1 pack cache keys, and 1.4's in-lane stopgap converges on
  this derivation [f11]§ordering [f06]§id-regen.

### Phase 5 — multi-repo output (5.1–5.4)

#### 5.1 Repo/package labels — penalty anchors EXACT + newly quantified; effort S–M confirmed (M end)
- `Ranker.php:94` (ns>3 → −0.05) and `SafetyScorer.php:62` (ns>3 → −0.10) confirmed line-exact; f12's own
  2,649-cluster run quantifies: **13.2 % of clusters take the double penalty; 57.2 % ineligible for the
  single-class bonus; 226 cross-ns clusters also carry extractedFunction signatures** [f12]§5.1. 550
  cross-repo unreported pairs (doc) consistent with 724/27.3 % already spanning ≥2 packages [f12].
- Design: `RepoLabels.php` resolver at ScanningStage (composer.json + .git remote, cached per dir; reuses
  ScanningStage's git-shelling precedent :77–109); **labels are scalar Block fields — allow-list untouched**
  (Block is whitelisted) but **IndexStore `PARSER_VERSION` v5→v6 MANDATORY** or stale `.idx` files serve
  silent `''` labels (worse than a rescan) [f12]§5.1-design (memory (k)). `Cluster::$scope`
  (intra-file/package/cross-repo) + `--scope` filter + summary counts; penalty inversion rides the ScopeBias
  VO **audit-mode only — default scores stay byte-identical** (snapshot-pinned) else stored baselines move +
  exit-4 fires fleet-wide [f12].

#### 5.2 Exclude handling — doc UNDERSTATED a live bug; effort S → **S–M**; the dead-class correction
- Three new facts [f12]§5.2: (i) **DefaultExcludes.php is dead code** (§1 correction #2; independently
  confirmed by f14) — effective list = drifted `Config::defaults():222–227`; (ii) exclude lists **REPLACE,
  never merge** (ConfigLoader:89–91 ternary) — any user/profile exclude WIPES all defaults; (iii) stock
  defaults scan **57,120 phlix files** (every nested per-package `vendor/` missed) vs 1,727 with proper
  `**/vendor/**` — but that workaround itself over-matches: `**` compiles to `.*` crossing `/` so `myvendor/`
  and `notvendor/` hit too [f12]§5.2-facts. `.gitignore` honoring: zero implementation.
- Design (staircase, pattern fix = S, full item = M): defaults single-source into `Config::defaults()`;
  segment-safe `**/` → `(?:[^/]*/)*` (CHANGELOG — behavior fix); append-merge + `--exclude`; optional
  .gitignore parse; composer.lock `VendorMarker` (Block::$vendored; auto-demote vendored↔vendored under
  --scope). **5.2 before any multi-root/rollup run or its denominators are vendor-polluted** [f12]§ordering
  [f08]§coverage-denominator.

#### 5.3 Where shared code lives — M confirmed; form-choice distribution is the new fact
- Empirical form tree on f12's run: **6.0 % all-literal-holes (table-driven), 19.8 % zero holes (exact
  move), 37.3 % carry a subtree hole (→function) — and 37.3 % depends on 5.4's expression capture landing
  first**; 1,133 single-class clusters (trait/base evidence) [f12]§5.3. `Hole` kinds + observedValues
  sufficient — no AST access needed. New `PlacementAdvisor` post-RefactorStage: canonical probe (3.2 soft-dep),
  LCN from existing fields, common-required-package intersection (composer parse = new), 'create shared
  package' fallback. **Hard-dep 5.1 labels** [f12]§5.3.

#### 5.4 Usable abstraction output — magnitudes confirmed (59 %→56.6 %, 270→397); two prerequisite fixes the doc missed
- Root cause exact: `SignatureBuilder:88–90` names clusters ONLY from member block names; forced-unnamed
  kinds if/foreach/try/closure/arrow = 100 % extractedFunction (1,021+133+85+70+60 ≈ 1,499) [f12]§5.4.
  **Body-naming is cheaper than the doc says:** raw callee names DO survive in `generalizedAst` (deep clone
  of raw seed AST) → mine them; `mixed $expr` in 36.7 % of signatures via the identifier-hole fallback
  [f12]. `<missing>`: single source `AntiUnifier::repr:508` — replace with `null` + degraded-flag convention
  [f12]§5.4-missing.
- **Prerequisite 1: `Cluster::$templateMemberId` never persisted** (seed swap is local; DiffReporter diffs
  members[0]) — doc's "include template + one representative diff" requires this one field [f12]§5.4-seed-bug.
  **Prerequisite 2: the >40 % holes rule needs a `HoleCoverage` computation that doesn't exist**
  (holePaths are transient on the unify context) — and dropping 37.3 % of clusters guts recall ⇒
  re-express-as-fragment default + `--max-hole-coverage` knob [f12]§5.4-coverage.
- JSON source/template/diff = genuinely new surface (§2.2 claim of doc confirmed); `sebastian/diff` already
  a runtime dep — template + evidence_diff is **free reuse, but caps mandatory** (f12's JSON was already
  37.1 MB) [f12]§5.4-json.
- Effort M confirmed (M-end) [f12].

### Phase 6 — reporting & ranking (6.1–6.4)

#### 6.1 audit-md reporter — M confirmed; the renderability map is the deliverable
- Exactly **4 code + 2 docs edit sites, ZERO Config keys** (report paths live in `$reportArgs`) — smallest
  structural footprint in Phase 6; `--checkstyle` is the exemplar to clone [f13]§6.1.
- Doc's section list mapped to data readiness: sections 1–3 + Evidence render **today**; the rest gate
  item-by-item (§8 rules; the renderability table in [f13] carries the full map). Ship shell early, sections
  flip on as producers land — monotonic improvement, no rewrite.

#### 6.2 Ranking overhaul — anchors confirmed (impact-in-nodes, 3.5× inflation measured); two landmines
- **The impact→lines flip is a CI-facing behavior change** (ci.yml `--min-impact 5`, README "≈ duplicated
  lines" already wrong vs nodes [f13]§impact, recorded baselines, exit-3 gates) → **owner ruling §6#1**;
  dual-quantity mitigation (keep `impact` populated, add `lines`) [f13]§6.2-impact.
- Cross-ns penalty reversal collides head-on with 5.1's ScopeBias — f13's ruling: **confidence arithmetic
  (6.2) and label semantics (5.1) must not hard-depend**; coordinate or ship a mess [f13]§6.2-crossns.
- **Declarative demotion must be designed against `$canonical`, not real ASTs** — parallel mode returns
  enrichment-only (no parent-side nodes), so an AST-dependent demotion silently mis-ranks serial-vs-parallel;
  canonical keeps callee identity only as `__CALL<N>` numbering ⇒ usable for **diversity counts** (distinct
  count = precise; identity = ngramBag proxy) [f13]§6.2-canonical.
- Done-when ("top-20 no DTO/route tables") needs the pinned/phlix bench + labels (§6-bench); shape-test
  pins work on synthetic declarative fixtures until then [f13]§6.2-acceptance.

#### 6.3 Subsumption — nesting reproduced on fixtures; placement corrected: **ReportStage pre-Ranker**; effort S–M → **S**
- Method+inner-if+foreach = 3 clusters CONFIRMED on repo fixtures (4 relations/6 clusters, chained) [f13]§6.3.
- Design: fold B under A via **injective member map** (each B member inside a DISTINCT A member — naive
  per-member containment false-folds sibling ifs of one method); chains fold to maximal ancestor;
  annotation `subsumedBy`/`subsumes` on Cluster (rides the Cluster.php field batch); **fold AFTER 1.4's
  split** (guard-chain sub-groups then subsume under tighter DTO clusters — "split-first-subsume-after",
  [f06]§seam); the one suppression mechanism shared with 2.1/3.1/7.2(c) [f13]§6.3 [f07]§suppression [f09]§shared-rules.
- Effort **S** — the doc's S–M was right-leaning [f13]§6.3.

#### 6.4 Themes — heuristic floor S–M; real theming = 8.2 (doc already defers to LLM) [f13]§6.4.

### Phase 7 — beyond PHP (7.1–7.2 + jscpd interim)

#### 7.1 Language-agnostic tier — doc claim mechanically true; **blocked by the second gate it missed**; effort M–L → **L (native)**
- The `.js`/`.yaml` examples can never reach extraction today because of `looksLikePhp()` (:68–71) IN
  ADDITION to excludes — widening the doc's "exclusions" alone is a no-op; needs `looksLikeText()` + a
  text-scan branch (owner decision on widening: §6#6) [f14]§phase7-verdicts.
- Decided carrier architecture (f14): synthetic `Block(kind:'text')` with `Stmt\InlineHTML` as the raw-text
  canonical container (typed non-Null constraint respected — **not a type-widening**, contra the tempting
  route); token k-window fingerprinting **reuses the exact loop in `NgramFingerprint`** — which 1.1 also
  edits ⇒ **NgramFingerprint+AstSerializer.tokens() is the hardest file collision in Phase 7 ⇒ 7.1-native
  only after 1.1 lands** [f14]§design-7.1 [f14]§hot-files.
- Pairs enter as **pre-computed edges** via `Clusterer::cluster()`'s existing external-edges contract —
  no union-find surgery; `MatchTier::TextLex`; a kind-guard sweep (`text` never reaches TED/AntiUnifier/
  RefactorPatch/Apply) is the FIRST PR [f14]§design-7.1 [f14]§risks.
- **jscpd interim premise DATED by the fleet: v5.4.3 is a Rust binary — the doc's "cost of a Node dependency"
  is obsolete** (zero PHP-runtime dep; cost = CI binary provisioning + version pin + adapter). Effort
  **M, 2–4 d**; recommend interim now, native only if same-pipeline features (packs/drift/stable-ids) prove
  needed [f14]§jscpd.
#### 7.2 PHP in templates — (a) is a one-line `Config::defaults()` flip (tpl exclusion) + the DefaultExcludes
wire-or-delete decision, **zero code otherwise — interim already possible today via bespoke `--config`**
(exclude lists replace, so a config without tpl re-enables it); (b) inline-`<script>` extraction via
`token_get_all` T_INLINE_HTML spans (byte-exact probe; no full parse ⇒ immune to broken templates;
php-parser Token-API mixing trap recorded); dedup of script-vs-host overlaps rides the ONE suppression
mechanism (§6.3) [f14]§7.2-design.

### Phase 8 — LLM groundwork (8.1–8.3)

#### 8.1 Evidence packs — M agreed, but the doc overstates today-availability; pack field×dep matrix is the deliverable
- **Available with zero upstream deps:** id (⚠ only via BaselineStore-style sorted-content hash — do NOT put
  `Cluster::$id` in a cache key: §4.4 instability), kind/exact/similarity/impact/tags, signature+holes
  (printed strings; **node refs NOT kept** — descriptors §4.1 carry what drift needs), members
  (file/ranges/kind/ns/class/name — already in JSON), source excerpts (pattern exists:
  `@file()`+`FILE_IGNORE_NEW_LINES`+slice, DiffReporter:79–82), template text (PrettyPrinter over
  generalizedAst ≈40 LOC + **fidelity disclosure** — normalized printer output, not source), per-member
  diff (sebastian/diff already a dep), outliers (badge only).
  **Blocked:** drift_flags←4.1, history←4.2, adoption←3.2, scope←5.1, side_effects←MutationTester-unwired
  skeleton at best [f14]§8.1-matrix.
- No blocker of its own; value roughly doubles after 1.1+5.4 [f14]§deps.
#### 8.2 phpdup audit — pattern-ready, wire-absent; M–L → **L leaning**
- Transport skeleton EXISTS = `MlPairClient` (curl postJson, null-on-failure, timeouts, scoreBatch = the
  batching contract) — but **zero LLM transport exists; the doc's "starting point" overstates** [f14]§8.2-transport.
- **Command::runAnalysis extraction is the pivotal shared refactor** (Command::execute embeds the whole
  drive; serve-params (§8.3) needs the same extraction) → schedule deliberately, hot-file collision with
  every Command edit (0.2, 0.4, 5.x flags) [f14]§8.2-command.
- Decided posture: ENV token only (never config — **JsonReporter embeds the config section in reports**);
  `llm_url=''` default disabled; temp 0 + host+model+**PROMPT_VERSION** cache key (TokenCache content-hash
  is the model) storing **annotations only, never code**; failure = un-annotated report ships, exit unchanged;
  SSRF guard reuse + explicit `llm_allow_private` opt-in (no silent weakening); cost caps
  `llm_max_findings` (top ~100) + `--dry-run-packs` [f14]§8.2-security [f14]§8.2-cost.
- Prereqs: 0.2 (flags must not crash the audit entrypoint) and 0.5 (an 80 GB OOM makes 8.2 un-runnable at
  audit scale) — memory (d) task-line: "plugins DispatchesInboundEvents"-style gate: 8.2 after 0.2+0.5 [f14]§deps.
#### 8.3 MCP over serve — real state audited: **7 routes not 4** (SERVER.md stale, /jobs/cleanup is a dead
doc claim), **`buildSummary` discards the raw report** ⇒ the job API today cannot answer ANY per-finding
question — retention/`ReportIndex` infra lands FIRST; `find_clones` = only genuinely-buildable tool now
(block ranges + 3.4 names); twins/census/reimpl tools **blocked behind 2.2/3.1/3.2 with `blocked_on`
annotations in their facades** (false authority worse than nothing); stdio JSON-RPC hand-rolled per the
ServeCommand precedent (no maintained PHP MCP SDK; zero mcp refs in repo) [f14]§server-truth [f14]§8.3-design.

### Benchmark program (doc §6) [f15]

- **Every corpus input is available on this host and verified:** refactor.md both corpora readable;
  **doc-pinned commits 375f7992 (server, 796 src files) + ba1ae3b8 (hub, 234) PRESENT locally** — extract via
  `git archive` (read-only law: never worktree add); `/home/my` = ad58b221c5 + 96 vendor-HEADs ⇒
  `corpus-pins.txt` snapshot is a one-loop deliverable (§6#5) [f15]§corpus.
- **`bench/` already exists** (run-all.sh/score.php/BenchmarkScoreCalculator with ±2-line overlap matching)
  ⇒ audit-truth = NEW `bench/audit-truth/` + `score-audit.php` riding existing semantics [f15]§corpus.
- **Micro-fixture exists in NO repo — authoring it IS a deliverable:** 5 of 14 files prototyped from the
  doc's prose alone and run through unmodified master ⇒ **reproduced doc §3.4's exact failure signature**
  (3×5-member if-clusters + 2-member method cluster) — red-state-AS-the-pin; per-PR home =
  `tests/Integration/MicroFixtureAuditTest.php` (<8 files ⇒ dodges crash#1 today; CI-hermetic, no network)
  [f15]§micro-fixture.
- Ground-truth extraction pipeline = script (2 adapters: /home/my four-part shape holds; **phlix refactor.md
  has a DIFFERENT anatomy** — inline prose cites in 3 dialects, W6 currency stamps MUST be stripped, 138/149
  heads parse) + one LLM pass + manual sign-off; **never re-extract against post-scan HEADs** (CURED findings
  still count — corpus pinned at scan-era) [f15]§adapters.
- Recall/precision defined: overlap ≥k members (k=2 or half for big families), top-50 precision with
  {signal,noise} labels; **never match findings by cluster id** (4.4) — by member overlap only; the doc's
  7-row spot-check table gets one mechanical regression per row, most runnable TODAY over the vendored
  26-twin+8-decoy subset (≈40 files ~0.5 MB, serial <5 s) [f15]§check-files [f15]§pipeline.
- Effort: **≈5–7 spread days**, independent of engine phases except Phase-0 for any >8-file run [f15]§effort.
- **Privacy gate (§6):** the refactor reports are a latent security-bug register (unfixed holes + W6 stamps
  naming fix commits) — vendor ONLY derived truth JSON, gate `audit-truth/` commit behind owner consent
  [f15]§privacy.

### Effort-comparison table

| item | doc | fleet | Δ reason |
|---|---|---|---|
| 0.1 | S | S | allow-list already exists [f01] |
| 0.2 | S | S (upper) | 7 flags, exit-2 convention [f01][f02] |
| 0.3 | S | S | one guard + encoding decision [f01] |
| 0.4 | S–M | **M** | test infra (temp configs, fork matrix) [f02] |
| 0.5 | M | M (step1 S–M) | F3 filter + bounded drain; A2 deferred [f03] |
| 0.6 | S | S | scalar rows; contract pre-declared [f02] |
| 1.1 | S–M | **M** | dual scorer path + 4-key cache packet [f04] |
| 1.2 | M | M ✓ | measurement-justified [f05] |
| 1.3 | M | M (L-lean) | loader relocation + apply refuse [f05] |
| 1.4 | M | M (top) ✓ | cheap given pairs; blast radius = cache/goldens [f06] |
| 2.1 | L | L ✓ | stitch S-first de-risks [f07] |
| 2.2 | M | M ✓ | linked-subset; seed-completeness gated on 1.3/2.3 [f08] |
| 2.3 | M | **M–L** | shape on non-hot path + impact exemption + opt-in decision [f08] |
| 3.1 | M–L | M–L ✓ | fold ~300 LOC; M under shared-walker ruling [f09] |
| 3.2 | M | M ✓ | digest reuse = lookup not matcher [f09] |
| 3.3 | M | M ✓ | [f10] |
| 3.4 | S | S ✓ | all inputs resident [f10] |
| 3.5 | M | M ✓ (a before b) | ordered sequences [f10][f07] |
| 4.1 | M–L | **M** | substrate present; descriptors replace node-refs [f11] |
| 4.2 | M | M ✓ | subprocess precedent [f11] |
| 4.3 | S | S ✓ | comments survive caches [f11] |
| 4.4 | S–M | S–M ✓ | id recipe settled [f11] |
| 5.1 | S–M | S–M ✓ (M end) | cache discipline [f12] |
| 5.2 | S | **S–M** | dead-class replace-semantics + glob boundary [f12][f14] |
| 5.3 | M | M ✓ | [f12] |
| 5.4 | M | M ✓ (M end) | templateMemberId + HoleCoverage pre-reqs [f12] |
| 6.1 | M | M ✓ | 4+2 edit sites [f13] |
| 6.2 | M | M ✓ | ruling-gated impact flip [f13] |
| 6.3 | S–M | **S** | pairs data exists; injectivity test [f13] |
| 6.4 | M | **S–M** (floor) | LLM does the real work [f13] |
| 7.1 | M–L | **L** native / **M** jscpd | looksLikePhp wall; guard sweep [f14] |
| 7.2 | S | S (a) / S–M (b) | [f14] |
| 8.1 | M | M ✓ (field matrix) | [f14] |
| 8.2 | M–L | **L-lean** | runAnalysis extraction + no LLM wire [f14] |
| 8.3 | M | M (infra+1 tool) / **L** full | buildSummary discards reports [f14] |
| bench | (S assumed) | **5–7 d spread** | [f15] |

---

## 3. The 80 GB memory story

The doc's §3.6 blowup is real, correctly sited, and **its three proposed fixes are all beaten by a fourth
the fleet found** [f03].

**The arithmetic (verified end-to-end).** At ~400k pairs/s/core and a measured **232.8 B per pair-record**
(including bucket slack), a worker materializing the candidate stream produces **≈5.6 GB/min** — the doc's
measured 4–7 GB/min to the digit. Full-resident baseline is fine and flat (**23.8 KB/block**: id string,
range, canonical, bag, structuralHash, size; unloadAst saves 9.4 KB/block; postings index adds only
0.3–0.6 GB even at 98k blocks). What killed /home/my is the **pair list** — `ClusterStage:207`
`iterator_to_array` (stillborn-lazy, never actually streamed since PR #81 [f03]§verdicts) over
**~320M materialized pairs ≈ 78+ GB** → death at Clusterer.php:131 [doc §3.6, confirmed]. Why /home/my is
3–4× denser than phlix (2,400–3,400 pairs/block vs 787): **family storms are superlinear** — a 59k-block
replica family measured 882 pairs/block vs 216 for a 16.5k control [f03]§arithmetic. The quadratic ceiling
is 4.84B pairs ≈ 1.13 PB — "can't hold it" is structural, not a tuning miss.

**The winner: F3 exact multiset upper-bound filter — LOSSLESS, 42–46×.** For every candidate pair the
inverted index already visits, keep a running exact sum `s = Σ min(count_a[g], count_b[g])` over the
df-passing shared grams, plus per-block **skipped mass** `skip[p]` (counts in df>cap grams — avg 31.8 %/23.3 %
of bag mass). Bound: `ubS = min(mass_min, s + min(skip_a, skip_b))`; kill the pair unless the bound can
reach J≥0.80 or C≥0.85∧ratio≥0.6. Sound because J and C are monotone-increasing in Σmin [f03]§f3.
Measured on the full 59k-block census: **keeps 2.17–2.35 % of pairs (42.5×/46.0× reduction) and loses
0 of 640k true edges**; at phlix level 83,873/3.57M, **0 of 70,948 real edges lost** (survivor precision
84.6 % — scoring still confirms every one); alone puts /home/my **inside the 8 GB done-when with ~5× margin**
(80 GB→~1.6 GB) and cuts the TED-dominated scoring pass by the same factor. Placement:
`Clusterer::generateCandidatePairs` inner loop (one isset-probe per posting touch) [f03]§f3-placement.
Secondary lossless lever: mass-ratio ≥0.6 floor = −65 % pairs, 0 loss (stacks). `min-shared-2` = −55 % but
**loses 2.9–4.4 % of edges ⇒ opt-in only, default OFF** (§6#3) [f03]§filters-a.

**The rejected alternatives and why** [f03]§options:
- **rarest-k:** K=8 on the 59k storm is **bigger** than the df-cap (3.94M vs 3.57M pairs — the cap already
  cuts top-grams; rarest-k keeps the long tail), loses edges (0.03 %), needs stable tie-breaks + cache-key +
  schema churn. The doc's cap-starvation worry is **edge-side**; F3's losslessness answers it properly.
- **MinHash+LSH as THE fix:** fine per-block cost (2.1 ms/7.4 KB), but the shipped 32-band×4-row params
  are **mis-tuned for a 0.80 threshold** (p(collide)@J=0.5 = 0.87 → 846 candidate pairs/block — a flood, not
  a filter); a family storm collides in ALL bands; and **containment-tier pairs (16.6 % of family-corpus
  edges) are structurally unserved** by set-minhash (C≥0.85 with J≲0.42 ⇒ LSH-sole-filter deletes ~98 % of
  them). Correct: a real containment-aware banding (DS-index/Choi) = research code — demote from the menu.
- **Naive streaming (doc option 1):** raw pair records over the socketpair ≈ 200 B × 322M ≈ **64 GB of IPC**
  — streaming pays off only AFTER pair-count reduction (F3's survivors, 2.2 %, ≈1.3 GB, stream free); the
  fused range-task variant (children enumerate+filter+score over COW postings, return edges) = the later
  structural win. `ExternalSort`'s spill route was priced and is **no longer needed given F3**.
- Doc-missed unwired modules: **BloomCandidateIndex AND ExternalSort** are also built/tested/unwired
  (ExternalSort's docblock literally describes this merge use) [f03]§inventory.

**Acceptance correction (replaces "98k corpus < 8GB"):** a **memory-bounded CI storm test** — synthetic
40-class × 40-near-clone-member corpus (~1.6M candidate pairs ≈ 370 MB un-filtered) run under
`php -d memory_limit=300M`: **filter-OFF fails (OOM = the teeth), filter-ON passes, edge set byte-identical
ON-vs-OFF ⇒ zero golden/cache/TUI churn by construction** [f03]§acceptance-storm. Plus a bounded-drain
fail-fast (CandidateBudgetException citing --candidate-filter/max_df) instead of silent 80 GB growth
[f03]§a-lite, and the `maxMemoryMb` post-run warning made **pre**-scoring [f03]§maxmemory.

---

## 4. Hard blockers & codified bugs

| id | what | evidence | carrier fix |
|---|---|---|---|
| **BUG-A** | Normalizer bare-return TypeError (`:321` guard, `:327` throw) — a bare `return;` in a single-statement `switch` case fatals the normalizer | reproduced live, exit 255 [f01]§0.3 | 0.3 guard + explicit encoding; **poisons real corpora via worker deaths** |
| **BUG-B** | `WorkerPool.php:191` `allowed_classes:false` ⇒ every Block-bearing row arrives **`__PHP_Incomplete_Class`**; `analyze src` = exit 255; **A/B-proven a second time by seat 12** (labels experiment) | [f01]§0.1 [f12]§5.1-parallel | 0.1 decode with `SerializedClassAllowList::blockCacheClasses()` (zero new classes) — **gates ANY cross-repo/multi-worker acceptance run** |
| **BUG-C** | all VALUE_REQUIRED numeric CLI flags arrive as uncast strings into strict `Config::__construct` → TypeError exit 255; **7 flags incl. `--max-clusters`; `--workers 0` included** | [f01]§0.2 [f02]§0.4 | 0.2 OverrideResolver cast + friendly exit 2 — gates `--max-hole-coverage`-style tuning, 8.2 entrypoint, every flag-test in 0.4 |
| **Hazard-1** | **Partial-block-list caching**: a mid-file throw after some blocks appended leaves the truncated list cached/counted (PreprocessStage:181–189) | [f01]§0.3-fix | **buffer-and-discard**: per-file row buffer; commit to $out/cache only at file end |
| **SOCKETPAIR LAW** | **anything crossing the worker wire must be scalar** — Block/LineRange/PhpParser nodes are the crash#1 class; derived data (idiom digests, live-var summaries, fragments, labels, sequences) is computed **in-worker as plain arrays/strings** (the idiom-row precedent [f09]§socketpair) or **master-side** where the data already lives (f07's fragments deviate from the doc's "worker emits" exactly for this reason [f07]§design-deviation) | f01/f02/f07/f09/f10/f11/f12 convergent | every design brief above obeys it; deserialization-side corollary = Bug-B allow-list; `Hole` node-ref retention (§4.1) resolved by descriptor scalars [f11] |
| Hazard-2 | `--no-cache` ≠ no disk AST cache; `cache_dir` relative by default ⇒ repo litter | [f02]§facts [f14]§cache-8.2 | 0.4 harness absolute temp configs; docs fix rides 0.2/0.6 |
| Hazard-3 | IndexStore configKey **omits `allowedKinds`** (toolFor has it) — `--kinds` toggles silently stale on warm caches | [f08]§risk6 | key fix lands FIRST in 2.3's PR; §6#7 schedule |
| Hazard-4 | dual cluster-id streams: `X…` (content-stable) vs `C…` (md5(root), root = **edge arrival order** ⇒ unstable in parallel) + block ids embed scan index | [f06]§id [f13]§4.4-claim [f14]§4.4-verify | 1.4 in-lane content-hash stopgap → 4.4 FindingId everywhere; **never key caches/baselines/MCP on ids** [f14]§8.1 [f15]§bench-design |
| Hazard-5 | silent-`''` label loss on stale caches after 5.1 (IndexStore PARSER_VERSION v5→v6 MANDATORY) | [f12]§5.1 | §6#7 coordinated bump schedule |

---

## 5. Design rulings — DECIDED

**(a) 1.1 = un-number the bag AND the TED labels together.** Bag-only is unsound: pairs crossing J≥0.80
enter the Jaccard tier where `labelOf`'s numbered `__V1`-style labels corrupt the edit score and DROP
pairs containment accepts today (the doc's own row-2 J=0.826 is the counterexample). Fixing
`NgramFingerprint` + `AptedDistance::labelOf:321–324` in lockstep makes every transition a strict
improvement (TED on un-numbered labels is monotone ≥ numbered). Row-3 **realnames** sidesteps the gate
entirely (J<0.80 lands in Containment) — kept as the follow-up mode for callee-identity precision
(the `toArray()` noise family) [f04]§tier-order. **DECIDED.**

**(b) 1.4 = greedy medoid-ball inside Clusterer, post-union-find, consuming existing `Cluster::$pairs`.**
Zero new scoring (edges already materialized in both modes); membership by within-cluster threshold score
(tier-composite — CoherenceAnalyzer's raw-Jaccard basis would mis-split containment pairs);
**average-linkage REJECTED** (requires all-pairs recomputation ≈ 4 h at phlix scale; containment is
directed and not symmetric-averagable). Reported cluster similarity corrected to the per-member-vs-medoid
score. `splitClusters` default ON; ClusterCache re-pin. [f06]§design. **DECIDED.**

**(c) THE SHARED-MINER ARBITRATION (orchestrator ruling, 2026-11-20).** 2.1 (statement-sequence runs) and
3.1/3.2/3.5 (expression folds, helper digests, callee sequences) are one mechanism at different granularities:
**ONE shared mining substrate = a single per-file walker in `PreprocessWorker` (the ONLY place with the live
AST before unloadAst, under both lazyAst modes, parallel by construction) + ONE `SequenceIndex`**.
**The 2.1 design owns it; 3.1/3.2/3.5 are consumers.** **NO new `Stage` enum case** — 6th case = ≈9-file
exhaustive-match sweep (TUI panes/timings/match arms, 3 foreign-owned files) AND the new stage would run
**serially in the pipeline master** = exactly the §3.6 13-min-to-death failure mode [f07]§arbitration
[f09]§placement. One `mined` row type (scalar payload, pre-declared by f02's `skipped` contract)
carries all flavors. `ProgressListener`'s 6 abstract methods are a **compile-time contract** — reuse
`onFilePreprocessed`/`onPairScored`, never add methods unilaterally [f09]§seams [f07]§miner.
Fold keys via **`AntiUnifier::stmtHash` extracted to shared `src/Util/StmtHash`** (position-aware;
SubtreeHasher is position-free; two formulas must not coexist) [f07]§hash-choice [f09]§blockers. **DECIDED.**

**(d) Idiom/subtree keys need a dedicated bottom-up fold — NOT a reuse of Normalizer modes.** The doc's
"exact ≈ strict, abstract ≈ default" fails mechanically: `CanonicalizingVisitor` is a **block-scoped**
state machine (`__V` numbering by first appearance in the ENCLOSING block ⇒ the same subtree in different
contexts digests differently), and `SubtreeHasher` hashes whole trees with **no position** (unlocatable
sites; per-node use quadratic). The fold computes **subtree-local** var-role renumbering (per-subtree
fresh counter, first-appearance-in-subtree order) + leaf literal policy; names/literals kept = "exact",
blanked = "abstract" [f09]§fold. **DECIDED.**

**(e) Cluster-ID stability = content-derived, sorted member-set keys.** `C…` ids rebuild from
**sorted per-member `structuralHash`/content hashes** (never list position — PreprocessStage:200 embeds it —
never union-find root — arrival-order in parallel, f06's stronger finding); roll-up/baseline/registry IDs
same discipline; **`BaselineStore::computeBlockHash` (file.start.end) is POSITIONAL — reusable pattern, NOT
reusable hash** [f08]§2.2-verdicts3 [f06]§id [f11]§4.4-verdicts [f14]§4.4-verify. **DECIDED.**

**(f) Pseudo-Clusters ride Clusterer's Phase-1 hash buckets — no new payload type.** Idiom/fragment/
helper-copy findings are emitted as `Cluster` objects whose members are synthetic `Block`s
(synthetic-`Stmt\Block` precedent probe-verified to survive normalizer/hasher/IPC via the existing php-parser
class allowance) — every existing reporter/consumer works unchanged; JSON fields prefer existing side-channels
(`architecturalFindings`, `patternTags`) over schema growth (f09's schema-bump dodge); `Report`'s readonly
promoted ctor means new payload lists use **trailing-defaulted params** (keeps ~28 constructing sites
compiling) [f09]§pseudo-cluster [f14]§8.1 [f07]§synthetic [f08]§report-param. **DECIDED.**

**(g) 1.2 v1 = config-only `max_ted_size` + `jaccard-unverified` tier spelled identically in BOTH
parallel tier vocabularies** (Clusterer MatchTier + PairScoreWorker const — the repo's dual-vocabulary is a
known drift trap; JSON carries tier verbatim) — the JSON-int route dodges BUG-C on unpatched master;
measured TED cost (161 s/1.2 GB per 4,506-node pair) at exactly two call sites makes this the whole fix;
stmt-hash LCS surrogate later. **1.3: `--apply`/RefactorPatchReporter must REFUSE script-kind clusters in v1**
(BlockAstLoader cannot relocate synthetic blocks under default lazyAst — one RuntimeException kills the run;
the doc's "refactor still works" is false as written) [f05]§1.2-design [f05]§loader-trap. **DECIDED.**

---

## 6. Owner-ruling list (fleet-flagged human decisions)

1. **`--min-impact` semantics + declarative floor exemption [f08][f13]:** two coupled sub-decisions —
   (i) impact in nodes vs lines (6.2 flip = CI behavior change against a documented-as-"≈ lines" flag;
   dual-quantity mitigation on the table); (ii) the **exact ServiceProviderInterface declaration cluster is
   deleted today by `min_cluster_impact`** (impact 9 < 20) ⇒ the doc's §6 "RateLimitState ≥90 % coverage"
   target is **unreachable** without a shape-cluster floor exemption or lines-impact — a headline benchmark
   row is owner-gated.
2. **`--class-shape` posture [f08]:** adding `class` to `ALL_KINDS` is NOT inert (allow-list semantics mean
   class blocks enter EVERY default run → goldens move, block counts jump, candidate mass grows pre-0.5).
   Options: default-on+accepted churn vs **opt-in `shape_kinds` list (f08's recommendation)**.
3. **`min-shared-2` default [f03]:** −55 % pairs but loses 2.9–4.4 % of true edges ⇒ fleet says opt-in,
   default OFF; confirm the aggressive tier ships as a flag not a default.
4. **`max_ted_size` default value [f05]:** f05 ships 800 (byte-identical goldens) with the
   `Block::$canonical` **IPC/cache payload-inflation** question attached: raising `max_block_size` also
   ships whole canonical ASTs over the wire and into disk caches (a memory contract, not a style choice —
   /home-my already OOMed). Options: (a) default 800 user-pays; (b) null canonical above the cap + type
   widening (`Block::$canonical` is NON-NULLABLE today) + consumer null-guards — "parse don't validate" fix
   [f05]§risk1. Fleet recommends deciding (b) deliberately or deferring it loudly, not inheriting it silently.
5. **Pinned-corpus snapshot for acceptance [f04][f08][f15]:** 1.1/4.1/2.2/2.1's "20/28→28/28" style
   done-whens need the owner-pinned Vps↔QS snapshot (phlix trees at the doc pins demonstrably lack it;
   /home/my is unpinned and moving). Fleet proposal: `corpus-pins.txt` (server 375f7992, hub ba1ae3b8,
   /home/my ad58b221c5 + 96 vendor HEADs **now**) + vendored 40-file twin subset + micro-fixture as the
   hermetic stand-ins; owner approves the archive recipe + the privacy gate on committing derived truth.
6. **Widen `FileScanner::looksLikePhp()` for 7.1? [f14]:** without it, `.js/.yaml/.tpl` never reach any
   layer regardless of excludes or kinds — this is a policy decision (scan-surface expansion) before the
   native tier is buildable. (Related cheap sub-decisions riding here: DefaultExcludes **wire-or-delete**;
   tpl-flip interim via `--config` is already available.)
7. **Schema/cache version-bump schedule as ONE coordinated change [f02][f04][f06][f08][f10][f12]:**
   `JsonSchemaSpec 1.1→1.2` (first field-adding landing owns the bump; additive rides MINOR — candidates in
   expected order: 0.6 summary keys, 2.2 rollups, 3.3 census, 4.3/4.4 twins+findingId, 5.1 scope, 6.1 audit,
   8.1/8.2 packs) **+ the Golden trio regen welded to each bump + `IndexStore PARSER_VERSION v5→v6` (5.1
   labels; 1.1 forces a parser-key change too) + `ClusterCache` v1→v2 (1.4 split / 1.1 edges) + the
   `allowedKinds` config-key fix (2.3) + `toolFor` cache-key lines (1.1/3.x)**. Fleet verdict: treat as one
   owner-scheduled train, not five lane-local decisions — each lane's brief must state its expected
   position in the queue.

**Secondary flags** (decidable by the orchestrator unless the owner preempts): jscpd v5 binary
supply-chain acceptance in CI [f14]; `--no-cache` semantics fix vs docs-only [f02];
`optionalBlocksMaxPerCluster` cap-3 raise vs whole-array-hole pathology at scale [f05][f11];
`llm_allow_private` SSRF opt-in wording [f14]; `script_min_size` guard against glue-script flood [f05];
`fragment_min_sites` tuning [f07]; whether `callee-set` becomes a SCORING tier or stays
report-only [f10][f07]; **MCP `blocked_on` facade policy** [f14].

---

## 7. Dependency / ordering graph

**Edge legend: `X → Y` = Y needs X landed (or the noted coupling).**

```
PHASE-0 LANE (f01/f02 ordering): 0.1 → 0.3 → {0.2 ∥ 0.6} → 0.4
  (0.1∥0.2 mutually independent, but both are pre-conditions of everything parallel/flagged;
   0.6 lands before 1.2 [f02]; 0.5 after 0.1 [f01]§ordering — same WorkerPool/serial-fallback region;
   0.4 lands LAST = the green net over all of it [f01])

CORE:        1.4 → 1.1          (both touch the Clusterer tier block; 1.1's new edges need 1.4's split
                                  discipline AND 1.4's ID regen is 1.1's stability precondition —
                                  "1.4 with/before 1.1" [f06][f04])
             1.1 → 1.2-acceptance   (qs/vps drifted pair; interim acceptance without it [f05])
             1.2 → 1.3            (share the gate; 1.3 inherits it [f05])
             0.2 ⊣ max_ted_size-flag leg (config-only leg ships first [f05])
             1.1 → {4.1, 2.1-full, 3.1-full}   (un-numbering = the substrate; 3.1's fold is
                                                numbering-independent but cache-key must not drift [f07][f09])
             0.5 → /home-my-scale runs         (every large-corpus acceptance test)

PHASE-2:     2.2(seed arm) → {1.3, 2.3}        (zero-block files need script+class kinds [f08])
             1.1 → 2.2 → 2.3                   (fingerprint → rollup → shape; 6.2 before 2.2
                                                ships its DEMOTED-NUMERATOR leg (DTO inflation) [f08])
             0.5(or shape-only path) → 2.3 default-on class kind  (scoring-cost wall [f08])
             shared-miner (§5c): 2.1 owns; 3.1/3.2/3.5 consume; fragments → {5.4 re-express, 6.3 fold}

PHASE-3:     3.1 → 3.2            (lookup index IS 3.1's substrate [f09])
             {6.3, 6.2} → 3.1-default-on landing  (rank flood: 268-site idiom rank-1 [f09])
             6.3 after 1.4        (subsume post-split [f06][f13])
             shared suppression rule: 2.1 ≡ 3.1 ≡ 6.3 ≡ 7.2c — implement ONCE [f07][f09][f14]

PHASE-4:     {1.1, 0.1} → 4.1     (no edge / no holes without them; descriptors scalar-safe [f11])
             4.3 → 4.4            (shared comment-parse pass [f11])
             4.4 early → {6.1 anchors, 8.1/8.3 cache keys} [f11][f14]
             4.2 last in P4       (flag sites; only subprocess item [f11])
             2.2 → 4.1-acceptance (rollup presentation [f11]; Vps↔QS pins → owner §6#5)

PHASE-5:     5.2(pattern-fix) early → any multi-root/rollup denominators [f12]
             5.1 → 5.3 ; 5.4 → 5.3-subtree-forms (37.3 %) [f12]
             {templateMemberId + HoleCoverage} → 5.4-headline [f12]
             3.2 → 5.3-full       (canonical probe) [f12]
             {0.1, 0.2, 0.5} → all cross-repo multi-worker acceptance [f12]§ordering

PHASE-6:     6.2 first → coupled with 3.1 landing [f13][f09]; 6.3 after 1.4 split [f06][f13];
             6.1 shell anytime, sections gated by the renderability map [f13]; 6.4 after 8.1 (or defer to 8.2) [f13]

PHASE-7:     7.2a = day-one config flip [f14]; 7.1-native → {1.1 landed (NgramFingerprint collision —
             hardest in seat-14 scope), §6#6 ruling, kind-guard sweep PR first} [f14]
             jscpd-interim → no engine dep (binary aside) — lands BEFORE the native decision [f14]

PHASE-8:     8.1 now-subset (no blocker) [f14];
             8.2 → {8.1, 0.2, 0.5, Command::runAnalysis extraction} [f14];
             8.3 → {report-retention/ReportIndex infra; find_clones now; per-tool gates
                    twins←2.2, census←3.1, reimpl←3.2} [f14]

BENCH:       (0.1 only for >8-file hermetic runs) ; audit-truth scripts/LLM-pass/micro-fixture =
             independent [f15]; full-corpus weekly = blocked on 0.5 regardless [f15]§risks.
```

**Critical path (fleet consensus):** Phase-0 trio → 0.5 → **1.4+1.1 → 6.2/6.3 → 1.2/1.3 → 2.2/2.3 →
shared miner (2.1→3.1→3.2/3.5) → 4.3→4.4→4.1 → 5.1/5.2/5.4 → 8.1→8.2** — the doc's roadmap
reflavored, not re-ordered: its step table survives verification [f06]§ordering; the load-bearing
changes are (i) 0.5 moved up to gate everything corpus-scale, (ii) 1.4 before 1.1, (iii) 6.2/6.3 coupled
into 3.1's landing, (iv) the miner treated as one lane not three items, (v) 4.4's FindingId pulled early
as the id spine for 6.1/8.x.

---

## 8. The concurrency map

### 8.1 Collision matrix (items × hot surfaces — built from every filing's §hot-files)

● = primary edit, ◐ = line/region shared (row-scoped coordination), ○ = read-only dependency.

| item | Command.php | Config/Loader/OverrideResolver | Normalizer/AstSerializer/labelOf | NgramFingerprint | Clusterer+PairScoreWorker twins | ClusterStage/cacheConfigKey | PreprocessStage/Worker/ScanningStage | Block/Cluster.php | Ranker/SafetyScorer/SignatureBuilder/BaselineStore | JsonReporter/SchemaSpec/Goldens | ReportStage | profiles/*.json | TUI (Stage/ViewState/PhpdupModel) | ci.yml |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 0.1 | | | | | | | ●(decode) | ○ | | | | | | |
| 0.2 | ◐(:232) | ● | | | | | | | | | | | | ○(red) |
| 0.3 | | | ● | | | | ◐(buffer) | | | | | | | |
| 0.4 | ○ | ◐ | ○ | | ○ | | ◐ | | | ◐ | | | | ●(rewrite) |
| 0.5 | ◐flag | ◐ | | ○ | ●(gen-loop) | ◐ | ○ | | | | | | ◐ | ○ |
| 0.6 | ◐ | | | | | | ●(rows) | | | ◐(bump) | ◐ | | ◐ | ○ |
| 1.1 | ◐ | ◐(mode) | ● | ● | ◐ | ◐(key) | ◐(toolFor) | | | ◐ | | | | |
| 1.2 | ◐ | ◐(ted) | ○ | | ●(gate×2+tier) | ◐(key) | | ◐(canonical) | | ◐ | | ○ | | |
| 1.3 | ◐ | ◐kinds | | | | | ◐ | ●(kinds/loader) | | | | ○ | | |
| 1.4 | | ◐(split) | | | ●(split) | ◐(save) | | ●(id/tight) | | ◐(regen) | ○ | | | |
| 2.1 | ◐ | ◐frag | ◐(1.1) | ◐ | ○ | ◐ | ●(walker) | ●(fields) | ○ | ◐ | ◐ | | ○ | |
| 2.2 | | ◐twin | | | | | ○ | | ○(demote) | ●(rollups) | ◐ | | | |
| 2.3 | | ◐shape | | | | | ◐ | ●(Block+keyfix) | ◐ | ●(bump) | ◐ | | | |
| 3.1 | ◐ | ◐idiom | ●(fold) | ○ | ○(buckets) | ●(fold-host) | ●(rows) | ◐ | ●(partition) | ◐ | ◐ | | | |
| 3.2 | | | | | | ◐ | ○ | | | ◐ | ◐ | | | |
| 3.3 | ◐ | ◐literals | | | | | ●(census) | | | ◐(bump) | ◐ | | | |
| 3.4 | | | | | | | ○ | ○(names) | | ◐ | ◐ | | | |
| 3.5 | ◐ | ◐seq | | | ◐(tier?) | ◐ | ●(in-walker) | ◐ | | ◐ | ◐ | | | |
| 4.1 | | ◐vocab | ○ | | | | | ◐(descriptors) | | ○(side-chan) | ●(analyzer) | ●(vocab) | | |
| 4.2 | ◐ | ◐flags | | | | | ◐(.git) | | | | ◐ | | | |
| 4.3 | | | | | | | ○ | | ○(hash) | ◐(twins) | ◐ | | | |
| 4.4 | ◐ | ◐list | | | | | ◐(id) | ◐(ids) | ○(store) | ◐ | ◐ | | | |
| 5.1 | ◐scope | ◐ | | | | ◐(salt) | ●(labels+v6) | ◐(fields) | ◐(ScopeBias) | ◐(scope) | ◐ | | | |
| 5.2 | ◐exclude | ◐(defaults) | | | | | ●(FileScanner) | ◐(vendored) | | ◐ | | | | |
| 5.3 | | | | | | | ○ | | ◐ | ◐ | ◐(advisor) | | | |
| 5.4 | ◐coverage | ◐ | | | | | | ●(tmplId) | ●(Sig+HoleCov) | ◐(evidence) | ◐ | | | |
| 6.1 | ◐(4) | | | | | | | | ○(sort) | ◐(audit) | ●(wiring) | | | |
| 6.2 | ◐score | | | | | | | | ●(Rank+Safe+Sort) | ◐ | ◐(order) | | | |
| 6.3 | | | | | | | | ◐(subsumes) | ◐(filter) | ◐(bump) | ●(folder) | | | |
| 6.4 | | | | | | | | | | ◐(themes) | ◐ | | | |
| 7.x | ◐ | ◐text | ○(1.1 — HARDEST) | ●(window reuse) | ◐(edges-in) | | ●(2nd gate) | ◐(kind text) | ◐(proxy) | ◐ | ◐(guards) | ●(lang json) | ◐(kind) | ●(binary) |
| 8.1 | | | | | | | | | | ◐(packs) | ●(builder) | | | |
| 8.2 | ●(runAnalysis) | ◐llm | | | | | | | | ◐(audit) | ◐ | | | |
| 8.3 | ◐mcp | ◐params | | | | | | | | ○ | ○ | | | ○ |
| bench | | | | | | | | | | ○(N/N−1) | | | | ◐(nightly) |

**Recurrent surfaces, ranked:** ① `Command.php` six-flag-sites (+parseCliOverrides + runAnalysis region)
② `Config/ConfigLoader/docs-pair` ③ **`Clusterer` AND `PairScoreWorker` twins — BOTH tier vocabularies**
④ `PreprocessStage/PreprocessWorker/ScanningStage` row contracts + keys ⑤ `Block.php`/`Cluster.php` field
appends (**four-way** per f07/f12) ⑥ `JsonReporter/JsonSchemaSpec/Goldens` bump train ⑦
`Ranker/SafetyScorer/SignatureBuilder/BaselineStore` ⑧ `profiles/*.json` ⑨ TUI
`Stage/ViewState/PhpdupModel` ⑩ cache-key set (`toolFor` :105–117, `configKey` :78–95, `cacheConfigKey`
:25–46, PARSER_VERSION consts) + `Normalizer`/`AstSerializer`(**src/Util/!**)/`labelOf` + `ci.yml`.
Path-drift warnings for implementer briefs: **AstSerializer is `src/Util/AstSerializer.php`** (doc says
src/Parsing/) [f04][f09]; **BaselineStore is `src/Reporting/`** [f14].

### 8.2 Parallel-safe lanes (spawn-ready groups; disjoint primaries within a wave)

**WAVE-1 (now):**
- **P0a** = 0.1+0.3 (WorkerPool decode, Normalizer guard, buffer-and-discard) — the wire lane.
- **P0b** = 0.2 (OverrideResolver/ConfigLoader + Command cast region).
- **P0d** = 0.5 F3 (Clusterer gen-loop, NEW NgramInvertedIndexTest, Config trio, docs, storm test).
- **BENCH** = micro-fixture 14 files + audit-truth extractors + LLM pass + corpus-pins.txt (f15) — bench/ + tests/Integration only.
- **CFG72** = 7.2(a) tpl-flip + DefaultExcludes wire-or-delete execution of §6#6 (Config::defaults lines + docs).

**WAVE-2 (after P0a/P0b):**
- **P0c** = 0.6+0.4 **one lane** (same ci.yml step, same row contract; owns schema-bump queue position #1, §6#7).
- **SCORE-1** = **1.4** (Clusterer split + id regen + Cluster.php) — Clusterer/ClusterStage region gets ONE integrator through wave 3 [f06].
- **P5A** = 5.2 pattern fixes (globToRegex, defaults single-source, merge semantics).
- **P4-early** = 4.4+4.3 **one lane** (shared comment pass; unblocked today; produces the id spine) [f11].
- **BENCH-2** = vendored 40-file subset + checks/ scripts.

**WAVE-3 (after SCORE-1; P0d landed):**
- **SCORE-2** = **1.1** (parser/cache-key train position; golden trio regen slot) ∥
  **SCRIPT** = 1.3 + 1.2's loader/extractor legs (BlockExtractor/BlockAstLoader/groupTopLevel — disjoint
  from the Clusterer tier lines; 1.2's **scorer gates serialize behind 1.1** — shared tier-string
  vocabularies) ∥ **ROLLUP** = 2.2 (post-1.1 [f08]) ∥ **TEXT-GUARD** = 7.x kind-guard sweep PR [f14].
- **RANK** = 6.2 (Ranker/SafetyScorer/ClusterSort trio — coordinate ScopeBias contract with 5.1 only) ∥
  **PLACE-EV** = 5.4 (SignatureBuilder/HoleCoverage/templateMemberId — Report/Refactor side) ∥
  **LABELS** = 5.1 (ScanningStage/IndexStore — owns PARSER v6) ∥ **SHAPES** = 2.3 opt-in half (post
  allowedKinds-key fix).
- **MINE** = shared-substrate lane (2.1 → 3.1 → 3.2/3.5, strictly serial internally, §5c) — file-disjoint
  from P4-late except ReportStage ◐ + flag train; starts when 1.1's seam is visible.
- **P4-late** = 4.1 (needs 1.1+0.1) ∥ 4.2 (last flag train).

**WAVE-5+:** 7.1-native vs jscpd-adapter (after 1.1 + owner §6#6) ∥ 8.2 audit (runAnalysis extraction
scheduled with the last Command hot-spot use) → 8.3 MCP ∥ 6.1 sections as producers land ∥ 2.3
default-on half (only after 0.5 + owner §6#2) ∥ 5.3 (needs 5.1; soft 3.2) ∥ 8.1 packs.

### 8.3 Must-serialize (with reason)

| chain | why |
|---|---|
| 0.1 → 0.5 → any >8-file test | same WorkerPool/serial-fallback region; fork-path premise [f01][f03] |
| 1.4 → 1.1 → 1.2-scorer-gates | shared Clusterer tier block + dual tier vocabularies + cache train [f06][f04][f05] |
| {2.1, 3.1, 3.2, 3.5} internally | ONE walker/index/row contract (§5c); 2.1↔3.1 collision admitted by both filings [f07][f09] |
| 6.2 ↔ 5.1 | the ns-penalty LINES are literally the same code (Ranker:94/SafetyScorer:62) — one editor per step [f13][f12] |
| 0.6 → 1.2; {1.1,1.4,2.3-on,3.1,6.x,7.x} golden queue | counters before retained-big-blocks; each regen disclosed + batched; default-off items prove byte-identity [f02][f05][f08] |
| 4.3 → 4.4 → 4.1 → 4.2 | shared comment pass; id spine; substrate; flag train [f11] |
| 5.1 → 5.3; {templateMemberId, HoleCoverage} → 5.4 | labels; fields [f12] |
| schema-bump train / PARSER-version train | one bump owner at a time; IndexStore v6 + ClusterCache v2 + allowedKinds fix land as ONE coordinated change (§6#7) [f04][f08][f12] |
| ci.yml; Command.php flag sites; ReportStage; Block/Cluster fields | single owner per step / one CLI-threading commit per wave / P6 one writer / field-batch per lane-visit [f02][f05][f13][f07] |

### 8.4 Re-run rule per lane

- **P0a/P0b/P0c:** Unit+Integration; **goldens untouched** (0.3 only re-encodes previously-crashing files —
  regen to *prove* no-op; 0.6 = schema bump #1 owner + trio regen once, disclosed).
- **0.5/F3:** storm test requires edge set **byte-identical** ON-vs-OFF ⇒ goldens/cache untouched by design; no bump.
- **1.4/1.1:** ClusterCache v1→v2 (+1.1 parser-key) ⇒ **full invalidation + trio regen, every moved golden
  disclosed**; 1.1 NO schema bump [f04]§caches.
- **1.2/1.3:** default-off ⇒ assert **byte-identical goldens**; `max_ted_size` joins cacheConfigKey; kinds
  only move goldens when opted-in [f05].
- **2.2/2.3:** one bump slot + 3-golden regen; **allowedKinds key fix BEFORE any warm-cache shape test** [f08].
- **MINE (3.x):** three cache keys must all carry the new knobs (toolFor/configKey/cacheConfigKey) [f09]§§6;
  default-off ⇒ new case file, not a re-pin [f07].
- **4.x:** bump ride only (findingId/twins fields); opt-in features ⇒ zero churn [f11].
- **5.1:** v6 + salt + trio regen + **serial-vs-parallel byte-equal JSON test** (the BUG-B net) [f12].
  5.2/5.3: key review only. 5.4: evidence fields ride the bump slot [f12].
- **6.x:** impact-in-lines ⇒ **re-validate baselines + ci gates** (owner #1); subsumption count changes
  batch into the wave's trio regen [f13].
- **7.x:** pre-computed edges ⇒ cacheConfigKey + text-keyed parser; jscpd version-pinned; binaries out of
  goldens [f14].
- **8.x:** packs/audit ride the bump slot; LlmPackCache = separate content-hash key space (prompt version
  in key); prefer file output over JSON growth [f14]§8.2.
- **bench:** scorer reader accepts schema N and N−1 so bench history survives bumps [f15].

---

## Appendix — conflicts adjudicated (who won, why)

1. **Vps↔QS §3.3 reproducibility — f04 vs f08:** f08 **wins on the measurement** (J=0.613/C=0.840
   reproduces *verbatim* on `/home/my` incl. sizeRatio 0.826 — the numbers are real); f04 **wins on the
   operative conclusion** (the doc §2.1 attributes the family to the pinned phlix trees where it is absent,
   and /home/my is unpinned) ⇒ unified verdict: measurements true, corpus label wrong, acceptance
   owner-pinned (§6#5); synthetic stand-ins per both filings.
2. **Miner placement — f07 vs f09:** f09 (worker-side walker + Phase-1.5 fold, **no Stage case**) **wins
   the substrate** under the §5c arbitration; f07's master-side stitching half survives as the *consumer*
   (post-Cluster stitch over existing clusters) — complementary granularities of one mechanism, which was
   the arbitration's point [f07]§arbitration [f09]§placement.
3. **Fragments/script carriers — f05 vs f07:** both ride synthetic `Stmt\Block`; f07's master-side route
   avoids loader relocation for stitching, while the doc's "emit in worker" idea is dead (Socketpair Law);
   1.3's `groupTopLevel` becomes the shared helper (§5c/§7) — decided so nobody mints three wrappers [f05][f07].
4. **Class-shape exactness — f08 internal:** exact shape-equality refuted by f08's own 3-shell probe →
   overlap ≥0.7 (shapeCore); recorded so exact-hash dies once [f08]§shape-design.
5. **Benchmark freshness — f11(4.2) vs f15:** no conflict — 4.2 blame runs at scan-time; f15's rule
   (never re-check truth vs post-scan HEADs; strip W6 stamps) governs the bench corpus at pinned commits [f15]§adapters.
6. **BaselineStore as the ID precedent — doc/f08 vs f11:** f11 wins — sorted content-set **pattern** yes,
   positional file.start.end **hash** no; §5e codifies content-only [f11]§4.4 [f08]§2.2.
7. **Evidence-pack cache keys — f14 vs f11:** consistent once merged: packs key on the 4.4 FindingId
   derivation, never on `Cluster::$id` (f14's own warning) [f14]§8.1 [f11]§4.4.
