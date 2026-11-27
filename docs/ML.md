# ML scoring sidecar

phpdup ships two HTTP clients for external ML models:
`Phpdup\Ml\MlPairClient` (pair similarity — **wired into the pipeline
today** via `--ml-pair-url`) and `Phpdup\Ml\MlClient` (cluster safety +
anomaly — **not wired**, see Wiring below). The models themselves live
out of process (a Python service, or the PHP reference sidecar at
[`../tools/ml-sidecar-example.php`](../tools/ml-sidecar-example.php));
phpdup only consumes verdicts over the contracts below.

## Cluster safety: `POST /score` (`MlClient`)

```
POST /score
Content-Type: application/json

{
  "cluster_id":   "F2bc8447c61fba5ae",
  "similarity":   1,
  "members":      2,
  "holes":        4,
  "pattern_tags": []
}

200 OK
{ "safety": 0.71, "anomaly": 0.12 }
```

The `cluster_id` above is real output, not a placeholder: analysing a
two-file near-clone fixture emits exactly this id at JSON schema 1.6.
Cluster ids are content-derived — `Phpdup\Reporting\FindingId` builds
them as `F` + the first 16 hex digits of a sha256 over the cluster's
sorted element digests — so the same clone content always carries the
same id. (The response numbers are illustrative: no model is
published yet.)

`safety` is in `0..1` — same semantics as `Phpdup\Reporting\SafetyScorer`'s
heuristic score. `MlClient::score()` returns `null` whenever it cannot
produce a trusted verdict — transport errors, non-2xx responses, and
(engine-S20) any response number that fails the shared closed `0..1`
fence `MlClient::coerceUnitFloat()` — so callers fall back to the
heuristic without code-path branching.

`anomaly` is in `0..1` — interpreted as "this cluster looks
suspiciously different from typical training-set duplicates." A
high anomaly score on a cluster with high `safety` is a strong
"this isn't really a duplicate" signal.

## Wiring — today, per class

- **`MlPairClient` — wired.** Setting `--ml-pair-url` makes the pair
  model the very last clustering tier, after structural-hash, AST
  Jaccard + TED, containment and IR have all rejected a pair. The
  serial path runs it inside `Phpdup\Clustering\Clusterer` (scored via
  `MlPairClient::score()`); the parallel path runs
  `Phpdup\Parallel\PairScoreWorker`, which dials `scoreBatch()` first
  and retries individually after a batch miss. `ClusterStage` builds
  both from the config. A pair is accepted when its `similarity` is at
  or above `--ml-pair-threshold` (default 0.80, `Config::$mlPairThreshold`).
  Any null verdict — disabled, refused URL, transport error,
  fenced response — means "no ML-tier edge for this pair"; the run
  continues on the AST tiers, so an unreachable sidecar never breaks
  analysis.
- **`MlClient` — not wired.** `MlClient::score()` has no pipeline call
  sites. The class is still load-bearing in production:
  `MlClient::isAllowedUrl()` validates `--ml-pair-url` at the CLI
  admission door (and scores the cluster tier when wired), while the
  Config/`ConfigLoader` admission seams for the pair tier ride the
  dedicated shape-only door `MlPairClient::pairUrlAdmissionError()`
  (once-S54, below); `MlClient` owns the shared transport
  policy both clients ride (`gateUrl()`, `coerceUnitFloat()`,
  `MAX_RESPONSE_BYTES`, the address-pin helpers). Whether the cluster
  tier gets wired to it or the client gets deleted is the per-class
  wire-or-delete ledger adjudication of plan step **once-S89** (shared
  with once-S32 / engine-S31) — until that step lands, the `/score`
  contract above is the reserved shape and this sentence is the honest
  status.

The old narrative "wiring is a small change in `Ranker::rank()`"
predated the pair tier and contradicted itself; it is retired here.

## Transport posture (shared by both clients, landed code)

- **URL gate** (`MlClient::gateUrl()`, engine-S19a/S19b): http(s)
  only; the host must be a canonical IP literal (inet_pton syntax —
  the `inet_aton` shorthands are refused) or resolve to at least one
  A/AAAA record, fail-closed; every candidate address must clear the
  refused-space bit checks (loopback, RFC 1918, CGNAT, link-local,
  ULA, IPv4-mapped, multicast/reserved/broadcast, plus the localhost
  deny-list). The **cluster** client `MlClient` dials behind exactly
  this gate, unchanged.
- **Pair-tier doors** (once-S54, php-dup-finder-once-076/083/084):
  the pair client splits validation from resolution. **Admission**
  (`MlPairClient::pairUrlAdmissionError()`, ridden by `Config` and
  `ConfigLoader::validate()`) is shape-only and performs ZERO resolver
  calls — an attacker-planted `.phpdup.json` can no longer turn
  config validation into a blocking DNS lookup (the once-076
  stall/exfil channel). It still refuses every canonical IP literal
  the bit matrix condemns, `file://`-class schemes, inet_aton
  shorthands, bracket-pairing violations, grammar-invalid names, and
  `localhost`/`*.localhost` by name; a bare hostname is admitted on
  shape and decided at the dial. **Dialing**
  (`MlPairClient::pairEndpointDecision()`) resolves a name once per
  client (engine-S21 memo and the pinned-transport posture kept) and
  fail-closes on zero records or any refused record — re-classifying
  every resolved address, which also adds the NAT64 `64:ff9b::/96`
  embedded-IPv4 recursion the pre-existing shared matrix predates
  (once-083: `64:ff9b::7f00:1` is `127.0.0.1` wearing IPv6 bytes).
  The documented local-sidecar topology is no longer refused outright
  (once-084): the opt-in `ml_pair_allow_private` (CLI:
  `--ml-pair-allow-private`, default off, root config only — a
  subtree `.phpdup.json` carrying it is refused loudly) admits the
  private families (loopback, RFC 1918, CGNAT, link-local, ULA,
  embedded/NAT64 forms thereof), while 0.0.0.0/8, 192.0.0.0/24,
  198.18.0.0/15 and multicast/reserved stay refused either way. Every
  refusal message names the space AND the opt-in's two spellings.
- **Pinned dialing** (engine-S19b): the checked addresses are the
  dialled ones. The curl transport preloads them via
  `CURLOPT_RESOLVE` (`MlClient::curlPinEntries()`); the stream
  transport — whose wrapper has no resolve-pinning option — rewrites a
  named host to the first checked address, carries the original
  authority in an explicit `Host` header, and keeps https certificate
  identity via `ssl.peer_name` (`MlClient::pinnedStreamRequest()`).
- **One gate decision per client instance** (engine-S21): the memo
  covers `/score-pair` and `/score-batch` alike because the path never
  enters the decision; the pair client additionally reuses one fork-
  guarded curl handle, so keep-alive folds later handshakes into the
  first one.
- **Response cap** (engine-S19b/engine-015): bodies larger than
  `MlClient::MAX_RESPONSE_BYTES` (1 MiB) are refused mid-transfer on
  both transports (write-callback abort / bounded read).
- **Verdict fence** (engine-S20): every verdict number —
  `similarity`, `confidence`, `safety`, `anomaly`, named or positional
  — must clear `MlClient::coerceUnitFloat()`: numeric, finite, in
  `0..1`. INF, NaN, booleans, arrays and out-of-domain values void
  the verdict instead of laundering through a cast.

## Pair similarity: `POST /score-pair` and `POST /score-batch`

For **block pair** similarity — the ORM ↔ raw-SQL deduplication work
(option 6 of the retired ORM/DB-semantic-dedup plan; corpus format in
[`docs/ml-corpus-format.md`](ml-corpus-format.md)) — `Phpdup\Ml\MlPairClient`
POSTs the combined feature vector produced by `Phpdup\Ml\PairFeatures`
to `/score-pair` and consumes a single `{similarity, confidence}`
response. The vector is `feature_version` plus EXACTLY the 11 feature
fields of `Phpdup\Ml\PairFeatures::WIRE_KEYS`, in that order — the
const is the single source of truth for the wire (once-S48) and the
unit suite pins the emitted payload against it, so this example and
the bytes cannot drift:

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

Booleans travel as JSON `true`/`false` (once-S48). `similarity` is
intended to slot in alongside the AST-level scorers as a higher-tier
signal for ORM ↔ raw-SQL pairs the cheaper tiers reject, and it is the
number the tier actually compares against `--ml-pair-threshold`.
`confidence` is the model's self-reported certainty: the client
delivers it inside the verdict array, but no clustering decision or
reporter badge consumes it today — a "review-needed" surface is not
implemented.

`/score-batch` scores many pairs in one request — the parallel path
uses it first and falls back to per-pair `/score-pair` retries when a
batch voids:

```
POST /score-batch
Content-Type: application/json

{ "pairs": [ {<combined vector>}, {<combined vector>} ] }

200 OK
{ "results": [ { "similarity": 0.91, "confidence": 0.78 }, [0.55, 0.9] ] }
```

- Each `pairs` entry is ONE combined vector, the same shape
  `/score-pair` takes, listed in request order.
- `results` is CONTRACTUALLY ORDERED: entry *i* answers pair *i*, and
  it must be a sequential JSON list of exactly as many entries as were
  requested — a miscounted or reordered response is voided whole
  because consumers index it positionally (engine-S20, alignment).
- Each entry is either a named object (`similarity`, `confidence`) or
  a positional list whose FIRST TWO elements are those numbers
  (trailing fields are ignored). A malformed entry — not an array, too
  short, or numbers failing the fence — degrades to a null slot: that
  pair receives no ML-tier edge while its neighbours keep their
  verdicts.

`feature_version` lets the sidecar reject payloads from an
older/newer phpdup that produced a different feature shape — bump the
constant in `PairFeatures` (and `WIRE_KEYS` with it) any time the
schema changes and have the sidecar drop unknown versions to a
heuristic fallback; the reference sidecar spells "drop" as a null
verdict (`null` for `/score-pair`, a null slot inside the aligned
`results` list for `/score-batch`), which is exactly what the client
reads as "no ML-tier edge".

Runnable reference server for both routes (stdlib PHP, no composer):

```bash
php tools/ml-sidecar-example.php --port 8090
```

## Training data

The training corpus shape is left to the model author. Reasonable
features are exactly what the `/score` contract above ships, plus
optional encoding of the `signature` text. Labels come from human
review of phpdup outputs (`safety` ∈ {0.0, 0.5, 1.0} as a rough
rubric).

The corpus format the pair-scoring sidecar trains against is
documented in [`ml-corpus-format.md`](ml-corpus-format.md).
