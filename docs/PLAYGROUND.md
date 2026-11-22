# Web-based playground

**Status: a design intention, not a product.** This file describes what
a phpdup paste-and-analyse demo *would* look like. No front-end
exists, and the back-end today cannot serve the paste story — read the
gap section first, before treating anything below as a description of
shipped behaviour.

## The gap today

`phpdup serve` (see `docs/SERVER.md`) accepts a JSON body of on-disk
`paths` confined to its `--serve-root`. There is **no** endpoint that
takes pasted source, an upload, or a base64 blob. The "users paste
PHP" story this doc once told in the present tense is unimplementable
through the current routes; the paste/upload route (finding once-088)
is an open design decision that has not been built or finalised.

What *has* since shipped (once-S73) is the other half of that
finding's honesty demand: a `serve` deployment whose background worker
never came up — a phar build, or any startup where the worker could
not be spawned — no longer "secretly" runs the full pipeline inline
on the request loop. `POST /jobs` there answers
`503 {"error":"async unavailable: …"}` before any job is minted,
beside a startup notice saying the same, while `POST /analyze` keeps
answering synchronously. That refusal is the degraded-mode contract,
not a paste door: it closes the stall risk, it does not build the
upload route above, and no part of this file should be read as
promising one.

## Intended architecture (not built)

```
   ┌────────────────┐   POST <paste/upload>*  ┌──────────────────┐
   │ playground UI  │  ─────────────────────► │ phpdup serve     │
   │  (Next.js)     │  ◄───────────────────── │  (REST server)   │
   └────────────────┘     GET /jobs/{id}      └──────────────────┘
        (* a route like this does not exist yet)
```

- **Front-end** (not built): the plan is a Next.js / Vercel-Pages app
  with two panes — a Monaco editor on the left, the cluster table on
  the right — rendering the same JSON shape that powers
  `HtmlReporter`.
- **Back-end** (shipped, but path-only): any deployment of
  `phpdup serve` reachable from the front-end's origin can analyse
  files inside its serve-root today (`POST /analyze` synchronously;
  the async `POST /jobs` family needs the background worker, and `POST /jobs`
  answers the honest 503 without one, above); the missing piece is
  purely the input route.
- **Sharable links** (not built): base64-url-encoding the pasted input
  into a query parameter inherits the same missing-input problem and
  would need a size story under the 16 MiB body cap.

## What phpdup ships

Phpdup ships **only** the back-end:

  - `phpdup serve` HTTP server (covered in `docs/SERVER.md`).
  - JSON contract spec (`docs/JETBRAINS_PLUGIN.md`).

The front-end would live in a separate repo (intentionally — keeps
this codebase single-purpose) and that repo has not been written.
Once it exists, this doc gains a deployment-howto section; until then
it is a brief for contributors who want to build their own playground
UI against the existing (path-only) back-end.

## HtmlReporter as the working fallback playground

The shipped alternative to a paste UI — analysis runs from disk, the
report renders as a static interactive site (re-verified against the
tip: `--html <dir>` writes `index.html`, `app.js`, `style.css` and one
page per cluster):

```bash
phpdup analyze your-snippet.php --html ./report
```

The output is browseable, has interactive sort + search, and works
offline. There is no "paste new code" textarea — re-run the command
after each edit — but it needs no infrastructure at all.
