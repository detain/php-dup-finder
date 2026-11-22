# ADR 0003 — Three-tier similarity stack

**Status**: accepted
**Date**: 2026-05
**Amended**: 2026-11 (engine-S31c catalog close-out: tier-3 wiring truth,
normalization policy absorbed from the deleted marker classes)
**Amended**: 2026-11-21 (once-S32 advertised-surface parity: flag token
retired from the prose, dated erratum appended)
**Amended**: 2026-11-22 (once-S68 doc-truth wave: the tier-fallthrough
story is rewritten to the cascade both engines run today, the
size-delta pre-filter gets its unit-cost proviso (DA-14), and a
cost-ceilings section documents the aggregate APTED budget
(engine-S10) and the first-wins tier vocabulary (engine-S15).)

## Context

A single similarity score doesn't generalise across all clone
types. Type-1 / type-2 (token-level) clones, type-3 (with gaps),
and type-4 (behavioural-only) need different scorers — but running
the most expensive scorer on every pair is unaffordable on real
corpora.

## Decision

Three tiers, run in increasing cost order with early rejection:

```
Tier 1: Jaccard over n-gram bag       (cheap; rejects most pairs)
Tier 2: Tree-edit distance (APTED)    (expensive; only on Jaccard hits)
Tier 3: Containment                   (type-3 fallback; the only wired tier-3 scorer)
Planned: Behavioural                  (type-4 — NOT wired, see Tier-3 fallbacks)
```

Each tier's threshold is configurable. The cascade, as shipped in
BOTH scoring engines (verified 2026-11-22 against
`Clusterer::scorePairsSerially` and `PairScoreWorker::score`):

- Identical structural hashes mint the `exact-hash` tier without
  scoring.
- Pairs that cross tier 1's threshold proceed to tier 2 — behind the
  per-pair `max_ted_size` gate: over-cap pairs mint a
  `jaccard-unverified` edge instead of paying APTED.
- Pairs that cross tier 2's threshold contribute an edge weighted
  `min(jaccard, ted)` and tiered `jaccard` — the tier records the
  lens that FOUND the match (first-wins policy, engine-S15). No
  engine mints a `ted` tier: the `MatchTier::Ted` enum case is
  deleted and the vocabulary is exactly `exact-hash | jaccard |
  jaccard-unverified | containment | ir | ml` (pinned by
  `TedSizeGateTest::testTierVocabularyMintsNoPhantomTier`).
- Pairs tier 2 REFUTES do NOT fall through to `ContainmentSimilarity`
  — that was the old prose, and it was wrong twice over (it named the
  containment lane's customer incorrectly). The containment lens
  weighs pairs tier 1's Jaccard gate REJECTED, when
  `optional_blocks.enabled`. The refuted lane stays closed to it by
  design: containment ≥ jaccard for every pair, so bag evidence
  re-acclaiming what the tree refuted would mute the tree tier on
  every nested-shape pair. A TED refutation still drops the pair to
  the IR and ML arms — engine-S15 made the refuted lane rescuable by
  genuinely different lenses — but only on the SERIAL engine: the
  fork `PairScoreWorker` still `continue`s past every arm on a TED
  refutation (twin gap, report-only row).
(The former prose named a CLI long-option flag for the behavioural
leg — no such flag exists and never shipped; the engine-S31c catalog
close-out corrected the claim, and the once-S32 erratum below records
its removal from this document's text. Optional IR-tier and ML-pair
scorers exist behind `--scorer=ir` / `--ml-pair-url`; they extend the
cascade rather than the three tiers of this decision.)

## Tier-1 pre-filters

Before invoking APTED, two more cheap pre-filters trip:

1. **Size-delta**: if the trees differ in node count by more than
   `|size(a) − size(b)| > ceil((1 − threshold) × max(size(a),
   size(b)))`, no number of matches could close the gap → skip
   linearisation. The absolute value is load-bearing (the old prose
   stated the bound without it, which reads as never tripping when
   b is the larger tree). The "no number of matches" law is a
   UNIT-cost identity: under `default` weights every unmatched node
   costs exactly 1, so the count gap lower-bounds the edit cost
   directly. Under `semantic` weights node costs span 0.5–2.0
   (scaled to integers ≥ 1 by the ×2 quantiser), so the count gate
   is a cheap conservative screen, not the exactness law for the
   weighted lane — that role belongs to the banded DP normalising
   against the weighted worst-case cost of the trees themselves.
2. **Shapelet sketch**: 64-bit (node-type, depth) histogram. Pairs
   with overlap < threshold/2 can't possibly be that similar →
   reject in ~10 ALU ops.

These are gated to `threshold > 0` so exact-match callers
(`threshold = 0`) see no behaviour change.

## Tier-2 weighted costs

`AptedDistance` accepts an `EditCostModel`:

- `default` — unit costs (legacy).
- `semantic` — method calls = 2.0, control flow = 1.5, literals =
  0.5, everything else = 1.0. Better proxy for behavioural
  similarity.

Costs are quantised to small integers (×2 scaler) so the DP stays
int-only. The admitted model reaches BOTH engines: shell-S14 threaded
the cost table into `PairScoreWorker`'s constructor, closing the
window where the serial Clusterer honoured `--ted-weights` while the
fork zone scored gate survivors on hardcoded defaults (the stale
"mirrored byte-for-byte" promise in the serial loop's comment was
corrected with it — once-028).

## Cost ceilings

Two budget gates bound the expensive leg (engine-S10 era, verified
2026-11-22):

- **Per pair** — `max_ted_size` (config key, root-only, default 800
  = the extraction ceiling, so inert unless lowered): over-cap pairs
  mint `jaccard-unverified` instead of paying APTED. Applied
  identically by both engines.
- **Aggregate** — `Clusterer::DEFAULT_MAX_TED_COST = 100,000,000`
  node-pair units. An INTERNAL constant: no CLI flag, no config key
  (plan direction). The serial `Clusterer::scorePairsSerially`
  charges `size(a) × size(b)` for every verdict it is about to pay,
  and when a pair would carry the accumulator past the cap it throws
  a loud `RuntimeException` naming the budget, the running total and
  the ways forward — halt BEFORE paying, never truncating
  mid-corpus. The clock never enters the accumulator, so below the
  cap the edge list is pair-for-pair identical to the unguarded
  loop. Scope: the serial surface only (library direct API and the
  in-process fallback). The fork `PairScoreWorker` loop has no
  aggregate counterpart yet — a report-only row carried since
  engine-S10; the transport legs (once-S33 budget story, once-S74
  framing caps) decide when and how the fork side inherits it.

## Tier-3 fallbacks

- `ContainmentSimilarity` — for type-3 clusters where one block is
  a near-subset of the other. LIVE: this is the only tier-3 scorer
  the Clusterer instantiates.
- `BehaviouralSimilarity` — for type-4 (behavioural) clusters.
  Combines var/call/return Jaccard + a side-effect-flag match over
  the `DataflowSummarizer` summary. PLANNED, NOT WIRED: the CLI flag
  the old prose gated it behind does not exist (see the dated
  erratum), and the Clusterer never instantiates it. The class is
  kept, tested, and honest per the engine-S31a catalog ledger
  (rows 3 and 16).

## Normalization beneath the stack

Every tier above scores *normalized* ASTs, so the normalization
policy belongs with this decision. It used to be stated by two
marker classes with zero production references
(`Normalization\LiteralCanonicalizer`,
`Normalization\NameCanonicalizer`) whose `PLACEHOLDER_*` constants
duplicated literals inlined in `Normalizer` — pure drift risk; both
were deleted by the engine-S31c catalog close-out. The accurate
policy, as `Normalizer` implements it today:

- **Literals** (default + aggressive modes): string values →
  `__STR`, integers → `0`, floats → `0.0`; interpolated-string parts
  collapse to `__STR`.
- **Names** (aggressive mode only): call, property and class
  identifiers become NAME-STAMPED sentinels — `__CALL_<name>`,
  `__PROP_<name>`, `__CLASS_<name>` — injective on the identifier
  space, so branch-role-swapped pairs can no longer re-deal into
  byte-identical streams (engine-002). The deleted markers' prose of
  first-occurrence `__CALLn` ordinals and a `__KEY` sentinel
  described the pre-engine-S02 scheme: ordinals were replaced by
  stamps, and the `__KEY` case was never implemented (engine-S02a
  report-only row, folded here).
- **Structural functions keep their names** — `isset`, `empty`,
  `unset`, `count`, `is_*` predicates, `array_map`/`array_filter`/
  `array_reduce`, and the synthetic `__DB_<OP>` tokens emitted by
  `DbOpCanonicalizer` (`Normalizer::isStructuralFunction()` is the
  list).
- **Variables keep first-occurrence ordinals** (`__V0`, …) on
  purpose: local-name agnosticism is the mode's contract and
  variable slots carry no cross-block identity claim.
- Downstream, the n-gram fingerprint and APTED label passes fold the
  name stamps symmetrically via `Util\IdentifierIds`.

## Consequences

- Pair scoring stays linear-ish in corpus size for typical
  duplicate densities.
- Adding a new scorer = adding a new tier or extending an existing
  one — no centralised dispatch to refactor.
- Each scorer is independently testable and benchmarkable.

## Erratum 2026-11-21 — advertised-surface parity (once-S32)

- The pre-amendment text of this decision documented a CLI
  long-option flag gating the behavioural (type-4) tier. No such
  flag exists, never shipped, and was never accepted by
  `src/Cli/Command.php`. The engine-S31c close-out corrected the
  claim in prose; this pass retires the literal flag token from the
  ADR text entirely, so a grep for it no longer hits a tree where
  the flag does not exist (the exact former token is preserved in
  this document's git history).
- Advertised-surface census at this commit: README.md carries no
  type-4/behavioural feature rows (the dead ToC entry and its
  docs/CLI.md anchor were dropped by engine-S31c);
  `docs/algorithms/clustering.md` documents only the wired passes
  (hash buckets, Jaccard, APTED with pre-filters, Containment
  fallback, union-find); every class under `src/Semantic/` and
  `src/Validation/` now states its wired/unwired status per the
  engine-S31a catalog ledger.
- Path forward for the planned tier: wiring `BehaviouralSimilarity`
  into the Clusterer is an explicit product call and must sit
  behind its own opt-in flag (engine-S31a ledger row 3). Until a
  flag ships, this ADR — and no other tracked doc — may name one.
