# ML scoring sidecar

phpdup ships a minimal HTTP client (`Phpdup\Ml\MlClient`) for an
external ML model that scores cluster safety + anomaly. The model
itself lives in a sister Python repo — phpdup only consumes its
output via a single endpoint.

## Contract

```
POST /score
Content-Type: application/json

{
  "cluster_id":   "X53edd72b",
  "similarity":   0.93,
  "members":      4,
  "holes":        2,
  "pattern_tags": ["sql-builder", "config-driven"]
}

200 OK
{ "safety": 0.71, "anomaly": 0.12 }
```

`safety` is in [0, 1] — same semantics as `Phpdup\Reporting\SafetyScorer`'s
heuristic score. The plugin can use whichever; `MlClient::score()`
returns `null` on transport errors so callers can fall back to the
heuristic without code-path branching.

`anomaly` is in [0, 1] — interpreted as "this cluster looks
suspiciously different from typical training-set duplicates." A
high anomaly score on a cluster with high `safety` is a strong
"this isn't really a duplicate" signal.

## Wiring

Today: zero — `MlClient` exists but isn't called from the pipeline.
Once a real model is published, the wiring is a small change in
`Ranker::rank()` to query `MlClient` per cluster and average the
returned `safety` with the heuristic value.

## Training data

The training corpus shape is left to the model author. Reasonable
features are exactly what the contract above ships, plus optional
encoding of the `signature` text. Labels come from human review of
phpdup outputs (`safety` ∈ {0.0, 0.5, 1.0} as a rough rubric).

## Pair similarity (option 6 of `docs/plans/orm-db-semantic-dedup.md`)

A second sidecar contract scores **block pair similarity** for the
ORM ↔ raw-SQL deduplication work. `Phpdup\Ml\MlPairClient` POSTs
the 11-field feature vector produced by `Phpdup\Ml\PairFeatures`
to `/score-pair` and consumes a single `{similarity, confidence}`
response.

```
POST /score-pair
Content-Type: application/json

{
  "feature_version":       1,
  "structural_hash_match": false,
  "ngram_jaccard":         0.42,
  "var_jaccard":           0.55,
  "call_jaccard":          0.10,
  "return_jaccard":        1.00,
  "db_tag_jaccard":        1.00,
  "ir_token_jaccard":      0.93,
  "block_size_ratio":      0.85,
  "kind_match":            true,
  "block_a_kind":          "method",
  "block_b_kind":          "method"
}

200 OK
{ "similarity": 0.91, "confidence": 0.78 }
```

`similarity` is in `[0, 1]` and is intended to slot in alongside
the existing AST-level scorers as a higher-tier signal for ORM ↔
raw-SQL pairs that the cheaper tiers reject. `confidence` is the
model's self-reported certainty; reporters can surface low-confidence
matches with a "review-needed" badge.

`feature_version` lets the sidecar reject payloads from an
older/newer phpdup that produced a different feature shape — bump
the constant in `PairFeatures` any time the schema changes and
have the sidecar drop unknown versions to a heuristic fallback.

The corpus format the sidecar trains against is documented in
[`ml-corpus-format.md`](ml-corpus-format.md).
