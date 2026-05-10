# Anti-unification deep dive

How phpdup turns a cluster of similar code blocks into a single
suggested abstraction with parameter holes.

## The problem

Given two methods:

```php
function notifyHigh($user, $score) {
    if ($score > 10) {
        send('admin', $user);
    }
}

function notifyMid($user, $score) {
    if ($score > 20) {
        send('moderator', $user);
    }
}
```

we want to recover the abstraction:

```php
function notifyByThreshold(int $threshold, string $value, $user, $score) {
    if ($score > $threshold) {
        send($value, $user);
    }
}
```

— **automatically**, with type-inferred parameter names.

## Algorithm

`Phpdup\Refactor\AntiUnifier` runs in three phases:

### 1. Seed selection

Pick a member to use as the **template**. The seed should be the
most-general member — the one that has the most structure other
members can specialise from.

phpdup tries the top 3 candidates ranked by AST size and keeps the
seed that produces the fewest holes (multi-seed search; see
`pickBestSeed()`). Falls back to size-only when there are ≤ 2
candidates.

### 2. Path-based unification

Walk the seed and every other member in lockstep. Record divergences
as `Hole` objects keyed by AST path:

```
[
  ['Stmt\\If_', 'cond', 'BinaryOp\\Greater_', 'right'] → '10' / '20'
  ['Stmt\\If_', 'stmts', 0, 'expr', 'args', 0]         → "'admin'" / "'moderator'"
]
```

The template AST itself is untouched — divergences live in the
`UnifyContext::$holes` side table.

### 3. Optional-segment LCS

When subtrees are arrays of statements with **different lengths**,
run LCS over per-statement structural hashes. Matched positions
recurse normally; unmatched template positions become
`optional_block` holes — emitted as `bool $includeFooBar = false`
parameters in the suggested signature.

This is the magic that lets

```php
function long()  { a(); b(); c(); d(); e(); }
function short() { a(); b(); c(); }
```

cluster together with

```php
function commonShape(bool $includeD = false, bool $includeE = false) {
    a(); b(); c();
    if ($includeD) { d(); }
    if ($includeE) { e(); }
}
```

without forcing a brittle whole-array hole on the difference.

## Hole post-processing

After unification, two more passes refine the holes:

- **`ParameterSynthesizer`** classifies each hole's observed values
  (int / float / string / bool / null / class-string) and emits a
  union type when types disagree. Generates a friendly parameter
  name from the longest common substring of the observed values, or
  falls back to a role name (`$threshold`, `$value`, `$callback`).
- **`PatternRecognizer`** tags clusters with high-level shape
  labels (`strategy`, `state-machine`, `sql-builder`, `crud-handler`,
  `controller-action`, etc.) so reviewers can prioritise familiar
  patterns first.

## Confidence vs safety

The pipeline emits two scores per cluster:

- **`confidence`** — how sure the unifier is the members
  structurally agree. Anchored to the cluster's pairwise
  similarity, with subtree-hole and namespace-spread penalties.
- **`safety`** — how mechanical-refactor-friendly the cluster
  looks. Combines hole-type quality, member count (pair penalty,
  3–8 sweet spot), and pattern-tag deltas. Reviewers should sort
  by safety, not just impact.

## Worked example

Given the notify cluster above, phpdup's pipeline reports:

```
Cluster #1 — similarity 1.00 — impact 44 — members 2
holes:
  $threshold : int    (10, 20)
  $value     : string ('admin', 'moderator')
patterns: config-driven
✓ confidence 1.00 · safety 0.93
```

— exactly the abstraction we wanted.
