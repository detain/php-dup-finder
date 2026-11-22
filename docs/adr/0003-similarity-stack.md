# ADR 0003 — Three-tier similarity stack

**Status**: accepted
**Date**: 2026-05
**Amended**: 2026-11 (engine-S31c catalog close-out: tier-3 wiring truth,
normalization policy absorbed from the deleted marker classes)
**Amended**: 2026-11-21 (once-S32 advertised-surface parity: flag token
retired from the prose, dated erratum appended)

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

Each tier's threshold is configurable. Pairs that cross tier 1's
threshold proceed to tier 2; pairs that cross tier 2's threshold
contribute an edge. Pairs that fail tier 2 fall through to
`ContainmentSimilarity` when `optional_blocks.enabled` is set.
(The former prose named a CLI long-option flag for the behavioural
leg — no such flag exists and never shipped; the engine-S31c catalog
close-out corrected the claim, and the once-S32 erratum below records
its removal from this document's text. Optional IR-tier and ML-pair
scorers exist behind `--scorer=ir` / `--ml-pair-url`; they extend the
cascade rather than the three tiers of this decision.)

## Tier-1 pre-filters

Before invoking APTED, two more cheap pre-filters trip:

1. **Size-delta**: if the trees differ in size by more than the
   threshold permits, no number of matches could close the gap →
   skip linearisation.
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
int-only.

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
