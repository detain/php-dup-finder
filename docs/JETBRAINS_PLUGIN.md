# JetBrains plugin contract (PhpStorm / IntelliJ)

> Status: phpdup itself ships the **JSON contract** below. The IntelliJ
> plugin lives in a sister repo (e.g. `php-dup-finder-jetbrains`) and
> consumes phpdup's `--json` output. This document defines the
> stable interface between them.

## How the plugin invokes phpdup

```bash
phpdup analyze <project-roots> \
    --json /tmp/phpdup.json \
    --plain
```

- The plugin shells out via IntelliJ's `GeneralCommandLine`. Don't
  tail stdout for findings — read the JSON file when the process
  exits 0.
- `--plain` keeps `OutputInterface` non-decorated so the plugin's
  console window doesn't accumulate ANSI escapes.
- Exit codes:
  - `0` — analysis completed
  - `1` — internal error (parse error, IO failure, etc.)
  - `2` — bad arguments / config validation failed
  - `130` — user pressed Ctrl+C; partial JSON may still be present

## JSON shape (schema 1.0)

The phpdup JSON output carries a `schema_version` string
(`Phpdup\Reporting\JsonSchemaSpec::SCHEMA_VERSION`) at the top
level. Compatibility rules:

| Schema bump | Plugin MUST | Plugin SHOULD |
|-------------|-------------|---------------|
| MAJOR (`1.x` → `2.x`)       | refuse to load; tell user to update plugin | log a clear error |
| MINOR (`1.0` → `1.1`)        | load anyway | warn that some fields may be unfamiliar |
| PATCH                        | load anyway | nothing |

Top-level fields:

```json
{
  "phpdup_version": "0.1.0",
  "schema_version": "1.0",
  "summary": {
    "files": 152,
    "blocks": 4023,
    "parse_errors": 0,
    "clusters": 18,
    "duplicated_lines": 412,
    "total_impact": 1240
  },
  "config": { "min_block_size": 8, "normalization_mode": "aggressive", … },
  "clusters": [ … ]
}
```

Cluster fields (each entry of `clusters[]`):

| Field | Type | Notes |
|-------|------|-------|
| `id` | string | Stable id; usable as a UI key. |
| `kind` | string | Block kind of member[0] (method/function/closure/…) |
| `exact` | bool | True for type-1 (exact-canonical) clusters. |
| `similarity` | float | Min pairwise similarity [0,1]. |
| `confidence` | float | Anti-unification confidence [0,1]. |
| `safety` | float | Refactor-safety score [0,1]. |
| `impact` | int | Approximate lines that vanish if the abstraction lands. |
| `pattern_tags` | list&lt;string&gt; | e.g. `sql-builder`, `controller-action`, `loop-map`. |
| `outlier_members` | list&lt;int&gt; | Indices of members flagged by coherence analysis. |
| `architectural_findings` | list&lt;object&gt; | `{analyzer, code, severity, message, suggestion}`. |
| `signature` | string\|null | Suggested abstraction signature (multi-line). |
| `members` | list&lt;object&gt; | `{file, start, end, kind, namespace, class, name, size}`. |
| `holes` | list&lt;object&gt; | `{placeholder, kind, inferred_type, suggested_name, observed[], value_count, present_in_members?}`. |

## Plugin features that map to fields

| Plugin feature | Reads |
|----------------|-------|
| Inspection: "duplicate cluster" highlight | `members[*].file`, `members[*].start`, `members[*].end` |
| Quickfix: "extract suggested abstraction" | `signature`, `holes[*].suggested_name`, `holes[*].inferred_type` |
| Tool window: cluster grouping | `pattern_tags`, `architectural_findings` |
| Severity in inspection panel | take MAX of `architectural_findings[*].severity` |

## Stability contract

- Field order is unspecified — never rely on JSON key order.
- New fields may be added in MINOR bumps; the plugin must use
  `getOrDefault` style access.
- Existing fields' types won't change without a MAJOR bump.

## Out of scope for this repo

- Bundling the IntelliJ plugin (separate `.jar` build).
- Running phpdup against in-flight unsaved buffers (PSI integration
  is plugin-side responsibility — write the buffer to a temp dir,
  invoke phpdup against it).
- Cross-version compatibility shims; consumers pin their phpdup
  range like any other library.
