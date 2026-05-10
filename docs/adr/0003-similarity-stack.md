# ADR 0003 — Three-tier similarity stack

**Status**: accepted
**Date**: 2026-05

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
Tier 3: Containment / behavioural     (fallback for type-3 / type-4)
```

Each tier's threshold is configurable. Pairs that cross tier 1's
threshold proceed to tier 2; pairs that cross tier 2's threshold
contribute an edge. Pairs that fail tier 2 fall through to tier 3
when `optionalBlocksEnabled` (containment) or `--type4` (behavioural)
is set.

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
  a near-subset of the other.
- `BehaviouralSimilarity` — for type-4 (behavioural) clusters.
  Combines var/call/return Jaccard + side-effect-flag match.
  Higher false-positive risk; gated behind a config flag.

## Consequences

- Pair scoring stays linear-ish in corpus size for typical
  duplicate densities.
- Adding a new scorer = adding a new tier or extending an existing
  one — no centralised dispatch to refactor.
- Each scorer is independently testable and benchmarkable.
