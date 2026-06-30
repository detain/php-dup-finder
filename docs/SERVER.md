# `phpdup serve` — REST API server

Run phpdup as a minimal HTTP service. Useful for in-house dashboards,
CI integrations, or the [interactive playground](#playground) wired
to a hosted backend.

```bash
phpdup serve --host 0.0.0.0 --port 8080
```

The server uses `stream_socket_server` + a hand-rolled HTTP/1.1 parser
— no ReactPHP / Amp dependency. For high-traffic deployments, swap
in a more capable runtime (Roadrunner, FrankenPHP, FPM behind nginx)
and reuse `Phpdup\Server\Application` directly.

## Routes

| Method | Path           | Body                              | Returns |
|--------|----------------|-----------------------------------|---------|
| GET    | `/healthz`     | —                                 | `text/plain` `"ok"` |
| POST   | `/analyze`     | JSON: `{"paths":["src","lib"]}`   | `application/json` (full report) |
| POST   | `/jobs`        | JSON: same as `/analyze`          | `202` + `{"job_id":"…"}` |
| GET    | `/jobs/{id}`   | —                                 | `application/json` (status + result) |

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

The server binds to `127.0.0.1` by default. Exposing it beyond
localhost requires both `--bind-public` **and** `--token`.

```bash
# Loopback only (default) — no token required
phpdup serve

# Public bind — token is mandatory
phpdup serve --bind-public --token YOUR_SECRET_TOKEN
```

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

- **Capacity cap** — a maximum of `MAX_JOBS` (100) entries are retained.
  When a new job is enqueued at capacity, the oldest completed or failed
  entry is evicted to make room. Pending and running jobs are **never**
  auto-evicted — they count toward the capacity limit but are preserved
  until they reach a terminal state.

- **TTL eviction** — completed and failed jobs older than
  `JOB_TTL_SECONDS` (default: 1 hour) are automatically purged on every
  mutating operation (`enqueue`, `markRunning`, `markCompleted`,
  `markFailed`). This reclaims memory even when the queue is well below
  capacity.

- **Administrative cleanup** — `POST /jobs/cleanup` (or calling
  `JobQueue::cleanup()`) force-evicts all terminal-state jobs regardless
  of TTL, useful for manual intervention.

- **Result summaries only** — job results stored are `{files, blocks,
  clusters, config}` summaries extracted from the full report. The raw
  JsonReporter output is discarded immediately after the summary is
  extracted, keeping per-job memory usage small and predictable.

These guarantees apply to the built-in single-process server. If you
swap `JobQueue` for a persistent backend (e.g. Redis) you retain the
same interface but the capacity/TTL semantics depend on your storage
choice.

### General advice

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
