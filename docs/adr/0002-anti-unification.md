# ADR 0002 — Path-based anti-unification

**Status**: accepted
**Date**: 2026-04
**Amended**: 2026-11-22 (once-S68 doc-truth wave: multi-seed cost
story gets a dated erratum — see below; optional-segment section
updated to the shipped cap-fallback, degraded-row and inert-knob
truth).

## Context

Given a cluster of N similar code blocks, we need to produce a
single AST that captures their common shape plus a list of holes
recording where the members disagree. Two approaches:

**A. Embedded sentinel nodes**: build a synthesised AST containing
   special "hole" sentinel nodes inline. Nice for visualisation but
   requires extending the parser node hierarchy and breaks any
   visitor that doesn't know about the sentinels.

**B. Path-based holes**: leave the seed member's AST untouched as
   the template, and record holes externally as
   `(AST path → observed values)` tuples.

## Decision

**B**: path-based holes. Holes are stored on `UnifyContext::$holes`
and surfaced on the `Cluster` post-unification.

Walk the seed and every other member in lockstep. At each node:

- If a hole already exists at this path → record the member's value
  (member i's repr at this position).
- If both sides have the same scalar → continue recursing.
- Otherwise → create a hole here and account for prior members: a
  member the walk proved token-equal at this path gets the seed's
  repr as its row (agreement actually witnessed); one that diverged
  at or above this path — or never reached it — gets the canonical
  `Hole::MISSING` row with the hole degraded (output-018: silence
  is never recorded as agreement). Then record member i's divergence.

Subtree holes (where one side has children and the other doesn't,
or the kinds disagree) collapse the entire subtree into one hole.

## Consequences

- The template AST is untouched and always parseable.
- Visitor authors don't need to know about holes.
- Reporters render holes by walking the path and matching against
  the template — slightly more complex than rendering inline
  sentinels but worth it for the cleanliness.

## Multi-seed search

Choosing the seed matters. The largest member usually has the most
structure for others to specialise from but isn't always the best
choice. We try the top 3 candidates by size (`SEED_CANDIDATES = 3`;
ties keep scan order under a stable sort) and keep the one whose
trial walk produces the smallest hole set
(`AntiUnifier::pickBestSeed()`). The winning trial's context is
CARRIED FORWARD as the final unification — the walk only reads the
template, so there is no production re-walk; the extra cost after
the ≤ 3 trials is the pad and the row remap, plus one deep clone of
the winner's AST for the mutation-ready `generalizedAst`. Worst
case is therefore 3 walks per cluster; the "~1.5× amortised" note
that originally sat here was a sizing heuristic, never a
measurement, and is retired. Clusters with fewer than 3 members
walk once against the picked seed index.

**Erratum (dated 2026-11):** the original "Worst case is 3×" was
false for most of the feature's life. From the first implementation
(2026-05, a451c4e) until the carry-forward landed (output-S25,
6ac5809, 2026-11-21) `unify()` re-ran a production walk after the
trials — worst case was 4 walks, and the 2026-11 audit
(php-dup-finder-once-078 DA-10) correctly flagged the doc as
undercounting. The claim is true again today, by a route the
original text did not describe.

## Optional-segment LCS

When two members have differing-length statement arrays (think
`function long() { a(); b(); c(); d(); }` vs `function short() { a(); b(); }`),
LCS over per-statement structural hashes pairs up the matched
statements and emits one `optional_block` hole per unmatched
template statement. Hole values are the canonical absence row
(`Hole::MISSING` — rendered by reporters as an honest "absent" marker,
never a printable impostor in the data) for members that
omit the segment, and the seed's repr for members that have it.

Three shipped caveats ride on this section (verified 2026-11):

- **The alignment is statement-list-gated.** If either side's array
  is not a statement list, or `optional_blocks.enabled` is off, the
  divergent array collapses straight into a whole-array `subtree`
  hole (legacy posture) — LCS never runs.
- **The count is capped.** When the optional holes that would be
  born here plus those already standing exceed
  `optional_blocks.max_per_cluster` (default 3), this alignment
  degrades to the whole-array `subtree` hole instead of shipping a
  seven-boolean signature.
- **Extra member statements cannot become holes.** Holes are
  template-relative; a member holding statements the template
  lacks silently under-represents that member. When such extras
  exist, every optional segment born from that alignment is marked
  `degraded` so reporters say so (output-018 law). A
  `degraded:false` MISSING row on an optional hole is a VERIFIED
  absence; `degraded:true` says some MISSING rows were never
  observed at all (output-S26/S27 semantics).
- **`min_segment_length` does not gate anything today.** The knob is
  admitted, validated (≥ 1), threaded through Config → cache key →
  stage → worker → `AntiUnifier`'s constructor — and never read: the
  LCS promotes every unmatched template statement regardless of run
  length (php-dup-finder-once-078 DA-06; implement-vs-document is an
  open owner ruling). Until that lands, the documented default of 1
  is also the only value whose behaviour the docs describe honestly.

That's how phpdup's `optional-segments` pattern tag fires and how
the suggested abstraction gets `bool $includeFooBar = false`
parameters.
