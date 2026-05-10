# Web-based playground

Interactive phpdup demo: a small front-end where users paste PHP and
see the cluster report rendered in-browser.

## Architecture

```
   ┌────────────────┐    POST /jobs     ┌──────────────────┐
   │ playground UI  │  ───────────────► │ phpdup serve     │
   │  (Next.js)     │  ◄─────────────── │  (REST server)   │
   └────────────────┘    GET /jobs/{id} └──────────────────┘
```

- **Front-end**: a Next.js / Vercel-Pages app with two panes — a
  Monaco editor on the left, the cluster table on the right. Pastes
  go to the REST server's `/jobs` endpoint; results render via the
  same JSON shape that powers `HtmlReporter`.
- **Back-end**: any deployment of `phpdup serve` (see `docs/SERVER.md`)
  reachable from the front-end's origin.
- **Sharable links**: input is base64-url-encoded into a query
  parameter so links round-trip to a snapshot of the analysis.

## Status

The front-end lives in a separate repo (intentionally — keeps this
codebase single-purpose). Phpdup ships **only** the back-end:

  - `phpdup serve` HTTP server (covered in `docs/SERVER.md`).
  - JSON contract spec (`docs/JETBRAINS_PLUGIN.md`).

Once the front-end repo lands, this doc gets a deployment-howto
section. Until then it's a pointer for contributors who want to
build their own playground UI against the existing back-end.

## HtmlReporter as a fallback playground

If you want a playground without spinning up a separate front-end:

```bash
phpdup analyze your-snippet.php --html ./report
```

The static-site output is browseable, has interactive sort + search,
and works offline. Less dynamic than the Next.js playground (no
"paste new code" textarea) but no infrastructure to maintain.
