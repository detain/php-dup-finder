# audit-truth/1 — derived ground-truth payload shape

Both adapters (`extract-my.php`, `extract-phlix.php`) emit this JSON envelope:

```jsonc
{
  "corpus":         "home-my" | "phlix",
  "source_report":  "/abs/path/to/refactor.md",  // which prose report was parsed
  "source_sha256":  "…",                          // digest of those exact bytes
  "derived_by":     "bench/audit-truth/extract-<corpus>.php",
  "schema":         "audit-truth/1",
  // phlix envelope additions:
  "scan_pins":      {"server": "375f7992", "hub": "ba1ae3b8"},
  "heads_parsed":   138,
  "doc_claimed_findings": 149,
  "findings":       [ … ]
}
```

## Finding record

Shared keys: `id`, `class` (doc §2.1 taxonomy letter or `??`), `title`
(heading text; W6 currency stamps stripped), `members[]`, `drift` (bool seed),
`needs_llm_pass` (see below).

Per-corpus extras:
- **my**:   `lane` —; `label` (the report's free-form `Type:` text — canonical
  short values like `copy-paste` OR long prose); `claim` (size claim kept as a
  STRING — claims are claims, not truth); `drift_warning_markers` (int).
- **phlix**: `lane` (dossier letter A–H); `w6_stamps_stripped` (int, title+body);
  `lane_claim_counts` (the dossier's raw `## Counts:` line or null). No label/claim.

`needs_llm_pass`:
- phlix: `{"unresolved_tokens": […raw cite strings…], "zero_members": bool}`
- my:    `{"gap": bool}` plus `claimed_copies`/`ranged_members` ints when the
  claim cited a copy count (gap = claim ≠ mechanically materialized members).

## Member record

```jsonc
{
  "repo":     "phlix-server" | "phlix-hub",   // phlix only
  "file":     "include/Api/Vps/Start.php",     // repo-relative at the pinned commit
  "start":    24 | null,                       // 1-based inclusive; null = cite had no range
  "end":      41 | null,
  "origin":   "…",                             // how it was bound, below
  "verified": true | false | null              // file present in the pinned manifest (null = no manifest given)
}
```

`origin` enums:
- **my**: `explicit` (absolute or backticked path written out) | `relative`
  (continuation token, bound to last-seen directory — trust it less).
  Unbalanced-brace prose residues are dropped mechanically (their finding then
  surfaces zero-member = LLM tail).
- **phlix** (dialects, see extract-phlix.php header): `anchor-inline`,
  `side-tagged`, `basename`, `basename-manifest`, `path-manifest`,
  `path-last-side`, `path-basename-manifest`, `bare-last-anchor`.

## Mechanical/LLM split (honesty law)

Adapters are deterministic and never guess: ambiguous basenames (cross-side
twins), contextless bare lines, unbalanced-brace cross-products, and
prose-only findings land in `needs_llm_pass` / zero-member state instead of
fabricated paths. See `LLM-PASS.md` for the stage-2 operator work orders.

## Consumers

`bench/score-audit.php` reads a truth payload (this shape) and a phpdup `--json`
report. Matching is member-overlap ONLY — bucketed ±LINE_TOLERANCE lines
mirroring `Phpdup\Testing\BenchmarkScoreCalculator::LINE_TOLERANCE` — never by
cluster id. It gates on `schema_version` major/minor tolerance (N/N−1).

## FUTURE (reserved, f15 §7)

A `scope` field (`"php" | "template" | "sql" | "config"`) is reserved on member
records for the wave-3 template/SQL truth split. Additive — stays audit-truth/1.
