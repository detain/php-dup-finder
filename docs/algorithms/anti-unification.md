# Anti-unification algorithm

Given a set of similar blocks (a cluster), find the common abstraction:
a generalized AST plus a set of typed holes where the members differ.
This is the stage that turns "these five methods are the same except
the field names" into a parameterised function.

## Inputs

- `Cluster::$members` — the Block objects (their `canonical` ASTs, loaded
  via `BlockAstLoader` under `lazy_ast`, or already held in memory).
- Config knobs: `optionalBlocksEnabled`, `optionalBlocksMaxPerCluster`,
  and `optionalBlocksMinSegmentLength` — the last one is **admitted,
  validated and threaded into `AntiUnifier`'s constructor but never
  read** (DA-06; php-dup-finder-once-078): the LCS promotes every
  unmatched template statement regardless of run length. It rides the
  cluster cache key even so, so changing it invalidates warm caches
  while producing identical clusters — an open implement-vs-document
  owner ruling, written here so the doc matches the code today.
- The seed template — the member chosen as the universalisation base.

## Output

- `Cluster::$generalizedAst` — the seed AST with every hole position
  replaced by its placeholder (a deep clone of the winning template).
- `Cluster::$holes` — `list<Hole>`; each hole carries:
  - `id` / `placeholder` — `__P<n>` (rendered as `$__P<n>` in the
    template body),
  - `kind` — one of `literal`, `identifier`, `name`, `subtree`,
    `optional_block` (emission rules below). `kindForLeaf` maps scalar
    nodes (strings, ints, floats, interpolated strings) → `literal`,
    `Expr\Variable` → `identifier`, `Node\Identifier` / `Node\Name`
    → `name` (including `VarLikeIdentifier`, a subclass), everything
    else at a leaf position → `subtree`. A historical `call` kind
    was documented but never minted (its dead consumer branches are
    a separate cleanup) — do not write matching on it.
  - `observedValues` — one entry per member, in `Cluster::$members`
    order (the walk visits seed-first order, so rows are reordered
    after unification). A row is either a repr string or the
    canonical gap marker `Hole::MISSING` (the empty string, output-S27
    — NOT a printable fake; reporters project `null`/`absent` from the
    data, never a magic token found in it);
  - `repr` grammar (what an observed row looks like): strings go
    through `var_export` (single-quoted with escapes), ints print
    bare, floats `var_export` too (output-S28 — float typing survives:
    `1.0`, not `1`); variables render `$name`, identifiers bare,
    names FQN-ish via `toString()`; expression/stmt subtrees are
    pretty-printed; unprintable shapes fall back to `<NodeType>`.
  - `degraded` — set when the hole's row set is incomplete evidence:
    a member simply had no node at the path (a shorter block), a row
    that was never gathered, or (output-S26) an optional alignment
    with member-side extras — see step 3.
- `Cluster::$holePaths` — `hole id → AST path`, produced by the walk
  and consumed by `ApplyExtractor` (the member call-site rewrite in
  `--apply`). Paths are template-relative and survive the row remap.

## Steps

1. **Seed choice (multi-seed).** For clusters with ≥ 3 members, trial
   the 3 largest as templates (`SEED_CANDIDATES = 3`; ties keep scan
   order under the stable sort) and keep the one whose walk yields the
   smallest hole set. The winner's context is carried forward — there
   is no production re-walk; the post-walk passes are the pad and the
   row remap (and one deep clone of the template AST). Below 3 members
   the walk runs once against the picked seed index. Worst case is 3
   trial walks (the 2026-04 "worst case is 3×" promised less than the
   then-shipped code did and more than today's does; the dated
   erratum in `docs/adr/0002-anti-unification.md` carries the
   provenance).

2. **Lockstep walk** of seed and each other member over the AST. At
   each node:
   - both sides null → continue;
   - either side null, or the kinds differ → `subtree` hole;
   - both scalar leaves → compare; equal → continue; different → leaf
     hole kinded by `kindForLeaf` (`literal` / `identifier` / `name`,
     falling back to `subtree`);
   - node arrays: matched subnode keys recurse normally; statement
     arrays of unequal length take the LCS path (step 3) when
     `optionalBlocksEnabled` and both sides actually look like
     statement lists; otherwise (and whenever that pipeline refuses —
     see the two caps in step 3) the whole array becomes one `subtree`
     hole;
   - holes are created with their earlier members accounted: a
     predecessor the walk proved token-equal at this path gets the
     seed's repr as its row; one that diverged at or above the path
     (or never reached it) gets `Hole::MISSING` with the hole degraded
     (output-018: silence is never recorded as agreement).

3. **Optional-segment LCS.** For two statement arrays of different
   length (`{a;b;c;d}` vs `{a;b;c}`), hash every statement and run a
   longest-common-subsequence DP over the hash sequences. Matched
   positions recurse; unmatched TEMPLATE positions become
   `optional_block` holes (the seed has a statement there, the member
   does not). The observed row for a member that lacks the segment is
   `Hole::MISSING` — a **verified absence when the hole stands
   undegraded** (the walk saw the slot and it held nothing;
   appendObserved, T4d): `degraded:false` + MISSING says "this member
   definitely lacks it", `degraded:true` says some MISSING rows may be
   never-observed gaps — the two cases share the row and the flag
   carries the difference). The row for a member that HAS the segment
   is its repr.

   Two caps guard the abstraction:

   - `optional_blocks.max_per_cluster` (default 3) bounds how many
     optional holes a cluster can carry. When the alignment would push
     the count past the cap, the ENTIRE divergent array collapses into
     one `subtree` hole instead of shipping seven boolean parameters
     (output-S26 rework).
   - `optional_blocks.min_segment_length` is documented and threaded
     but NOT enforced (see Inputs) — once wired it will suppress
     promoting gaps shorter than N statements.

   Member-side extras (statements this member has that the template
   lacks) cannot become holes — holes are template-relative — so when
   they exist, every optional segment born from that alignment is
   marked `degraded`, flagging that the synthesized booleans understate
   what the member drops.

4. **Backfill (pad).** `fillGaps` runs after the winning walk and pads
   every hole's row array to the full member count: holes born while
   earlier subnodes diverged are never reached by later members, and
   the pad records those rows explicitly as `Hole::MISSING` with the
   hole degraded (output-018). The old whole-hole backfill with the
   seed's repr is retired — silence is no longer recorded as agreement.

5. **Remap.** Rows emerge from the walk in internal (seed-first) slot
   order. `remapRowsByMemberId` addresses every row by the cluster
   MEMBER ID that owns it and re-emits all rows into `Cluster::$members`
   order in one pass over all holes (php-dup-finder-once-039, S35-era)
   — order-correct whatever the walk permutation is, and duplicate
   members (content-derived ids) pair by occurrence order. The output
   shape is unchanged: a dense positional list in member order —
   positional is the contract reporters and ApplyExtractor's member
   walk index by; only the establishment of that order moved from a
   positional assumption to id addressing. Id-less fixtures (hand-built
   clusters without the ids PreprocessStage stamps) fall back to the
   local 0↔seedIdx transposition (output-018's unconditional,
   placeholder-aware form), and a belt loop inside refuses to ever
   skip the remap on a mis-ordered call.

6. **Downstream.** `ParameterSynthesizer` walks `observedValues`
   (skipping MISSING rows — they are absence, not observation) to infer
   each hole's type and suggested name; the boolean params for
   `optional_block` holes are synthesized with the same
   observed-values-only law. `Cluster::$holePaths` is consumed by
   `ApplyExtractor`, which lands member call-site rewrites as a
   write-window transaction with read-back verification (output-S44a/S44b):
   the abstraction keeps each member's declaration and only its body
   delegates (output-S46), so a refused target rolls the window back
   instead of leaving half-rewritten files. The optional-segments
   pattern tag and the `bool $include* = false` parameter story that
   flow from steps 3-6 are what turns "block 1 has 5 stmts, block 2
   has 3 of those" into `function f(..., bool $includeFooBar = false)`.

## Complexity

- Per-pair: O(n) where n is the seed's AST size — one walk.
- Multi-seed: 3 × per-pair worst case (trial walks; the winner is
  reused, pad + remap are O(holes × members) and O(holes × members)
  single passes respectively).
- LCS per statement-array: O(n × m) on the two array lengths, once per
  (seed, member) pair that has a length mismatch.
- The optional-hole COUNT is bounded by `max_per_cluster` (step 3's
  cap), which limits the hole-set size, not the DP cost.

In practice the entire `unify()` pass is microseconds-per-cluster on
typical projects.

## Dated notes (2026-11-22, once-S68 doc-truth wave)

- `BinaryOp\Greater_` — the class name used in the worked-path example
  of this document family (it lived in the deep-dive's §example) is
  wrong on every php-parser major: `Expr\BinaryOp\Greater` (the
  trailing underscore marks a handful of reserved-word classes;
  `Stmt\Throw_` had one, gone in v5). The deep-dive seat itself lives
  outside this file; its correction is a sibling lane's (DW-22 report
  row — this document carries the finding's memory so the name never
  re-grows here).
- The kind vocabulary above (`literal|identifier|name|subtree|
  optional_block`) supersedes the pre-S15 text's `literal/name/call/
  subtree/optional_block`: `call` never emitted, `identifier` did
  (DA-09), and `Hole.php`'s own docblock still lists `call` while
  omitting `optional_block` (the class is outside this step's locks;
  report row).
- The `min_segment_length` inertness is the one knob whose story here
  will change: whichever way the owner ruling lands (implement the
  filter in the LCS, or retire the knob), this Inputs bullet and step
  3's second cap update together.
