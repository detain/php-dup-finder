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
