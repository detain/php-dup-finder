# phpdup — Code Review Findings

**Date:** 2026-06-30
**Scope:** Full `src/` tree — 146 PHP files, ~18,800 LOC.
**Method:** Five parallel deep-reads of the subsystems (pipeline/parallelism, parsing/normalization, clustering/similarity, refactor/reporting, CLI/TUI/server/infra), plus cross-cutting scans and empirical verification of the highest-value claims.
**Baseline:** `vendor/bin/phpunit --testsuite Unit` → **571 tests / 1523 assertions, all green** (26 MB, 2.5 s). The suite passing is *not* evidence of correctness for the items below — several inert features are green-tested (see C1, C3).

Each finding has an ID, a severity, exact `file:line` references, and a concrete fix. Items tagged **[verified]** were reproduced/confirmed during this review; the rest are high-confidence reads of the source.

---

## Executive summary

The codebase is well-structured, statically-analysed (Psalm/PHPStan level 6), and broadly idiomatic PHP 8.1+. The problems cluster into five themes:

1. **Inert "headline" features that are green-tested but do nothing** — `--ted-weights=semantic` (C1), `match`↔`switch` canonicalization (C3), and `--debug-log` (C5) all validate, pass tests, and silently no-op. These are the most damaging because users *believe* they are getting a capability they aren't.
2. **Two real security holes in the network-facing surfaces** — the self-updater has no signature verification (S1) and the `serve` command reads arbitrary local paths unauthenticated (S2).
3. **Silently-wrong similarity scores under load** — an `spl_object_id` cache that aliases after GC (C2) and an APTED early-abort that poisons the DP matrix (P-level correctness).
4. **A large band of dead/unwired infrastructure** — five fully-built, tested-but-never-called classes (`TokenCache`, `CanonicalNodePool`, `HoleMap`, `CompactNgramBag`, `SimilarityScore`) plus `WorkerPool::run()`.
5. **Generated artifacts that don't run/compile** — broken HTML syntax highlighting, generated PHPUnit tests that fatal, and refactor signatures that are not valid PHP on the 8.1 floor.

**Fix these first:** C1, C2, C3, S1, S2.

| Severity | Count |
|---|---|
| Critical | 5 |
| High | 14 |
| Medium | 17 |
| Low | 13 |

---

## 1. Critical correctness bugs

### C1 — `--ted-weights=semantic` is completely inert; every node costs 1.0 **[verified]**
**`src/Similarity/EditCostModel.php:52-102`**, consumed at `src/Similarity/AptedDistance.php:329-334`.
`cost()` compares labels against bare PHP-Parser names (`'MethodCall'`, `'If_'`, `'Int_'`), but the labels that actually arrive come from `AstSerializer::shortType()`, which emits the **prefixed** form (`Expr_MethodCall`, `Stmt_If_`, `Scalar_Int_`). Verified empirically:

```
Expr_MethodCall => 1.00   MethodCall => 2.00
Stmt_If_        => 1.00   If_        => 1.50
Scalar_Int_     => 1.00   Int_       => 0.50
```

So the "semantic" cost model is byte-for-byte identical to "default" — the whole feature does nothing. It's masked because `EditCostModelTest` calls `cost('MethodCall')` with the *bare* string (passes), and `testWeightedAptedDistanceProducesDifferentScoresThanDefault` only asserts `assertLessThanOrEqual` (trivially true when scores are equal).
**Fix:** Strip the `Expr_`/`Stmt_`/`Scalar_` prefix in `cost()` (e.g. compare the suffix after the last `_`), or canonicalize the label first. Then harden the test to assert the two models produce *strictly different* AptedDistance similarity, and add a direct `cost('Expr_MethodCall')` assertion.

### C2 — APTED `flatten` cache keyed on `spl_object_id` aliases after GC → silently wrong scores + unbounded leak
**`src/Similarity/AptedDistance.php:299-308`.**
`cachedFlatten()` memoizes on `spl_object_id($node)` in a process-lifetime `static` array that is never cleared. PHP reuses object ids after GC. In a long run a `Block`'s canonical AST is freed (`unloadAst()`), its id is reused by a structurally-different node, and `cachedFlatten` returns the **stale flatten of the dead tree** → wrong tree-edit-distance → wrong cluster. It is also a straight memory leak: every node ever compared is retained forever.
**Fix:** Don't key a persistent cache on `spl_object_id`. Key on a content hash (the block already carries `structuralHash`/`rangeHash`), or scope the cache per-`similarity()` call, or simply remove it (flatten is cheap relative to the DP).

### C3 — `canonicalizeMatchAsSwitch` builds a `$cases` array and throws it away; `match`/`switch` never cluster **[verified]**
**`src/Normalization/Normalizer.php:213-257`.**
The method constructs `Stmt\Case_` nodes (lines 218-235) and **never assigns them anywhere** — `$cases` is dead. The only surviving mutation OR-chains multi-cond arms. The node stays an `Expr\Match_`, so `AstSerializer` emits `Expr_Match_(…)` while a real switch emits `Stmt_Switch_(…)` — they can never collide. A documented normalization (CLAUDE.md "Match_ ↔ Switch_") silently does nothing, and CPU is wasted building the dead nodes on every match node.
**Fix:** Normalize *both* `Match_` and `Switch_` to a shared synthetic shape (e.g. a `FuncCall` to `__MATCH__` carrying arm conds/bodies) so they serialize identically; delete the dead `$cases` construction.

### C4 — HtmlReporter `highlightPhp()` emits broken HTML for any code containing a keyword in a comment/string
**`src/Reporting/HtmlReporter.php:271-284`.**
Regex passes run sequentially over the same string. The comment/string pass emits `<span class="c">…</span>` first; the later keyword pass then runs `\b(class|new|string|…)\b` over the *entire* string including the emitted markup, rewriting the word `class` inside the `class="c"` attribute and keywords inside comments. Concretely `// new class` →`<span <span class="k">class</span>="c">…` — invalid DOM. Not XSS (input is `htmlspecialchars`-escaped first), but every duplicate block whose source has a comment/string with a keyword renders corrupted.
**Fix:** Single-pass `preg_replace_callback` with a combined alternation that classifies each token exactly once, or negative-lookahead to avoid matching inside `<span …>`.

### C5 — `--debug-log` is a validated no-op; the value is never read **[verified]**
**`src/Cli/Command.php:82,168` · `src/Cli/Config.php:135` · `src/Cli/ConfigLoader.php:96,242,282,524`.**
`--debug-log=FILE` is declared, threaded into `$overrides`, validated, and stored on `Config::$debugLog` — but a whole-tree grep finds **no reader** of `$config->debugLog`. The `PipelineState::$debugMessages` ring buffer feeds only the TUI debug pane, never a file. So `phpdup analyze … --debug-log=out.log` exits 0 having written nothing. (Compounded by C5b below.)
**Fix:** Implement the sink (append each `PipelineState` debug message to the file from a `DebugLogger`), or remove the flag + config key + validation until it exists. Don't ship a green no-op.

---

## 2. Security

### S1 — Self-update has no signature verification; checksum shares the phar's trust channel
**`src/Cli/UpdateCommand.php:180-238` (esp. 203, 225-238).**
`update` downloads `phpdup.phar` and `phpdup.phar.sha256` from the *same* GitHub release, then checks the phar against that downloaded checksum and replaces the running binary. This protects against transmission corruption only — anyone who can publish/MITM the release controls both files, so the SHA-256 adds no integrity. There is no GPG/minisign/cosign signature and no pinned key. Worse, the checksum is fetched via `@file_get_contents($checksumUrl)` (line 203) with **no SSL stream context** (unlike `downloadFile()` which sets `verify_peer=>true`), so it leans on `php.ini` defaults. Net: "download and execute arbitrary code" with weak integrity.
**Fix:** Sign releases (minisign/cosign), embed the public key in the phar, verify a detached signature over the phar before install, and treat missing/invalid signatures as a hard failure (require `--allow-unsigned` to override). Add an explicit `verify_peer=>true` SSL context to the checksum fetch.

### S2 — `serve` analyzes arbitrary client-supplied paths with no sandbox → unauthenticated local file disclosure
**`src/Server/Application.php:120-145` · `src/Cli/ServeCommand.php:69-114`.**
`runAnalyze()` passes request-body `paths[]` straight into `Config::defaults($paths)` → `ScanningStage`, which scans them with no allow-list root or `realpath` containment (`FileScanner` only checks `is_dir`). The JSON report echoes file paths and duplicated source structure. With `--bind-public` (which disables the loopback guard and ships with **no auth**, per its own header comment), a remote client can `POST {"paths":["/etc","/home"]}` and read back source structure from the host.
**Fix:** Constrain scanned paths to a `realpath`-canonicalized `--serve-root`; reject absolute paths and `..`. Require a bearer token whenever `--bind-public` is set, and refuse to start a public bind without one.

### S3 — ML sidecar SSRF guard is shallow (blocks only `0.0.0.0`)
**`src/Ml/MlClient.php:111-126`** (mirrored in `MlPairClient`).
`isAllowedUrl()` rejects only non-http(s) schemes, empty host, and the literal `0.0.0.0`. It does not block `127.0.0.1`, `localhost`, `169.254.169.254` (cloud metadata), RFC-1918 ranges, IPv6 `::1`, or DNS-rebind hostnames. `--ml-pair-url` is config-supplied and POSTed feature vectors, so a coerced config can reach internal services. Severity Medium-High (operator-supplied URL, but defense-in-depth matters in CI).
**Fix:** Resolve the host and reject private/loopback/link-local/reserved IPs (`FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`), plus an explicit deny-list for `localhost`/`::1`/`169.254.0.0/16`.

### S4 — ML clients accept any HTTP body as a score (no status-code check)
**`src/Ml/MlPairClient.php:182-223` · `src/Ml/MlClient.php:135-178`.**
`postJson()` returns `curl_exec()`'s body whenever it's a string, with no `CURLINFO_HTTP_CODE` check. A `500 {"error":…}` or a proxy error page is handed to `json_decode`; if it happens to contain a numeric `similarity`, a garbage score forms a clustering edge. The stream fallback (`ignore_errors=>true`) is worse — even 4xx/5xx bodies return as success, defeating the documented fail-graceful contract.
**Fix:** Check `curl_getinfo($ch, CURLINFO_HTTP_CODE)` (and `$http_response_header` for the stream path); return `null` unless 2xx.

### S5 — `WorkerPool` IPC `unserialize()` omits `allowed_classes` **[verified]**
**`src/Parallel/WorkerPool.php:127, 246`.**
These two `@unserialize($blob)` calls default to `allowed_classes => true` (any class). The data originates from the project's own forked children over a socketpair (trusted IPC), so practical risk is low — but it diverges from the project's own carefully-hardened pattern (`SerializedClassAllowList` is used everywhere else) and SAST tools will flag it. Note: the **disk caches** (`IndexStore`, `ClusterCache`, `AstCache`) are correctly hardened — verified they pass explicit allow-lists and reject `__PHP_Incomplete_Class`. The outlier is `TokenCache.php:55`, which uses `allowed_classes => true` (moot only because `TokenCache` is unwired — see D1).
**Fix:** Pass `['allowed_classes' => SerializedClassAllowList::blockCacheClasses()]` (or a worker-specific list) in `WorkerPool` and `TokenCache` for consistency.

### S6 — CSV reporter is vulnerable to formula injection
**`src/Reporting/CsvReporter.php:83-89`.**
`escape()` does RFC-4180 quoting but doesn't neutralize a leading `=`, `+`, `-`, or `@` — a member/function name or signature beginning with one is interpreted as a formula by Excel/Sheets/LibreOffice. Member names come from analyzed source and can be attacker-influenced.
**Fix:** Prefix cells starting with `=+-@\t\r` with a `'` guard before quoting.

### S7 — `Pager::send()` runs `$PAGER` through `/bin/sh -c`
**`src/Cli/Pager.php:51-76`.** Operator-controlled, not remote, but `PAGER="less; rm -rf ~"` executes the trailing command. **Fix:** pass an argv array to `proc_open()` instead of a shell string.

### S8 — `.diff`/`.patch`/`.tests.php` filenames use raw `$cluster->id` (path-traversal-shaped)
**`src/Reporting/DiffReporter.php:29` · `RefactorPatchReporter.php:40` · `RefactorTestReporter.php:32`.** Ids are internally generated (safe today), but a `/` or `..` would escape the output dir. **Fix:** `basename()`/slug the id before using it as a filename. (Same defensive note for `ReportStage::writeClusterIdCache`.)

---

## 3. Performance & scalability

### P1 — APTED early-abort leaves a partially-filled `treedist` matrix that later key-roots read as 0
**`src/Similarity/AptedDistance.php:121-140, 188-222`.**
`forestDp()` mutates `$treedist` in place but bails its row loop with `return $rowMin` (≈214) before filling remaining rows, leaving cells at their `array_fill(…, 0)` default. The Zhang-Shasha recurrence at ≈207 reads those cells for *later* key-roots; a stale-zero produces a TED that's too small and a similarity that's too high. The abort is only sound when `ted()` returns immediately, but an inner `return $rowMin` that is `<= budget` from an incomplete matrix is the danger.
**Fix:** Only early-abort at the `ted()` level (return `budget+1` and stop); never leave a key-root's own `treedist` cells unwritten. Add a regression test with three sibling subtrees where the middle pair aborts.

### P2 — Within-component re-scoring is O(k²), iterating pairs that were never scored
**`src/Clustering/Clusterer.php:191-201`.**
For each non-exact component, a nested `for` over all member pairs (O(k²)) finds the min edge similarity, but most pairs have no materialized edge (`isset($edgeMap[$key])` false) and contribute nothing — pure waste, quadratic in a "hairball" component. It also over-states coherence (min over only direct edges, ignoring transitively-connected members).
**Fix:** Accumulate a running per-root min while consuming `$edges` during `union()` — O(edges) instead of O(Σk²).

### P3 — `computeRangeHash` re-reads the whole source file once **per extracted block**, and the result is never consumed
**`src/Extraction/BlockExtractor.php:148-155.`**
`@file($this->file, …)` runs once per block; a file with N methods reads+splits the whole file N times during one `extract()`. Verified: nothing reads `Block::$rangeHash` / `BlockHeader::$rangeHash` outside the producers — it's expensive dead work feeding an unimplemented "incremental reuse" path.
**Fix:** Drop `computeRangeHash` (it's unused), or memoize the `file()` result once per `extract()` if a future feature needs it.

### P4 — Per-node subtree node-count spins up a fresh `NodeTraverser` for every node (≈O(n²) on nested code)
**`src/Extraction/BlockExtractor.php:182-192`** and the duplicate `src/Util/AstSerializer.php:31-36,80-95`.
`BlockVisitor::nodeCount()` fully re-walks a subtree for every candidate node; nested blocks re-walk overlapping subtrees. There are **two** independent node-counters (SSOT violation).
**Fix:** Compute subtree sizes in one bottom-up pass (propagate child counts up a stack in `leaveNode`); consolidate on a single counter.

### P5 — `BloomCandidateIndex::candidatesFor()` is O(n²·m) — strictly worse than the inverted index it claims to replace
**`src/Index/BloomCandidateIndex.php:61-75`.**
It compares every block against every other via `BloomFilter::overlap()` (word-by-word popcount). For the large corpora that motivate Bloom filters, this is the *slowest* path, not the fastest the docblock advertises.
**Fix:** Band/LSH the Bloom filters so lookup isn't all-pairs, or restrict the docblock claim to small corpora. (Currently moot — also unwired; see D1.)

### P6 — Candidate-pair generation materializes the full pair list + a corpus-wide `$seen` map in RAM
**`src/Clustering/Clusterer.php:104-124`.**
`generateCandidatePairs()` returns a fully-materialized `$pairs` array plus a `$seen` dedup map for the whole corpus; on dense near-duplicate corpora both are super-linear and held simultaneously. The `ExternalSort` streaming class exists for exactly this but the serial path bypasses it.
**Fix:** Make it a generator (`yield` each pair), dedup via canonical ordering + sort rather than a corpus-wide `$seen`, and let the scorer consume lazily.

### P7 — `ShapeletSketch::popcount` shells out to GMP (`sprintf` + base-10 parse) on a "few ALU ops" hot path
**`src/Fingerprint/ShapeletSketch.php:81-86`.** Per call it does `gmp_popcount(gmp_init(sprintf('%u', $x), 10))`, invoked twice per `overlap()` — orders of magnitude over the intended bit-trick, and hard-depends on ext-gmp with no fallback. **Fix:** pure-int SWAR popcount; GMP only as fallback.

### P8 — `detectCpuCount()` always `shell_exec('nproc')` even after cheaper sources succeed
**`src/Parallel/WorkerPool.php:48-60`.** The `$candidates` array is built eagerly, so `nproc` runs even when `PHPDUP_WORKERS`/`/proc/cpuinfo` already gave a value — and it's called per-stage on every run. **Fix:** short-circuit lazily; cache in a static.

### P9 — `JaccardSimilarity` / `ContainmentSimilarity` build a throwaway merged array for the key union
**`src/Similarity/JaccardSimilarity.php:29` · `ContainmentSimilarity.php:34`.** `$a + $b` allocates a full merged array only to call `array_keys()`, in the hot pair-scoring loop. **Fix:** iterate `$a` then keys of `$b` not in `$a`.

### P10 — `ScanningStage` accumulates every path then `sort()`s in memory
**`src/Pipeline/Stages/ScanningStage.php:44-73`.** `FileScanner::scan()` is a lazy generator, but the stage buffers all paths and sorts at once — a memory ceiling on huge trees. (Sort is needed for determinism; the note is the unbounded buffer.) Also `scannedFiles` is set equal to `totalFiles` every tick, so the TUI shows 100% throughout scanning.

---

## 4. Memory & resource leaks

### M1 — `WorkerPool::run()` (non-streaming) leaks temp files on any error path
**`src/Parallel/WorkerPool.php:79-139`.** Children write results to `tempnam()` files unlinked only in the read loop; if the parent throws (≈123/129/132) or a child is OOM-killed between `tempnam` and write, the temp files leak — there is no `finally`. (Confirmed `run()` has **no production callers** — only `WorkerPoolTest` uses it; see D2.) **Fix:** wrap temp-file lifecycle in `try/finally`, or delete the method.

### M2 — Cancel mid-run can orphan worker children (zombies) + leave sockets open
**`src/Parallel/WorkerPool.php:209-274` + `src/Pipeline/Pipeline.php:48-67`.** `runStreaming`'s cleanup `finally` runs deterministically only if the generator is fully advanced or explicitly closed. On `^C` mid-clustering the TUI/pipeline abandons the generator, so children blocked on IPC are not `pcntl_waitpid`'d until GC. **Fix:** on cancel, `posix_kill($pid, SIGTERM)` children then reap, and call `$gen->return()` so `finally` runs.

### M3 — `PreprocessStage` holds two full copies of every block until streaming completes
**`src/Pipeline/Stages/PreprocessStage.php:152-205`.** Each block is stored in both `$blocks[]` and `$perFileBlocks[$file][]` (for per-file cache save), roughly doubling peak block memory during the most memory-intensive stage. `$perFileBlocks` is freed only after all saves. **Fix:** save to cache incrementally as each file's blocks complete (workers already group per-file) rather than buffering the whole grouping.

### M4 — `ClusterStage` rebuilds a second full edge list (`$pureEdges`) that's already filtered
**`src/Pipeline/Stages/ClusterStage.php:349-355`.** `$pureEdges` re-filters `__progress` rows that were already excluded when consumed from the stream, doubling peak edge memory for no benefit. **Fix:** pass `$edges` directly to `cluster()`.

### M5 — `Server/JobQueue` grows unbounded (memory-exhaustion DoS)
**`src/Server/JobQueue.php:22,35-45` + `src/Server/Application.php:80-101`.** `$jobs` only ever appends; each `POST /jobs` stores the full request payload **and** the full decoded analysis result forever. A client looping `POST /jobs` grows RSS until OOM. **Fix:** cap stored jobs (ring buffer / LRU), TTL-expire completed jobs, and don't retain the full report body indefinitely.

---

## 5. Dead, unwired & incomplete code

### D1 — Five fully-built, tested-but-never-called classes **[verified]**
Verified zero production callers (excluding self + tests):

| Class | File | Implied capability that never runs |
|---|---|---|
| `TokenCache` | `src/Parsing/TokenCache.php` | "skip lex/parse on revisit" (uses unsafe `allowed_classes=>true`) |
| `CanonicalNodePool` | `src/Util/CanonicalNodePool.php` | leaf-node memory dedup via `intern()` |
| `HoleMap` | `src/Normalization/HoleMap.php` | (`Block::$holeMap` is `[]`, never read/written) |
| `CompactNgramBag` | `src/Fingerprint/CompactNgramBag.php` | low-memory 32-bit n-gram bags |
| `SimilarityScore` | `src/Similarity/SimilarityScore.php` | `combined()`/`confidence()` (clusterer inlines `min()` instead) |

These carry tests, docs, and reviewer attention while implying optimizations that don't run.
**Fix:** wire each in behind a flag, or delete it + its tests. If kept: `CompactNgramBag` folds 64-bit hashes to 32 bits (`hi ^ lo`) — collision rate is materially higher than the "≪1%" claim on large corpora; store a 64-bit key instead.

### D2 — `WorkerPool::run()` is test-only dead code **[verified]**
**`src/Parallel/WorkerPool.php:79`.** Only `tests/Unit/Parallel/WorkerPoolTest.php` calls it; all three pipeline stages use `runStreaming`. **Fix:** delete it (and its test), or document why the non-streaming path is retained.

### D3 — `RefactorTestReporter` generates tests that fatal for any cluster with a hole
**`src/Reporting/RefactorTestReporter.php:67-79`.** `testAbstractionMatchesEachMember()` declares **zero parameters** but `casesProvider()` returns rows with one element per hole; PHPUnit passes provider elements as arguments → `ArgumentCountError` before reaching `markTestIncomplete()`. The generated skeleton doesn't run.
**Fix:** generate the parameter list from `$cluster->holes` (e.g. `mixed $suggestedName`).

### D4 — Refactor signatures can be invalid PHP on the 8.1 floor
**`src/Refactor/SignatureBuilder.php:34-41` + `src/Refactor/ParameterSynthesizer.php:103-125`.** Two ways: (a) an all-null hole yields the literal `null` type → `function f(null $x)`, which is a parse error before PHP 8.2 (project floor is 8.1); (b) `displayType()` can emit `class-string`, a Psalm/PHPStan pseudo-type that is a **fatal parse error** as a real PHP type. These flow into `.patch` files and copy-paste UI.
**Fix:** map non-runtime types in `displayType()` — `class-string`→`string`, bare `null`→`mixed`/drop — and gate `null`-only on the 8.1 floor; prefer a docblock `@param` for pseudo-types.

### D5 — `RefactorPatchReporter` patch body is a `// TODO: implement` stub
**`src/Reporting/RefactorPatchReporter.php:14,88`.** The "patch" emits the abstraction signature plus a TODO body — it doesn't extract the duplicated logic. This is the gap behind the missing "apply" feature (see F1).

### D6 — `IrLifter` ignores compound assignments and inc/dec → equivalent code lifts to different IR
**`src/Ir/IrLifter.php:170-246`.** Only plain `Expr\Assign` is recognized; `AssignOp\*` (`+=`, `.=`, `??=`) and `PreInc`/`PostInc`/`PreDec`/`PostDec` fall through to a generic `CallIr`, so `$x .= $y` and `$x = $x . $y` lift to *different* IR — defeating the IR tier's purpose. **Fix:** handle `AssignOp` and inc/dec as `AssignIr`/mutation nodes.

### D7 — Three `PatternRecognizer` detectors lack the `$m->ast === null` guard their siblings have
**`src/Refactor/PatternRecognizer.php`** — `isValidationChain` (102-124), `isSqlBuilder` (126-152), `isStateMachine` (154-164) call `NodeFinder::findInstanceOf([$m->ast], …)` without guarding null. ASTs are routinely unloaded (`Block::unloadAst()`); `[null]` passed to `NodeFinder` throws a `TypeError`, so these can fatal instead of returning false like the other ~8 detectors. **Fix:** add `if ($m->ast === null) continue;` to all three.

### D8 — `BlockVisitor` never resets `$namespace` on `leaveNode` **[verified]**
**`src/Extraction/BlockExtractor.php:89-93, 157-163`.** `enterNode` sets the namespace on `Stmt\Namespace_` but `leaveNode` only pops the class stack. For bracketed multi-namespace files (or namespaced-then-global blocks) later blocks inherit a stale namespace label used in `qualifiedName()`/reporting. (Narrow in valid PHP since each `Namespace_` resets on enter, but it's a real robustness gap — cheap to fix.) **Fix:** reset `$this->namespace = null` when leaving a `Namespace_`.

---

## 6. Code quality, duplication & complexity

### Q1 — `Command::execute()` (300-line method) and the 3-way config-threading ladder
**`src/Cli/Command.php:142-453` · `src/Cli/ConfigLoader.php` · `src/Cli/Config.php:239-282`.** The `?? $overrides ?? $data ?? $base` precedence is hand-repeated for ~30 keys across `ConfigLoader::load`, `Config::withOverrides`, and `Command::execute`; the optional-blocks/db-symbols flattening is duplicated verbatim between `ConfigLoader::shapeOverrides()` and `Command::profileToOverrides()`. This is exactly where the next "forgot to thread a field" bug recurs (see C5b).
**Fix:** extract an `OverrideResolver` owning the single key list + precedence rule; move per-key range validation into one shared spec (today it's duplicated between `Config::__construct` and `ConfigLoader::validate`); split `execute()` into `parseOverrides()`/`resolveProfile()`/`buildPipeline()`/dispatch.

### C5b — `Config::withOverrides()` silently drops `debugLog` (latent for any new trailing field)
**`src/Cli/Config.php:239-282`.** The reconstructing ctor call ends at `mlPairThreshold` and never passes `debugLog`, so every path through `withOverrides()` — including the per-file `effectiveFor()` used by `PreprocessWorker` — resets it to `null`. The by-hand parameter duplication guarantees the next added field repeats this. **Fix:** add `debugLog: $this->debugLog`; longer-term a `cloneWith(array $changes)` helper.

### Q2 — Massive reporter boilerplate duplication (9+ copies of dir-create + file-write)
**`JsonReporter:14`, `SarifReporter:23`, `GitLabSastReporter:22`, `CheckstyleReporter:15`, `CsvReporter:28`, `PrometheusReporter:28`, `GraphvizReporter:24`, `PlantumlReporter:20`, `TimeseriesReporter:29`** all repeat the identical `dirname`/`is_dir`/`@mkdir`/`file_put_contents` block. **Fix:** a `trait WritesReportFile { writeFile(); ensureDir(); }` (keeps `final class` + pure `build()` convention).

### Q3 — Severity/confidence thresholds are reinvented per reporter and disagree
`GitLabSastReporter::severityFor` (>100/≥50/≥20), `GraphvizReporter::colourForImpact` (≥200/≥100/≥40), `CliReporter` (≥0.85/≥0.65), `SarifReporter` (`exact?warning:note`). The same cluster shows "High" / "rose" / "warning" / "success" with no shared definition and disagreeing cutoffs. **Fix:** centralize `Severity::forImpact(int)` and `Severity::forScore(float)`.

### Q4 — Pipeline stages: ~25 copy-pasted debug-log blocks + duplicated memory polling
**`ScanningStage`/`PreprocessStage`/`ClusterStage`.** The `if (verbosity>=DEBUG){ $msg=sprintf(…); writeln; pushDebug; }` block and `memory_get_usage`/`peak` polling are pasted at every checkpoint — and the duplication already bred a bug: `PreprocessStage.php:122` uses `$state->scannedFiles` (wrong counter) in the cache-check progress message. **Fix:** a `PipelineState::debug(OutputInterface, string)` and `::sampleMemory()` helper.

### Q5 — `ClusterStage::iter()` is 380 lines with near-identical serial/parallel scoring loops
**`src/Pipeline/Stages/ClusterStage.php:216-281` vs `282-338`** are ~60 lines of duplicated heartbeat/yield bookkeeping. **Fix:** extract a shared scoring driver; decompose `iter()` by clusterer phase.

### Q6 — Cooperative stages ignore the documented `$state->cancelled` contract **[verified]**
**`PipelineState.php:52-58`** says cooperative stages "MUST consult this between yields," but none of `ClusterStage`/`RefactorStage`/`PreprocessStage` check it — only `Pipeline.php:52` checks *between* stages. So `^C` during a long clustering run waits for the entire stage to finish. **Fix:** add `if ($state->cancelled) break;` at each yield checkpoint.

### Q7 — Other correctness/quality nits
- **`DbOpCanonicalizer::firstStringArg`** (`:363-375`) returns the first string-shaped arg *anywhere*, not the first arg, so it can pull SQL from the wrong argument and mis-canonicalize. Honor the contract (inspect arg 0 / the driver's SQL position).
- **`SARIF array_filter`** (`:122-135`) drops any `0`/`false` property — `impact:0`, `exact:false`, `optionalSegmentCount:0` vanish, so consumers can't distinguish "false" from "absent" (`similarity:0.0` survives, proving the filter is too blunt). Filter only `null`.
- **`ContainmentSimilarity`** (`:26-47`) docblock claims "returns 1.0 when every n-gram of the smaller side is present" but the multiset `min`-denominator breaks that when multiplicities differ — code and doc disagree.
- **`BehaviouralSimilarity`** (`:62,85-87`) scores "both tag-bags empty" as 1.0, contributing a spurious 0.25 floor to *every* non-DB pair — biases the type-4 fallback toward false positives. Exclude empty bands from the denominator.
- **`MinHashSignature`** (`:69-80`) derives all 128 permutations from one seed pair (`h1 + i*h2`); strongly correlated → real variance far above the "≈10% at K=128" claim. Seed each row independently.
- **`Semantic` summarizers** (`DataflowSummarizer`/`DbOperationTagger`/`CallGraph`) docblocks say they "stop at function boundaries," but `NodeFinder` recurses into nested closures — docs and behavior disagree; pick one.
- **Magic delimiter literals** (`\0`, `\x1F`, `\x1E`, `|`, `#`) scattered across `Hash`, `SubtreeHasher`, `NgramFingerprint`, `AstSerializer` with no central guarantee a token can't contain one. Centralize as named constants or length-prefix the payloads.
- **`UpdateCommand`** cosmetics: `"Asset {$pharUrl} not found"` interpolates a guaranteed-null (`:189-195`); the checksum strip regex requires trailing whitespace (`:204`). **`ServeCommand`** writes every response reason phrase as `OK` (`HTTP/1.1 404 OK`, `:182`).
- **`CliReporter::renderClusterList`** (`:120-128`) guards `isset($c->members[0])` for `location()` but then dereferences `$c->members[0]->kind` unguarded — a zero-member cluster warning-fatals under `failOnWarning`.

---

## 7. Testing gaps

### T1 — 72 of 146 source classes have no directly-named unit test **[verified]**
Notable untested high-risk units: `Clustering/Clusterer`, `Server/JobQueue`, `Cli/ServeCommand`, `Cli/UpdateCommand`, `Watch/WatchRunner`, the **entire `src/Ir/`** subsystem (IR lifting/printing/similarity + 12 node types), the `src/Architecture/Analyzers/*`, and `Semantic/ControlFlowGraph`/`DataflowSummarizer`/`PsalmTypeProvider`. Some are covered indirectly by Golden/Integration suites and some are pure DTOs, but the compute-heavy and security-sensitive units (Clusterer, ServeCommand, UpdateCommand) are exactly the ones lacking focused tests — which is how C1/C3 stayed green.
**Fix:** prioritize tests for `Clusterer` (determinism + threshold edges), `IrLifter` (semantic-equivalence pairs), `ServeCommand`/`Application` (path-sandbox), and `UpdateCommand` (signature verification once added).

### T2 — Existing tests assert too weakly, masking inert features
`EditCostModelTest` (bare labels + `assertLessThanOrEqual`) green-tests a dead feature (C1). Audit similarity/normalization tests to assert *strict* behavioral differences and to feed the **real** `shortType()` labels rather than hand-written bare ones.

---

## 8. Missing features & enhancements

### F1 — **Apply-refactor mode (`--apply`)** — the biggest missing capability
The tool is billed as a "refactoring assistant" but stops at *describing* refactors: `--patch` emits a `// TODO: implement` stub (D5) and signatures may not compile (D4). A real `--apply` would extract the anti-unified body into the synthesized function and rewrite each member call site (the AntiUnifier already computes holes/seed; PHP-Parser can do the rewrite + pretty-print). This is the feature that turns the tool from a linter into an assistant.

### F2 — **Duplication baseline / suppression file** (CI workflow)
There is no way to accept existing duplication and fail CI only on *new* dupes (the phpstan/psalm baseline model). `incremental` is a *cache*, not a baseline. A `--baseline phpdup-baseline.json` (write + compare) would make the tool adoptable on legacy codebases without a wall of pre-existing findings.

### F3 — **Changed-files / diff mode for PR CI** (`--diff-base=origin/main`)
`incremental` reuses the per-file index but still analyzes the whole tree. A git-diff-scoped mode (analyze only files changed since a ref, plus their clone-cohort) would make per-PR runs fast and focused. Pairs naturally with F2.

### F4 — **CI exit-code gating on thresholds**
`analyze` returns 0 regardless of findings (only `--validate-config`→2 and SIGINT→130 exist). A `--fail-on-impact=N` / `--max-clusters=N` gate (non-zero exit when exceeded) is table-stakes for CI use.

### F5 — **`phpdup init` config wizard** — generate a starter `phpdup.json` from a sniffed profile (the detector already exists), instead of hand-writing config.

### F6 — **Inotify/FSEvents watch backend** — see A1 (sync→async).

### F7 — **"Why are these duplicates?" explainability** — surface which tier matched (exact-hash / Jaccard / TED / containment / IR / ML) and the score per pair in the report, so users can trust/triage results. The data exists internally; it isn't exposed.

---

## 9. Sync → async opportunities

### A1 — Watch mode is poll-based and **duplicated**, and the non-TUI path misses new files
**`src/Watch/WatchRunner.php:103-120` vs `src/Cli/Command.php:494-536`.** Two independent mtime-poll implementations that have already drifted: `Command::pollChanges` detects new/deleted files; `WatchRunner::pollChanges` only iterates the existing snapshot, so `--watch` (without `--tui`) **never picks up newly-added files** until a full reload. Both poll every 1.5 s regardless of corpus size.
**Fix:** unify on one `FileWatcher` collaborator with new-file detection; add an inotify/FSEvents backend (keyed off the existing React loop) that falls back to polling — removes the 1.5 s latency and idle CPU.

### A2 — `serve` is a single synchronous accept-loop
**`src/Cli/ServeCommand.php` + `src/Server/Application.php`.** One blocking request at a time; a long analysis blocks all other clients, and the job queue (M5) implies async intent that the server doesn't deliver. **Fix:** either fork/worker-pool per request (the `WorkerPool` machinery exists) or run jobs on a background worker draining the queue while the HTTP loop stays responsive — and bound the queue (M5).

### A3 — ML scoring is HTTP-serial per batch
`MlPairClient::scoreBatch` already amortizes by batching (recent perf work), but batches are issued sequentially. With a remote sidecar, overlapping in-flight requests (curl multi) across worker chunks would hide latency. Lower priority than A1/A2.

---

## 10. Documentation

- **`README.md` is 2,650 lines / 109 KB** — effectively unmaintainable as a single file and certain to drift from the 52 CLI flags it documents. Split into `docs/` topic pages (most already exist) and keep the README a short orientation + links.
- **Docblocks that lie** are a recurring theme and a real maintenance hazard (they're why dead features looked alive): C1/C3 (semantic + match/switch), P5 (Bloom "fast path"), the semantic-summarizer boundary claims, `ContainmentSimilarity`, `CompactNgramBag` collision rate, `RefactorTestReporter`/`RefactorPatchReporter` "scaffolding done." Treat docblock claims as testable assertions where feasible.
- **`.logs/subtask2.log`** (19 KB) is committed working scratch — remove or `.gitignore`.

---

## Appendix A — verified correct (checked, no action needed)

So the next reviewer doesn't re-investigate:
- Disk-cache deserialization (`IndexStore`, `ClusterCache`, `AstCache`) is correctly hardened — explicit `allowed_classes` allow-lists + `__PHP_Incomplete_Class` rejection. (Outliers: `WorkerPool` IPC and `TokenCache` — S5.)
- **IR-tier (`--scorer=ir`) and ML-pair (`--ml-pair-url`) wiring is complete**, contrary to the "deferred clusterer wirings" note in project memory — both thread through `Config → ClusterStage → Clusterer`/`PairScoreWorker`, serial and parallel, honoring the null-score fail-graceful contract. *(Recommend updating `project_orm_dedup_scope` memory.)*
- `UnionFind` (`Clusterer.php:294-332`) — path-halving + union-by-rank, correct.
- `ExternalSort` cleanup (try/finally unlink, partial-iteration safety) is solid (its only gap is being unused — P6).
- HTML reporter body data is consistently `htmlspecialchars`-escaped (only the *highlighter* is broken — C4); CheckstyleReporter uses `ENT_QUOTES|ENT_XML1` correctly.
- Line-range slicing (HtmlReporter/DiffReporter/RefactorPatchReporter) is off-by-one-correct against `LineRange::lines()` (inclusive).
- `JobQueue` ids use `random_bytes(8)` (unguessable); `ServeCommand` body handling caps `Content-Length` (16 MiB) and headers (64 KiB) and rejects negative lengths — the path issue (S2) is the real problem, not byte handling.
- `BloomFilter::popcount` byte-table choice (over SWAR) correctly avoids PHP signed-int overflow.

## Appendix B — prioritized remediation roadmap

**Now (correctness + security):** C1, C2, C3, S1, S2 — then C4, C5/C5b, S4, P1.
**Next (scalability + dead weight):** P2, P6, M2, M5, D1 (delete or wire), D3, D4, D6.
**Then (maintainability):** Q1/Q2/Q3/Q4 extractions, Q6 cancel contract, T1 test backfill (Clusterer/Ir/ServeCommand/UpdateCommand first).
**Roadmap (features):** F2 (baseline) + F4 (exit gating) for CI adoption, then F1 (`--apply`), F3 (diff mode), A1 (unified watcher).
