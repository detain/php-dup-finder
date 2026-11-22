# `phpdup serve` — REST API server

Run phpdup as a minimal HTTP service. Useful for in-house dashboards,
CI integrations, or the [interactive playground](#playground) wired
to a hosted backend.

```bash
# Default bind (loopback) and default port (8080)
phpdup serve
```

The server uses `stream_socket_server` + a hand-rolled HTTP/1.1 parser
— no ReactPHP / Amp dependency. For high-traffic deployments, swap
in a more capable runtime (Roadrunner, FrankenPHP, FPM behind nginx)
and reuse `Phpdup\Server\Application` directly.

Binding a non-loopback address (e.g. `--host 0.0.0.0`) is a guarded
step, not the default: the server refuses to start unless both
`--bind-public` and `--token` are given. See
[Default bind](#default-bind) for the exact refusal and the full
exposure command.

## Routes

| Method | Path                    | Body                              | Returns |
|--------|-------------------------|-----------------------------------|---------|
| GET    | `/healthz`              | —                                 | `text/plain` `"ok"` |
| POST   | `/analyze`              | JSON: `{"paths":["src","lib"]}`   | `application/json` (full report) |
| POST   | `/jobs`                 | JSON: same as `/analyze`          | `202` + `{"job_id":"…"}` |
| GET    | `/jobs/{id}`            | —                                 | `application/json` (status + result) |
| GET    | `/api/jobs`             | —                                 | `application/json` (all jobs, status list) |
| GET    | `/api/jobs/{id}`        | —                                 | `application/json` (status only) |
| GET    | `/api/jobs/{id}/result` | —                                 | `application/json` (result file bytes) |

These seven method+path pairs are the **fixed allow-list** enforced at
the HTTP layer (`ServeCommand::ROUTE_ALLOW_LIST`); `{id}` is a hex job
id (`[0-9a-f]+`). Anything outside the table answers 404 *before* the
body is read, so unknown routes never consume request bytes. The three
`/api/*` legs are the async polling surface: `GET /api/jobs`
reconciles and lists every retained job, `GET /api/jobs/{id}` returns
one job's status row, and `GET /api/jobs/{id}/result` serves the
worker's full result file for a completed job.

The `/jobs` endpoint runs synchronously in the demo build but its
contract is async — `POST /jobs` returns 202 with an id, then the
client polls `GET /jobs/{id}` until `status === 'completed'`. Wiring
it to a real worker pool is straightforward (delegate to
`WorkerPool::run()` and persist state in `JobQueue`).

## Example

```bash
$ curl -s http://localhost:8080/healthz
ok

$ curl -s -X POST http://localhost:8080/analyze \
       -H 'Content-Type: application/json' \
       -d '{"paths":["./src"]}' | jq '.summary'
{
  "files": 152,
  "blocks": 4023,
  "parse_errors": 0,
  "clusters": 18,
  "duplicated_lines": 412,
  "total_impact": 1240
}
```

## Security

### Default bind

The server binds to `127.0.0.1:8080` by default. Loopback targets —
`127.0.0.1`, any `127.x.x.x` address, and the name `localhost` — are
accepted by the guard with no extra flags and no token:

```bash
# Loopback only (default) — no token required
phpdup serve
```

Anything else is refused **at startup**, before any socket is
created, so a copied-and-pasted `--host 0.0.0.0` can never silently
open a network port:

```bash
$ phpdup serve --host 0.0.0.0
phpdup serve: refusing to bind to non-loopback host '0.0.0.0' without --bind-public.
```

Exposing the server beyond loopback is the deliberate, guarded case,
and it takes two opt-ins. `--bind-public` on its own is refused the
same way, because an unauthenticated public endpoint hands anyone on
the network everything under the server's `--serve-root` (by default,
its launch directory):

```bash
$ phpdup serve --bind-public
phpdup serve: --bind-public requires --token to be set (no authentication is enforced by default).
```

The complete public-exposure command supplies all three parts — a
non-loopback `--host` to pick the address, `--bind-public` to lift
the guard, and `--token` to authenticate every request:

```bash
# Public bind — non-loopback --host + --bind-public + --token
phpdup serve --host 0.0.0.0 --bind-public --token YOUR_SECRET_TOKEN
```

Note that `--bind-public` only disarms the guard; it does not change
the bind address by itself — without an explicit non-loopback
`--host` the server still comes up on `127.0.0.1`.

Exposing the server also puts `--serve-root` in play: it defaults to
the directory the server was launched from, and every `paths[]`
entry a client posts is confined beneath it (see
[Path confinement](#path-confinement) below). Start a public server
from — or point `--serve-root` at — exactly the tree you intend it
to be able to scan, and treat `--token` as non-negotiable: with a
token set, every request without a matching bearer header is
answered `401` before any other processing.

### Bearer token authentication

When `--token` is set, every request must include:

```
Authorization: Bearer YOUR_SECRET_TOKEN
```

Requests without a valid bearer token receive a `401 Unauthorized`
response before any other processing occurs.

### Path confinement

When `--serve-root` is set (default: the working directory), every
path in `paths[]` is validated:

1. **No absolute paths** — paths starting with `/` are rejected with
   `400 Bad Request`.
2. **No `..` traversal** — paths containing `..` are rejected.
3. **realpath containment** — each path is resolved via `realpath()`
   and must fall within `realpath(--serve-root)`. Symlinks that point
   outside the root are also rejected.

This prevents phpdup from reading arbitrary filesystem paths through
the HTTP API, even if a client bypasses the above checks.

### Bounded job queue & memory management

The in-memory job queue (`JobQueue`) is bounded to prevent unbounded
memory growth during sustained use:

- **Capacity cap** — a maximum of `MAX_JOBS` (100) entries are retained,
  and the cap is a hard ceiling: at capacity an `enqueue` makes room by
  displacing the oldest displaceable row — terminal (completed/failed)
  first, then pending, then running. Every capacity displacement is
  recorded as a visible note: the admitting request's `202` body carries
  an `evicted` list naming the displaced ids and the status each held
  when dropped (key absent when nothing was evicted), and polling an
  evicted id afterwards answers `404` with that eviction note rather
  than a bare "job not found".

- **TTL eviction** — completed and failed jobs older than
  `JOB_TTL_SECONDS` (default: 1 hour) are automatically purged on every
  mutating operation (`enqueue`, `markRunning`, `markCompleted`,
  `markFailed`). This reclaims memory even when the queue is well below
  capacity.

- **Administrative cleanup** — `JobQueue::cleanup()` force-evicts all
  terminal-state jobs regardless of TTL, for programmatic manual
  intervention. It is method-only: there is **no** `/jobs/cleanup` HTTP
  route — the table under "Routes" above is the complete, fixed
  allow-list.

- **Result summaries only** — job results stored are `{files, blocks,
  clusters, config}` summaries extracted from the full report. The raw
  JsonReporter output is discarded immediately after the summary is
  extracted, keeping per-job memory usage small and predictable.

These guarantees apply to the built-in single-process server. If you
swap `JobQueue` for a persistent backend (e.g. Redis) you retain the
same interface but the capacity/TTL semantics depend on your storage
choice.

### Async job posture

`POST /jobs` always answers `202` with a `job_id`. What happens next
depends on whether the background worker was spawned at startup — the
serve command prints `worker spawned (pid …)` when it has, or falls
back to `running in sync mode` when it has not.

- **Ack at accept, not at completion.** The daemon writes a `RUN` frame
  to the worker and waits only for the worker's `OK <job_id>` accept-ack
  — written the instant the frame is admitted, before the analysis
  starts. A job that runs longer than any socket timeout is therefore
  never mistaken for a dispatch failure.
- **Buffered admission is still admission.** A frame fully written to a
  live worker that is busy behind an earlier job answers nothing inside
  the ack window (`Application::DISPATCH_ACK_TIMEOUT_SECONDS`, 5 s). The
  request still returns `202` and the row honestly stays `pending` until
  the worker's late `OK` flips it to `running`.
- **Terminal events are observed, not inferred.** The dispatch
  connection is held open after the exchange, and every status/result
  read asks it for the worker's terminal event: a late `OK`, an
  `ERR`/`FAIL` frame (the row fails with the worker's own reason), or
  the close itself — a worker that hangs up without publishing is
  reported as dead on the very next read. Completion is primarily the
  result file landing in the private results directory; every read
  reconciles the queue row against that file first.
- **The absence deadline is a wedge backstop.**
  `Application::ASYNC_RESULT_DEADLINE_SECONDS` (300 s) fails a still-live
  row whose result has not appeared while its channel stays *open yet
  silent* (a wedged worker). It is not the normal completion path, and
  it does not fire when the worker's death or verdict was already
  observed.
- **Dispatch failures stay honest.** A frame the socket layer cannot
  admit at all (connect refused, short write, hang-up without any
  reply) fails that job's row and answers `500` — no silently lost job.

In sync mode (no worker) the `POST` runs the analysis inline and
settles the row before answering `202`; the async legs above simply do
not apply.

## Concurrency posture

`phpdup serve` is a **single-process, serial accept loop**: it handles
one connection at a time, with the background `phpdup-worker` process
taking async jobs (`POST /jobs`) off the HTTP thread. The alternatives
were fork-per-connection / worker-per-connection and an event loop;
both were evaluated and rejected here (once-S43, php-dup-finder-once-047):
the pipeline already has one warm worker for the heavy path, this
daemon is built for dev / small-team use, and per-connection forks
would multiply PHP process lifetimes without adding throughput to the
one place that is actually expensive (the analysis). The serial
posture is a decision — its knobs and its limits are below.

Because the loop is serial, every inbound socket is bounded so a single
bad client cannot starve the others:

| Knob | Value | Home |
|------|-------|------|
| Listen backlog (kernel accept queue for connections arriving while a client is being served) | 64 | `ServeCommand::LISTEN_BACKLOG` |
| First-byte grace — an accepted client that sends **no request byte at all** (netcat half-open, classic slow-loris) is released with 408 | 500 ms | `ServeCommand::CLIENT_FIRST_BYTE_TIMEOUT_MS` |
| Per-read deadline — bytes started then stalled; the window restarts on every chunk, so a slow-but-live client is never cut off mid-request | 5 s | `ServeCommand::CLIENT_READ_TIMEOUT_SECONDS` |
| Header size cap | 64 KiB → 431 | `ServeCommand::handleClient()` |
| Body size cap | 16 MiB → 413 | `Application::MAX_BODY_BYTES` |

**Slow-loris immunity (`/healthz`).** A health probe keeps answering
while other clients stall: a half-open connection holds the loop for at
most the 500 ms first-byte grace, so a `GET /healthz` fired behind a
stalling client returns 200 with sub-second worst-case latency — verified
live against a running `phpdup serve` in this step's exec-phase
acceptance probe (hold one socket accepted-but-silent, fire a fresh
`GET /healthz` inside the ≤500 ms grace window, time the reply: 200 in
under a second); a client that starts a request and
dribbles is released with 408 at the 5 s read deadline. Fresh sockets
are never refused in either window — they ride the backlog while at
most 64 are queued.

**Known-serial limitation + sizing guidance.** The bounds limit
*stalled clients*, not *busy handlers*: exactly one request is in
flight at a time, so a slow `/analyze` (a mid-size tree takes seconds)
delays every queued client behind it until it completes — there is no
per-request concurrency to size against.

| Deployment | Guidance |
|------------|----------|
| Dev laptop / small team, loopback bind, occasional analyze | the serial server is exactly right; nothing to tune |
| CI health probe + a few humans | fine — the 64-socket backlog absorbs bursts; keep big analyses on `POST /jobs` so the worker takes them off the HTTP thread |
| Many users, sustained API traffic, or public-network exposure | do **not** tune this loop toward it — lift `Phpdup\Server\Application` into a capable runtime (FrankenPHP, RoadRunner, php-fpm behind nginx) and front it with TLS + rate limiting (see General advice below) |

## General advice

For non-localhost deployments, put a reverse proxy (nginx / Caddy)
in front and apply the usual hardening: TLS termination, rate
limiting, and allow-list of source IPs where possible.

## Playground

The intended deployment for the public phpdup playground is a small
Cloudflare-Pages / Vercel front-end that posts user-pasted PHP to
this server's `/jobs` endpoint and renders the resulting JSON via
the browser-side reporter. The `Application` class is transport-
agnostic, so the same code can run under FrankenPHP for a single-
binary deployable artifact.

## ML pair-scoring sidecar contract (outbound, `--ml-pair-url`)

These routes are **not** served by `phpdup serve`. They are the HTTP
contract this daemon's analysis pipeline dials **out** to when
`--ml-pair-url` is set, via `Phpdup\Ml\MlPairClient` — the last
clustering tier (semantics and per-class wiring status:
[ML.md](ML.md)). A runnable stdlib reference server for both routes
ships at [`../tools/ml-sidecar-example.php`](../tools/ml-sidecar-example.php).

### `POST /score-pair` — one pair

Request body is ONE combined feature vector: the `feature_version`
envelope marker followed by EXACTLY the 11 fields of
`Phpdup\Ml\PairFeatures::WIRE_KEYS`, in that order (booleans travel as
JSON `true`/`false`; `once-S48` made the const the wire's single source
of truth, and the unit suite pins the payload against it):

```
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
```

Response: `200` + `{ "similarity": 0.91, "confidence": 0.78 }` — both
numbers must be finite and within `[0, 1]` (they pass
`MlClient::coerceUnitFloat()` before the tier compares `similarity`
with `--ml-pair-threshold`; engine-S20).

### `POST /score-batch` — many pairs, one request

Request: `{ "pairs": [ <combined vector>, … ] }` — one vector per
requested pair, in request order (the parallel path's `PairScoreWorker`
uses this first and retries individually on a batch miss). Response:

```
{ "results": [ { "similarity": 0.91, "confidence": 0.78 }, [0.55, 0.9] ] }
```

`results` is contractually ORDERED: entry *i* answers pair *i*, and it
must be a sequential JSON list of exactly as many entries as were
requested — a miscounted or non-list response is voided whole because
the client consumes it positionally (engine-S20). Each entry is either
a named object or a positional list whose first two elements are
`similarity` then `confidence` (trailing fields are ignored). A
malformed entry — not an array, too short, or numbers failing the
`[0, 1]` fence — becomes a null slot: that pair receives no ML-tier
edge while its neighbours keep their verdicts.

### What the sidecar sees on the wire

Every endpoint URL passes the shared SSRF gate (`MlClient::gateUrl()`,
engine-S19a/b): http(s) only, canonical-IP-literal-or-resolving host,
and every candidate address cleared of the refused spaces (loopback,
RFC 1918, CGNAT, link-local, ULA, IPv4-mapped, multicast/reserved/
broadcast, localhost deny-list) — so today a loopback or private-range
sidecar URL is refused at admission; the planned opt-in
`--ml-pair-allow-private` (once-S54, default off) does not exist yet.
The gate-checked addresses are then the dialled addresses (curl
`CURLOPT_RESOLVE`; stream rewrite + explicit `Host` + `ssl.peer_name`),
the gate decision is computed once per client instance and one reused
keep-alive curl handle carries later requests (engine-S21), and any
response larger than 1 MiB (`MlClient::MAX_RESPONSE_BYTES`) or any
non-2xx status is treated as a null verdict.
