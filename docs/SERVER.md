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
worker's full result file for a completed job. The `/score-pair` and
`/score-batch` names are **not** served routes — they belong to the
outbound ML sidecar contract described in the section "ML pair-scoring
sidecar contract" at the bottom of this page.

`POST /jobs` is asynchronous by default: a normal checkout spawns a
background `phpdup-worker` at startup (the serve command logs
`worker spawned (pid …)` when it has) and `POST /jobs` answers `202`
with the `job_id` the moment the job is admitted; clients then poll
`GET /jobs/{id}` — or the `/api/jobs/{id}` leg — until `status`
reaches `completed`. The full ack/timeout/reconcile semantics live in
[Async job posture](#async-job-posture). When no worker is available
— phar builds never have one, and a checkout's startup spawn can
fail — the same routes answer synchronously instead; see
[Serving from the phar build](#serving-from-the-phar-build).

**Request body fields — `POST /analyze` and `POST /jobs`** (one
payload shape serves both; re-probed live against `Application::handle()`):

- `paths` — required: a non-empty JSON list of strings naming files or
  directories inside the server's `--serve-root`. Paths resolve
  against the server process CWD and are confined to the root;
  absolute paths, NUL bytes and `..` traversal answer `400` before any
  analysis (or job) starts.
- `exact_only` — optional boolean (once-S03). Absent or `false` runs
  the full analysis; `true` is the HTTP twin of the CLI `--exact-only`
  posture (only structurally identical clones cluster). Any
  non-boolean value — including explicit `null` — answers
  `400 {"error":"exact_only must be a boolean"}`; on `POST /jobs` the
  refusal happens *before* minting, so no queue row is created. In
  async mode the worker re-checks the identical verdict
  (`Application::exactOnlyViolation()`) on the RUN frame and fails it
  there (defence in depth).
- `ml_pair_url` — must not be set on `POST /jobs`: it is an
  `Application::REFUSED_PAYLOAD_KEYS` field, and payloads carrying it
  are refused with `400 {"error":"job payload must not set: ml_pair_url"}`
  before the job is minted.

Every `POST` body is capped at `Application::MAX_BODY_BYTES` (16 MiB),
enforced at the HTTP layer: a declared `Content-Length` over the cap
answers `413 Payload Too Large` before any body bytes are read.

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
`127.0.0.1`, any `127.x.x.x` address, the name `localhost`, and the IPv6
loopback literal `::1` — are accepted by the guard with no extra flags
and no token:

```bash
# Loopback only (default) — no token required
phpdup serve
```

An IPv6 bind is spelled in its bracketed form on the socket and on the
wire (once-S97): `--host ::1` builds `tcp://[::1]:8080`, the `listening
on` line echoes exactly that, and clients use the RFC 2732 authority too
— with `curl -g` because the brackets are otherwise glob patterns:

```bash
$ phpdup serve --host ::1 --port 8080
phpdup serve listening on tcp://[::1]:8080

$ curl -g http://[::1]:8080/healthz
ok
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

The flip side of that guard is the default it guards: a loopback
server runs with **no token**, and "keep it local" has never meant
"private to you". Loopback confines the *network* reach of the daemon,
not the *users* — every account on this machine can connect to
`127.0.0.1`, and requests then read and scan files with the privileges
of whoever runs `phpdup serve`. Path confinement (see
[Path confinement](#path-confinement) below and the `--serve-root`
paragraph above) limits **what** a caller can scan — absolute paths,
`..` traversal and submitted paths that resolve through a symlink out of
the root are answered `400` before a job is minted, while a symlink
nested inside an admitted tree is contained by the walk itself (see
[Path confinement](#path-confinement)) — but nothing in the tokenless
posture limits **who** may ask.
What follows is the shipped honesty for that choice, three parts:

1. **The server says it out loud.** A tokenless boot prints this line
   before serving its first request (verbatim from `ServeCommand`):

   ```text
   phpdup serve: SECURITY: serving without --token — every user on this machine can reach this server unauthenticated (loopback confines the network, not the other users). scans are confined to serve root <the --serve-root value>. Pass --token to require a bearer header on every route, or run behind a proxy that authenticates. See docs/SERVER.md, "Security".
   ```

   An empty `--serve-root` disables path confinement, and the notice
   says so instead of claiming a confine that is not there: the middle
   clause then reads `scans are NOT confined (--serve-root is empty:
   any path this uid can read is scannable)`. Opt out of confinement
   knowingly.

2. **The disk posture is private by construction.** Results live in a
   0700 directory that no other uid can read, and the whole `/tmp`
   footprint is swept at shutdown and again at startup — see
   [Results on disk: /tmp hygiene](#results-on-disk--tmp-hygiene)
   below.

3. **On a shared machine, use the token anyway.** `--token` is only
   *mandatory* for public binds, but the bearer gate covers every
   route — `/healthz` included — so on any box other users can log
   into, run the loopback server with a token too (or behind a proxy
   that authenticates). The tokenless default is a convenience for
   single-user workstations, and the warning line exists precisely so
   it is never mistaken for authentication.

### Bearer token authentication

When `--token` is set, every request must include:

```
Authorization: Bearer YOUR_SECRET_TOKEN
```

Requests without a valid bearer token receive a `401 Unauthorized`
response before any other processing occurs.

That gate is honest about its own surface: with `--token` set, `GET
/healthz` is a request too — a liveness probe must carry the bearer
header like any other client, or it reads 401 and the load balancer
declares the daemon dead (once-S97; the routes table lists `/healthz`
with no body because the route takes no *parameters*, not because it
skips authentication — the sentence above is what gates it). Serve
token-gated behind anything that can attach the header, or probe
without a token.

### Error bodies: redacted detail

Error responses never map the server's filesystem (once-S97, following
the shell-S39 hygiene seam for the results tree): the 400 and 500 wire
bodies — and the failure reason an async job carries — hold the
exception's own sentence with **absolute paths reduced to relative
labels** (`/var/www/private/Config.php` arrives as `Config.php`), via
one shared seam, `Application::redactMessagePaths()`. The un-redacted
detail — class, message, file and line — is written to `error_log`,
the operator channel; `serve` has no configured debug-logger path, so
that is where a full diagnostic belongs and never on the socket.

The redaction is deliberately absent from the 400 scan-path refusals:
those echo the paths the client itself submitted (`path is outside the
served root: …`), which is input feedback, not a leak — the shell-S39
census says so verbatim, and the posture here keeps one taxonomy per
door rather than inventing a second.

### Request trust: Host, Origin and JSON shape

A loopback bind (the default, with no token in play) is not a
private network for a browser: any page a user opens can aim a
request at `127.0.0.1`, and the daemon historically answered every
`Host` it was given. `serve` therefore closes that surface
(once-046) with a request-trust gate that runs at the HTTP layer
before any body byte is awaited, and again inside the application —
one shared verdict (`Application::requestTrustViolation()`), two
doors. Three checks, in this order:

1. **Host allow-list.** The `Host` header must name the bound
   loopback authority — one of `localhost`, `127.0.0.1`, `::1`
   (`[::1]` bracketed), or the literal `--host` value, each bare or
   with the bound port. A missing `Host` is refused the same way
   (HTTP/1.1 requires it; a browser never omits it). The violation
   answers `400 Bad Request` with a JSON error body. This is the
   DNS-rebinding defence: a page that resolves `evil.test` to
   `127.0.0.1` still sends `Host: evil.test`, which is not the
   address this server was bound to.
2. **Same-origin `Origin`.** When a request carries an `Origin`
   header it must be an `http://` URL whose authority is itself on
   the Host allow-list — that is, the page holding the request must
   have been served by this very daemon, same origin, same port.
   Anything else (`Origin: null`, a foreign name, `https` behind a
   proxy) answers `403 Forbidden`. The daemon never sends CORS
   (`Access-Control-Allow-Origin`) on any response, so a same-
   machine page on a different port, and any remote page, has no
   fetch semantics to abuse here.
3. **JSON-only POSTs.** `application/json` is the only accepted
   `Content-Type` for `POST` routes. `text/plain` — the trick that
   turns an HTML form with `enctype="text/plain"` into a
   preflight-free cross-site POST, and `application/x-www-form-urlencoded`
   (curl `--data`'s default) are answered `415 Unsupported Media
   Type` *pre-body*: the refusal is made from the header section
   alone, so a hostile client cannot even park a 16 MiB claim on the
   reader. A POST without a `Content-Type` is refused the same way.
   `curl --data` examples in the wild need
   `-H 'Content-Type: application/json'` — every example in this
   document already carries it.

Public binds (`--bind-public`, which mandates `--token`) run this
gate unenforced, by design. A browser's cross-site form can never
set the bearer header, so the token already carries the whole
defence there, and behind a reverse proxy the visible `Host` is the
proxy's own — refusing it would refuse the deployment, so the
operator's proxy layer owns host trust exactly as it owns TLS. An
embedded host that constructs `Application` without authorities
keeps that same posture: the hosting runtime supplies its own trust
layer, and `Application::handle()` will refuse nothing that a
confident front-end did not already vet.

Verified live at this document's commit (loopback bind, scratch
port): cross-site Origin → `403`, foreign Host and rebinding-shaped
Host → `400`, `text/plain` form POST → `415` before the body is
read, same-origin JSON POST → `200`, and the playground-style
in-page fetch (same origin) → `200`.

### Path confinement

When `--serve-root` is set (default: the working directory), every
path in `paths[]` is validated:

1. **No absolute paths** — paths starting with `/` are rejected with
   `400 Bad Request`.
2. **No `..` traversal** — paths containing `..` are rejected.
3. **realpath containment** — each path is resolved via `realpath()`
   and must fall within `realpath(--serve-root)`. A submitted path
   that names a symlink pointing outside the root resolves out of the
   root and is rejected too — `400 path is outside serve-root: <path>`,
   answered before any job is minted, on `POST /analyze` and
   `POST /jobs` alike (and re-run by the async worker against its
   spawn-time root).

Confinement is two-tier for symlinks (once-S41). Admission can only
judge the paths a client names; content reached THROUGH a symlink that
merely sits inside an admitted tree is contained by the walk itself
(once-S20): a nested symlink whose target resolves outside the scan
root — or re-enters it, or does not resolve — is **skipped, never
scanned and never served**, and each skip answers one
`phpdup: warning: skipped symlinked path <path> (<reason>)` line,
where `<reason>` is one of the three shipped notes: `target escapes
the scan root`, `target does not resolve` or `target re-enters the
scan root`. That line lands on the server's stderr for synchronous
`POST /analyze` runs; async worker warnings do not currently reach
the daemon's stderr (the worker's stdio pipes are closed at spawn),
so on that path the notes ride the report carrier described below —
never a stderr line. The count of such notes
surfaces on the `scan_notes` channel: every serve transport threads
its run's skip notes onto the report's scan-notes carrier (sync
`POST /analyze` and the async worker share one seam,
`Application::buildServeReport()`); the `summary.scan_notes` JSON key
is part of the owner-gated schema-1.8 emit, so a schema-1.7 result
body stays byte-frozen while the carrier already carries the count.
Symlinks whose targets stay inside the root keep working — their
content is scanned at the literal in-root path.

This prevents phpdup from reading arbitrary filesystem paths through
the HTTP API: an escape is refused at admission or skipped at walk
time — either tier, content from outside the serve root never enters a
serve result.

### Results on disk: /tmp hygiene

Job results are not only queue rows in RAM: the worker writes each
finished result as `<jobid>.json` into the daemon's private results
directory, `/tmp/phpdup-results-<pid>`. That name embeds a guessable
pid — the secrecy is carried by the modes, never by obscurity:

- **0700, this uid only, fail-closed.** The directory is created 0700
  at startup (`chmod` defeats the umask, then the mode is re-read and
  doubted = refused). If the pid-derived name already exists and is
  owned by another user, or is a symlink, `serve` refuses to start at
  all rather than write results into a tree someone else can read.
- **0600 files and socket.** The worker is spawned under `umask 0177`
  and re-applies the mode itself: result files land 0600 and the
  worker's Unix socket (`/tmp/phpdup-worker-<pid>.sock`) is 0600 at
  bind, with a belt `chmod` right after. Another local uid can see the
  names; it cannot read a byte of either.
  The reclaim of a stale name at that path is **observed, not
  predicted** (once-S97): after `serve` proves the leftover has no live
  listener and unlinks it, the path must actually be gone — one that
  cannot be cleared (another uid owns the inode) ends the spawn attempt
  honestly in sync mode, naming the path, and the name is forgotten so
  no cleanup ever unlinks a foreign inode. Readiness likewise accepts
  only OUR bind: a socket-type inode (not a regular-file decoy) owned
  by this uid whose spawned child is still alive to answer for it; a
  child that dies before binding ends the wait immediately in sync mode
  instead of polling a corpse for five seconds.
- **Swept at shutdown.** Every exit path — the shutdown function and
  the SIGINT/SIGTERM handlers — unlinks the result files and removes
  the directory, so results never outlive the process. (The same law
  that bounds memory says nothing about the disk inside a run: each
  completed job adds a file, and TTL/eviction prune the queue rows,
  not the files — the shutdown sweep is the reclamation point.)
- **Swept at startup** (once-S71). Crashes and `kill -9` skip shutdown
  handlers, and a pid-derived directory that no longer has a server is
  invisible litter that used to accumulate forever. Before binding,
  `serve` now removes results directories owned by this uid whose
  owning pid is provably dead — plain files unlinked, directory
  removed, count reported on the startup line
  (`phpdup serve: swept N stale results directories`). Live-pid,
  foreign-owned and symlinked directories are never touched, and where
  the platform offers no pid-liveness oracle at all the sweep is a
  no-op: it may miss a cleanup, it never misfires a deletion.

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

- **Disk growth is swept, not capped** — the bounds above are about
  RAM. Finished results also land as files under
  `/tmp/phpdup-results-<pid>` and are not reclaimed mid-run; the
  startup and shutdown sweeps of
  [Results on disk: /tmp hygiene](#results-on-disk--tmp-hygiene) are
  the on-disk counterpart to these guarantees.

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

### Serving from the phar build

The phar ships the same HTTP surface minus the async worker — a
packaging decision (shell-S35), not an oversight. The spawn is an
`sh -c` exec, and nothing can execute a script stored inside a phar:
the shell resolves a `phar://` program path to *no such file* (exit
127) and `php` refuses to open one as its main script ("Could not open
input file"). So `serve` names the posture at startup instead of
waiting five seconds for a socket that can never appear:

```text
phpdup serve: running from the phar build — the async worker cannot be spawned (phar:// scripts are not executable); running in sync mode
phpdup serve running in sync mode (no worker)
```

From there every route answers with the same JSON as the checkout run.
`POST /jobs` still answers `202` with a `job_id` and settles the row
inline before responding (the sync-mode semantics above) — the shell-S35
probe verified the `400` refusal bodies for `..` traversal and absolute
`paths[]` entries byte-identically from both builds, and scan-leg
reports byte-identical when driven through absolute paths (with
`--serve-root ''` disabling confinement, `phpdup.phar` and the
checkout answered the same 5,811-byte report for a two-clone fixture).

One known limitation sits in the **scanning leg**, not the HTTP leg:
with a confined `--serve-root`, `paths[]` entries are relative, and PHP
running a phar as its main script redirects relative-path
`stat()`/`is_file()`/`is_dir()` calls into the archive itself, so a
phar-launched scan sees 0 files where the checkout finds clusters
(`realpath()` and absolute paths are unaffected). Absolutizing the scan
inputs at the pipeline entry is a fix leg outside this step's files;
until it lands, run `serve` from a checkout for anything the daemon
will actually scan. Async legs — ack-at-accept dispatch, terminal-event
observation, off-thread long jobs — also need the checkout worker.

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

**Visible fallbacks (once-S97).** pcntl is not guaranteed everywhere
`serve` runs, and a degradation that only speaks at `-vvv` is a silent
degradation. Both pcntl-absent facts print once at **DEFAULT**
verbosity before the bind, so the operator knows the posture from the
first second:

```
phpdup serve: ext-pcntl unavailable — no SIGINT/SIGTERM handlers are installed; a signal kills this process by default disposition (no worker reap, no results sweep). The next startup sweep reclaims what the death strands; see docs/SERVER.md, "Results on disk: /tmp hygiene".
phpdup serve: auto (CPU-count) worker selection requested but ext-pcntl/pcntl_fork is unavailable — the synchronous analysis falls back to SERIAL in-process scanning; see docs/SERVER.md, "Concurrency posture".
```

The first is `ServeCommand::signalHandlersAbsentNotice()` — with no
`pcntl_signal` the graceful SIGINT/SIGTERM shutdown is a deliberate
no-op and a kill lands on the default disposition; the startup sweep
(above, "Swept at startup") is what reclaims the stranding. The second
is `ServeCommand::serialFallbackNotice()` — a raised or auto
`--serve-workers` silently becoming serial is the substitution the
sizing table above would otherwise misread. The requested serial
default (`--serve-workers 1`) is not a fallback and never speaks. The
existing phar warning already followed this posture; these two join it,
and none of this changes the serial-loop sizing guidance itself.

## General advice

For non-localhost deployments, put a reverse proxy (nginx / Caddy)
in front and apply the usual hardening: TLS termination, rate
limiting, and allow-list of source IPs where possible.

## Playground

The playground front-end is **not built**, and today's back-end cannot
serve its paste story: `phpdup serve` accepts only on-disk `paths`
under a fixed `--serve-root` — there is no paste/upload endpoint, and
the planned input route (finding once-088) is an open design decision,
not shipped behaviour. See `docs/PLAYGROUND.md` for the full honest
status. What the contract *does* support today: a front-end pointing
users at files inside a hosted serve-root tree can submit `POST /jobs`
and render the polled JSON in the browser — and because
`Application` is transport-agnostic, the same code runs under
FrankenPHP for a single-binary deployable artifact.

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
