# ADR 0002 — Path-based anti-unification

**Status**: accepted
**Date**: 2026-04

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
- Otherwise → create a hole here, backfill prior members' implicit
  agreement with the seed, record member i's divergence.

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
choice. We try the top 3 candidates by size and keep the one whose
unification produces the smallest hole set
(`AntiUnifier::pickBestSeed()`). Worst case is 3× per cluster;
amortised cost is ~1.5× since most clusters have an obvious-largest
seed.

## Optional-segment LCS

When two members have differing-length statement arrays (think
`function long() { a(); b(); c(); d(); }` vs `function short() { a(); b(); }`),
LCS over per-statement structural hashes pairs up the matched
statements and emits one `optional_block` hole per unmatched
template statement. Hole values are `<absent>` for members that
omit the segment, and the seed's repr for members that have it.

That's how phpdup's `optional-segments` pattern tag fires and how
the suggested abstraction gets `bool $includeFooBar = false`
parameters.
