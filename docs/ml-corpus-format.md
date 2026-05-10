# ML pair-similarity corpus format

The ML pair-similarity model (option 6 of [`docs/plans/orm-db-semantic-dedup.md`](plans/orm-db-semantic-dedup.md))
expects a labelled corpus of `(blockA, blockB, label)` triples. This
document specifies the expected on-disk format so the Python sidecar
training pipeline and the PHP `MlPairClient` evolve from the same
contract.

## File format

The corpus is a JSON Lines file (`*.jsonl`). Each line is one
labelled pair:

```json
{
    "id":       "eloquent-vs-pdo-find-by-id-001",
    "block_a":  "<?php function f($id) { return User::find($id); }",
    "block_b":  "<?php function f($pdo, $id) {\n    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');\n    $stmt->execute([$id]);\n    return $stmt->fetch();\n}",
    "label":    "duplicate",
    "category": "orm-vs-raw-sql",
    "notes":    "Eloquent find vs PDO prepare/execute/fetch on the same table"
}
```

### Fields

| Field      | Type     | Description                                                                                  |
|------------|----------|----------------------------------------------------------------------------------------------|
| `id`       | string   | Stable identifier for citing this row in the dataset. Should not change once assigned.       |
| `block_a`  | string   | A complete PHP source snippet (must parse standalone). The model trains on the *block*, not the file. |
| `block_b`  | string   | As above, the second snippet of the pair.                                                    |
| `label`    | enum     | One of `duplicate`, `partial`, `unrelated`. See "Label semantics" below.                     |
| `category` | string   | Human-readable bucket (e.g. `orm-vs-raw-sql`, `query-builder-vs-fluent`, `mysqli-vs-pdo`).   |
| `notes`    | string   | Free-form annotation explaining *why* the label was chosen — feeds back into label review.   |

### Label semantics

- **`duplicate`** — the two blocks perform the same operation
  observable from outside the function (same table, same shape,
  same effect), differing only by library/extension/dialect.
- **`partial`** — the two blocks share a meaningful subset of
  their behaviour (one extra read, one extra branch) but diverge
  in ways a refactor would not paper over.
- **`unrelated`** — the two blocks share little observable
  behaviour. Includes near-misses where one block touches the
  database and the other does not.

## Example pairs we want covered

The plan calls for "30 hand-written ORM/raw-SQL pairs for unit
tests" plus a real-world sweep of `vendor/laravel/framework/src`
and `vendor/doctrine/orm/lib`. Concretely the corpus should
include at minimum:

- Eloquent `Model::find` ↔ Doctrine `$em->find` ↔ PDO
  `prepare/execute/fetch`.
- Laravel `DB::table('x')->where(...)->update(...)` ↔ raw
  `UPDATE` ↔ Doctrine `EntityManager` upsert trinity.
- mysqli object vs procedural vs `pg_query_params` for the same
  read/write.
- Negative examples: a non-DB function that *looks* DB-shaped
  (e.g. `Cache::find`) so the model learns the difference.

## Producing the corpus

The repo ships a `Phpdup\Testing\FuzzCorpusGenerator` for synthetic
ORM/raw-SQL variants. Real-world examples should be hand-curated
from public ORMs and committed under `tests/Fixtures/ml-corpus/`
(directory not yet created — appears once we have material).

Until the model is trained, `MlPairClient::score()` is fully
optional: with no `ml_pair_url` configured (or the service
unreachable), phpdup falls back to the AST-level scoring tiers
without changing observable behaviour.
