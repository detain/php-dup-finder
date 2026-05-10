# Plan — ORM-aware / DB-aware semantic deduplication

**Status**: planning · **Decision needed**: which option(s) to ship,
in which order. **Reading time**: ~15 min.

This is the detailed-options doc the user asked for. It covers the
problem, the spectrum of approaches (with honest tradeoffs), and a
recommended phased rollout.

---

## 1. The problem

phpdup currently clusters by AST shape (after canonicalisation).
That misses two large families of duplicates:

### 1.1 ORM ↔ raw-SQL equivalence

```php
// Variant A — Eloquent
$user = User::find($id);
$user->name = 'Bob';
$user->email = 'bob@example.com';
$user->save();

// Variant B — Doctrine
$user = $em->find(User::class, $id);
$user->setName('Bob');
$user->setEmail('bob@example.com');
$em->flush();

// Variant C — raw SQL
$db->query("UPDATE users SET name='Bob', email='bob@example.com' WHERE id={$id}");

// Variant D — query builder
$db->table('users')->where('id', $id)->update(['name' => 'Bob', 'email' => 'bob@example.com']);
```

All four perform the same write. None cluster today because:

- A's tokens: `User`, `find`, `id`, `name`, `Bob`, `email`, …
- B's tokens: `em`, `find`, `User::class`, `setName`, `setEmail`, `flush`
- C's tokens: `db`, `query`, `UPDATE users SET …`
- D's tokens: `db`, `table`, `where`, `update`, `[name => 'Bob', …]`

Even with aggressive name canonicalisation the structural shape
diverges (call chain depth, argument count, types).

### 1.2 Library/extension interchangeability

Same operation across DB libraries:

```php
// PDO
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// mysqli (object)
$stmt = $mysqli->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

// Laravel DB facade
$row = DB::select('select * from users where id = ?', [$id])[0];

// PostgreSQL pg_*
$res = pg_query_params($conn, 'SELECT * FROM users WHERE id = $1', [$id]);
$row = pg_fetch_assoc($res);
```

All four execute the same SELECT. None cluster today.

The user's ask: **treat library/extension calls as interchangeable
when they're doing the same thing with the data.**

---

## 2. Where this fits in the existing pipeline

phpdup's current scoring stack:

```
  Tier 0:  hash buckets         (type-1 — exact canonical match)
  Tier 1:  Jaccard over n-grams (type-2 — minor renames)
  Tier 2:  APTED tree edit      (type-3 — structural with gaps)
  Tier 3:  containment fallback (type-3 — one-is-subset cases)
  Tier 4:  BehaviouralSimilarity (type-4 — observable I/O; experimental)
```

ORM-aware dedup would slot in either:

- **Below Tier 1** — by feeding the n-gram fingerprint enriched
  tokens that already encode the *operation* rather than the *call
  shape*. Cheap, broad, but coarse.
- **As Tier 5** — a new dedicated semantic scorer that runs on pairs
  rejected by all earlier tiers. Expensive per pair, but only invoked
  on Tier-1-rejected candidates.

The plan below assumes a hybrid: a cheap canonicalisation pass
(Tier-1-friendly) plus a richer semantic summary (Tier-5).

---

## 3. The full option matrix

Six options, ranked roughly from cheapest+narrowest to most
ambitious. Each entry lists scope, implementation cost, accuracy
ceiling, and risk profile. Read top-to-bottom; the recommended
rollout (§6) picks 1+2+3 first.

### Option 1 — DB-call canonical-token rewrite (S, low risk)

**What.** During normalisation, rewrite recognised DB calls to a
canonical token shape:

```
User::find($id)        →  __DB_FIND__("User", __VAR__)
$em->find(User::class, $id)
                       →  __DB_FIND__("User", __VAR__)
DB::table('users')->find($id)
                       →  __DB_FIND__("users", __VAR__)
$pdo->query("SELECT * FROM users WHERE id = …")
                       →  __DB_QUERY__("SELECT", "users", …)
$mysqli->query("SELECT * FROM users WHERE id = …")
                       →  __DB_QUERY__("SELECT", "users", …)
```

**How.**
- Extend `CanonicalizingVisitor` (`src/Normalization/Normalizer.php`)
  with a `canonicalizeDbOps()` pass.
- Use a known-symbol lookup (Laravel `User::find`, Doctrine
  `EntityManager::find`, Eloquent `Model::find`, Cake ORM, etc.) —
  hard-coded for v1, profile-driven later.
- For raw SQL strings: parse with `greenlion/php-sql-parser`
  (already battle-tested) and emit a tuple
  `(verb, primary_table, params)`.

**Reach.** Catches the *shape* of a DB op. A `find` and a `query()`
that both load by-id can cluster.

**Limits.** Doesn't catch the read+modify+save trinity (option 2).
Doesn't span dialects (mysqli vs PDO use different fetch APIs).

**Effort.** S (~1 week). Mostly mechanical normalisation extension
plus a small symbol table; tests are easy.

**Risk.** Low — a new normalisation mode (`db-aware`) gates it so
existing users keep their current behaviour. Profile knob:
`db_aware: { extensions: [pdo, mysqli, doctrine, eloquent], …}`.

### Option 2 — Trinity-collapse: read → mutate → save (M, medium risk)

**What.** Detect the canonical CRUD trinity:

```php
$x = Model::find($id);   // read
$x->name = 'Bob';        // mutate
$x->save();              // write
```

…and rewrite it as a single canonical token:

```
__DB_UPSERT__("Model", { name: __VAR__ })
```

**How.**
- Extend `BlockExtractor` (or add a post-extraction pass) with a
  small dataflow analyser that tracks `$x` from the find/load to the
  save. Match read↔mutate↔save trinities by variable identity within
  the block.
- Equivalence classes for read (`find`, `findOrFail`, `findOne`,
  `where()->first()`, raw `SELECT … WHERE id =`).
- Equivalence classes for write (`save`, `flush`, `update`, raw
  `UPDATE`).
- Mutation is property assignment OR setter call (`setName('Bob')`).

**Reach.** Closes the user's worked example: an ORM upsert and a
raw-SQL `UPDATE` on the same fields cluster.

**Limits.** Brittle on multi-statement bodies that interleave the
trinity with unrelated logic. Requires conservative dataflow — false
matches are worse than misses.

**Effort.** M (~2-3 weeks).

**Risk.** Medium — the dataflow walk is the trickiest part. A failed
match silently falls back to AST scoring (no regression).

### Option 3 — Behavioural call-graph similarity (M, low risk)

**What.** Already partially shipped as `BehaviouralSimilarity` (Type-4
clones, scoring by var/call/return overlap). Extend it with:

- **DB-operation tags** on each call: a `User::find` → `db.read`,
  `$x->save()` → `db.write`, etc. A function with one `db.read` and
  one `db.write` looks the same as another with `db.read` then
  `db.write`, regardless of the libraries used.
- **Tag-frequency Jaccard** in the scorer instead of (or alongside)
  raw call-name Jaccard. Calls are bucketed by tag before comparison.

**How.**
- Add `Phpdup\Semantic\DbOperationTagger` — a visitor that produces
  the `op_tag → count` summary per block.
- Extend `BehaviouralSimilarity` with a tag-Jaccard pass weighted
  alongside the existing var/call/return passes.

**Reach.** Catches arbitrary library/extension swaps for any tagged
operation set. Generalises beyond DB to HTTP, file IO, logging.

**Limits.** Coarse — two DB-reading functions cluster even if they
return different things, unless return-type signal is also wired
(another extension, ~1 week).

**Effort.** M (~1-2 weeks).

**Risk.** Low — sits in the existing tier-4 scaffold; gated behind
`--type4` until calibrated.

### Option 4 — Symbol equivalence classes (M, medium risk)

**What.** A user-extensible *equivalence-class registry*: declare
that `pdo->query`, `mysqli->query`, `pg_query`, and `Db::query` all
mean the same thing. Profiles ship the common ones; users extend in
config.

**How.**
- New `profiles/db-symbols.json` mapping FQCN+method or function name
  → `(canonical_op, role)`.
- Plug into the existing `PluginRegistry` (V.B.3, already shipped) so
  custom symbol tables land via `phpdup.json -> db_symbols: [...]`.
- During normalisation, any call whose symbol matches a registry
  entry rewrites to a canonical placeholder:
  `mysqli_query($conn, $sql)` → `__DB_QUERY__($sql)`.

**Reach.** Catches *any* library swap — DB, HTTP, file, anything the
user adds to the registry.

**Limits.** Requires a real registry to be useful. Works best with
options 1+2 layered on top.

**Effort.** M (~1.5 weeks for the registry + a stock DB / HTTP /
filesystem profile shipped by default).

**Risk.** Medium — wrong canonicalisations damage clustering. Mitigation:
the registry is ranked by confidence; only high-confidence entries
collapse, others contribute a *boost* signal to scoring.

### Option 5 — IR (intermediate representation) lift (L, medium-high risk)

**What.** Lift each block to a tiny IR before scoring:

```
Block (PHP AST) → IR (canonical operation graph) → fingerprint
```

The IR has nodes for:

- **db.read**(table, predicate, fields)
- **db.write**(table, predicate, values)
- **http.call**(method, url, body)
- **assign**(target, value)
- **branch**(condition, then-IR, else-IR)
- **call**(symbol, args)

PHP-specific syntax (the difference between `$x->y` and `$x['y']`)
is erased; semantically identical operations get identical IR.

**How.**
- New `Phpdup\Ir\` namespace with a Lifter (PhpParser visitor → IR
  nodes), a printer (IR → tokens), a scorer (IR-to-IR distance).
- Initially limited domain (DB only) to keep the surface tractable.
- Incremental — IR-aware mode is opt-in
  (`--scorer=ir`).

**Reach.** Strongest — once the IR is right, the scoring is trivial.

**Limits.** Building the IR correctly is the hard part. Each new
operation domain (HTTP, file, queue, …) needs its own lifter.

**Effort.** L (~1-2 months for DB-only IR; another L per additional
domain).

**Risk.** Medium-high — investment is large; payoff depends on IR
fidelity. Risk-mitigated by:
- Fall back to AST scoring on lift failure (Lifter returns null).
- Keep the AST fingerprint as a side-by-side signal so callers can
  compare.

### Option 6 — ML-learned similarity (XL, high risk)

**What.** Train a model on human-labelled clone pairs that includes
ORM ↔ raw-SQL examples. Use the existing `Phpdup\Ml\MlClient` (already
shipped as a sidecar contract) to score pairs.

**How.**
- Curate a labelled corpus of paired snippets (currently the costliest
  step).
- Train a contrastive-learning model on AST + token-stream features.
- Sidecar HTTP service (already speced in `docs/ML.md`).

**Reach.** Whatever the model can learn from the labels.

**Limits.** Garbage-in-garbage-out. Without a serious labelling
effort the model just memorises specific patterns.

**Effort.** XL (~3-6 months including dataset curation).

**Risk.** High — the dataset is the project; everything else is
plumbing. Defer until 1-3 are shipped and we have real-user data on
which patterns are most-missed.

---

## 4. Cross-cutting concerns

These apply to every option:

### 4.1 SQL parser dependency

Options 1, 2, 4, 5 all need to parse raw SQL strings. Candidates:

| Library | Pros | Cons |
|---|---|---|
| `greenlion/php-sql-parser` | Pure PHP, MIT, mature, table-extracting API. | Slow on large queries; doesn't handle every dialect quirk. |
| `phpmyadmin/sql-parser` | Battle-tested in phpMyAdmin; excellent dialect coverage. | Heavyweight; pulls in a tokenizer + parser stack. |
| Hand-rolled regex | No deps. | Brittle on anything beyond `(SELECT|INSERT|UPDATE|DELETE) FROM <table>`. |

**Recommendation.** `phpmyadmin/sql-parser` for accuracy; `greenlion`
as a fallback. SQL parsing is a *hot path* during normalisation, so
cache the parsed result on the block alongside the AST.

### 4.2 Profile-driven configuration

ORM dedup is project-specific — Laravel Eloquent vs Doctrine vs
DBAL each need their own symbol mappings. Reuse the existing
`Phpdup\Cli\ProjectProfileDetector` (V.A.2) and ship:

```
profiles/db-aware-laravel.json
profiles/db-aware-symfony.json
profiles/db-aware-doctrine.json
profiles/db-aware-cake.json
```

Each one lists the recognised entry points + their canonical-op
mapping (option 4).

### 4.3 False positives — the fundamental risk

Aggressive canonicalisation makes more pairs cluster. That includes
*wrong* pairs. Two mitigations:

- **Confidence per cluster carries provenance.** When option 4
  collapsed via a registry entry contributes to a cluster, the
  cluster's `confidence` is decremented (we're trusting the registry).
  Reporters surface the provenance so reviewers see why a cluster
  formed.
- **Tier-1 still runs unchanged.** Aggressive ORM clustering is
  always a *new* tier; existing AST-based clusters are unaffected.
  Users opt in via `--db-aware` / `--scorer=ir`.

### 4.4 Test data for validation

Need a labelled corpus where we *know* the expected pairings.
Suggest:

- 30 hand-written ORM/raw-SQL pairs for unit tests.
- Real-world: `vendor/laravel/framework/src` (Eloquent), `vendor/
  doctrine/orm/lib` (Doctrine). Run phpdup against them, hand-score
  the false positives.
- Synthetic: extend `Phpdup\Testing\FuzzCorpusGenerator` to emit
  ORM/raw-SQL variants of the same operation.

---

## 5. Honest comparison vs out-of-tree alternatives

| Feature | phpdup today | Option 1+2 | Option 3 | Option 5 | tools that do this today |
|---|---|---|---|---|---|
| AST-based type-1/2/3 | ✓ | ✓ | ✓ | ✓ | phpcpd, pmd-cpd, simian |
| Anti-unification → suggestion | ✓ | ✓ | ✓ | ✓ | (phpdup unique) |
| ORM ↔ raw-SQL clustering | ✗ | partial | ✓ | ✓ | none in PHP space |
| Library swap detection | ✗ | partial | ✓ | ✓ | none |
| Cross-language semantic clones | ✗ | ✗ | ✗ | partial via IR | DECKARD (research) |

**Honest read**: no PHP tool does ORM-aware dedup today. The closest
research-grade work is academic (cross-language semantic clones).
phpdup shipping options 1+2+3 would meaningfully exceed the field.

---

## 6. Recommended rollout

Three phases, each shippable independently:

### Phase 1 (S, immediate) — Option 1 + Option 4 lite

- Ship `db-aware` normalisation mode.
- Ship a profile-driven symbol registry with stock entries for
  Laravel Eloquent, Doctrine ORM, Eloquent QueryBuilder, PDO,
  mysqli, pg_*.
- One new pattern tag: `db-op` so reporters surface DB-shaped
  clusters.

This is mostly mechanical work — extending an existing mode plus
adding a config key plus a normalisation pass.

### Phase 2 (M, follow-up) — Option 2 trinity-collapse + Option 3 tags

- Detect read→mutate→save trinities.
- Extend `BehaviouralSimilarity` with op-tag Jaccard.
- Update fuzz corpus + golden snapshots.

This is the slice that closes the user's worked example.

### Phase 3 (L, strategic) — Option 5 IR

- Lift to an explicit IR.
- Score IR-to-IR distance.
- Add HTTP and file domains.

This is research work. Only commit once Phase 1+2 land and we have
real user data on the long-tail patterns Phase 1+2 don't catch.

---

## 7. What I'm asking for

A decision on **which phases to start**, not full sign-off. Phase 1
is implementable in ~1 week with the existing scaffolding (modes,
profiles, plugin registry, DataflowSummarizer). Phase 2 builds on
Phase 1's output. Phase 3 is a separate strategic discussion.

If approved, the implementation sequence matches §6 — each phase
ships behind a feature flag (`--db-aware`, `--scorer=ir`) so the
default behaviour stays stable for current users.
