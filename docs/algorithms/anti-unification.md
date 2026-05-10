# Anti-unification algorithm

The reverse of unification: given several concrete terms, find the
most-specific common generalisation. phpdup uses this to recover a
parametric abstraction from a cluster of similar code blocks.

For a worked example and a high-level walkthrough, see
`docs/tutorials/anti-unification-deep-dive.md`. This document is the
algorithmic reference.

## Inputs

- A `Cluster` with `members: Block[]`. Each Block carries an AST
  (loaded eagerly or lazy via `BlockAstLoader`).
- Config knobs: `optionalBlocksEnabled`,
  `optionalBlocksMaxPerCluster`, `optionalBlocksMinSegmentLength`.

## Output

The cluster is mutated in place with:

- `generalizedAst` — a deep clone of the seed member's AST,
  unmodified.
- `holes` — a list of `Hole` objects, each carrying:
  - `placeholder`  — opaque id like `__P0`
  - `kind`         — `literal` / `name` / `call` / `subtree` / `optional_block`
  - `observedValues` — one repr-string per cluster member
  - (later) `inferredType`, `suggestedName` — populated by
    `ParameterSynthesizer`.

## Phases

### 1. Seed selection

Pick a member to use as the unification template. The seed should
be the most-general member — others specialise from it. Multi-seed
search:

```
candidates = top 3 members by AST size
for c in candidates:
    holes = trial unification with c as seed
    if holes < bestHoles: best = c
return best
```

Single-seed (largest member) is the fallback when count < 3.

### 2. Path-based walk

Walk seed and member i in lockstep. For each (seed, member) pair:

```
path  = [...]                     # tracks position in the AST
key   = serialise(path)

if seed and member are both null     → no-op
if either is null, or kinds differ   → emit subtree hole
if both are scalar leaves:
    if values match                  → no-op
    else                             → emit leaf hole (literal/name/call)
else:
    for each subnode name:
        if subnode is array<Node>:
            if lengths match: zip-walk
            else (with optional_blocks_enabled):
                LCS over per-statement structural hashes;
                emit one optional_block hole per unmatched template stmt
        elif subnode is Node:
            recurse with [...path, subname]
```

### 3. Optional-segment LCS

Specifically for statement arrays of differing lengths
(`{ a(); b(); c(); d(); }` vs `{ a(); b(); c(); }`):

- Compute the structural hash of each statement.
- Run LCS over the two sequences.
- Matched indices recurse normally.
- Unmatched template indices become `optional_block` holes whose
  observed values are `<absent>` for members that lacked the
  segment, the seed's repr otherwise.
- Cap the optional-block count via `max_per_cluster`; over-budget
  clusters fall back to a single whole-array `subtree` hole.

### 4. Backfill + remap

Two clean-up passes after the walk:

- **Backfill**: hole observation arrays are padded so every member
  contributes a value at every hole, even when LCS skipped a
  position.
- **Remap**: observation arrays are reordered from internal (seed-
  first) order back to the original `cluster.members` order, so
  reporters see per-member values in the order they expect.

## Complexity

- Per-pair: O(n) where n is the seed's AST size — one walk.
- Multi-seed: 3 × per-pair worst case.
- LCS per statement-array: O(k²) on the array length k; bounded
  by `max_per_cluster`.

In practice the entire `unify()` pass is microseconds-per-cluster on
typical projects.
