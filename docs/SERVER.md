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

The server has **no authentication**. Bind it to `127.0.0.1` and put
a reverse proxy (nginx / Caddy) in front for any non-localhost
deployment.

## Playground

The intended deployment for the public phpdup playground is a small
Cloudflare-Pages / Vercel front-end that posts user-pasted PHP to
this server's `/jobs` endpoint and renders the resulting JSON via
the browser-side reporter. The `Application` class is transport-
agnostic, so the same code can run under FrankenPHP for a single-
binary deployable artifact.
