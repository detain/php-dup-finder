# JetBrains plugin contract (PhpStorm / IntelliJ)

> Status: phpdup itself ships the **JSON contract** below. The IntelliJ
> plugin lives in a sister repo (e.g. `php-dup-finder-jetbrains`) and
> consumes phpdup's `--json` output. This document defines the
> stable interface between them.
>
> Regenerated from shipped truth (once-S39, 2026-11-23): the schema
> version, every field list, the example payloads and the exit-code
> table below were diffed against `Phpdup\Reporting\JsonSchemaSpec`,
> `Phpdup\Reporting\JsonReporter` and live `bin/phpdup --json` probe
> runs at this tip. Examples are actual tool bytes; the only edits are
> elisions inside long text values, each marked.

## How the plugin invokes phpdup

```bash
phpdup analyze <project-roots> \
    --json /tmp/phpdup.json \
    --plain
```

- The plugin shells out via IntelliJ's `GeneralCommandLine`. Don't
  tail stdout for findings — read the JSON file when the process
  exits `0`.
- `--plain` keeps `OutputInterface` non-decorated so the plugin's
  console window doesn't accumulate ANSI escapes.
- Exit codes (identical table to `docs/CLI.md`; every row below was
  probe-verified or code-verified):
  - `0` — analysis completed. Finding clusters is NOT an error: an
    empty `clusters` array is the only "clean" signal.
  - `1` — runtime error: uncaught exception, reporter failure, an
    unknown CLI option, or a typed runtime refusal (candidate-budget
    halt, script-kind apply refusal — each renders one `phpdup:`
    error line first).
  - `2` — invalid invocation: a non-numeric or out-of-domain numeric
    option value, an invalid `--stage`/`--pager`/`--theme` choice, a
    non-boolean `--dry-run` value, a nonexistent scan path (one
    `phpdup: path not found:` line, refused before the pipeline),
    a `--validate-config` failure, an empty/corrupt `--baseline` file.
  - `3` — CI gate tripped (`--fail-on-impact` / `--max-clusters`);
    the tripped gate is named in the output.
  - `4` — CI gate tripped: a `--baseline FILE` was provided, exists,
    and new duplicate clusters were found beyond it.
  - `130` — SIGINT (Ctrl+C, first press). The pump stops
    cooperatively but the reporting stage still runs: the `--json`
    file IS written with a **partial** payload and the CLI prints
    `phpdup: cancelled by user — partial report rendered`. Treat the
    result as incomplete evidence, never as a clean scan, and never
    as a crash.

## JSON shape (schema 1.7)

The phpdup JSON output carries a `schema_version` string
(`Phpdup\Reporting\JsonSchemaSpec::SCHEMA_VERSION`, currently `"1.7"`)
at the top level. The full per-version history lives in that class's
docblock; the bump rules are:

| Bump | Trigger (per `JsonSchemaSpec`) | Plugin MUST | Plugin SHOULD |
|------|--------------------------------|-------------|---------------|
| MAJOR (`1.x` → `2.x`) | a field removed or renamed, a field's type changed, or an enum value removed | refuse to load; tell user to update plugin | log a clear error |
| MINOR (`1.6` → `1.7`) | a field added at any nesting level (always in TRAILING position), or semantics corrected inside existing keys (1.7 was exactly this) | load anyway; ignore unknown keys | warn that some fields may be unfamiliar |

Because minor-bump fields are trailing, a consumer that parses `1.6`
parses `1.7` untouched, and a lenient parser reads any future minor
payload the same way. Never rely on key order (see *Stability
contract*).

### Top-level keys

| Key | Type | Since |
|-----|------|-------|
| `phpdup_version` | string | baseline (pre-1.2) |
| `schema_version` | string | baseline (pre-1.2) |
| `summary` | object | baseline (pre-1.2) |
| `config` | object | baseline (pre-1.2) |
| `clusters` | list&lt;object&gt; | baseline (pre-1.2) |
| `declared_twins` | list&lt;object&gt; | 1.3 (trailing) |

### `summary` — 10 keys (`JsonSchemaSpec::summaryShape()`)

| Field | Type | Notes |
|-------|------|-------|
| `files` | int | Files scanned — historical key, keeps its position; restated as `files_scanned`. |
| `blocks` | int | Blocks extracted. |
| `parse_errors` | int | Files that failed to parse. |
| `clusters` | int | Clusters present in the payload (after filters). |
| `duplicated_lines` | int | Sum over clusters of their members' line spans. |
| `total_impact` | int | Sum of every cluster's `impact`. |
| `files_scanned` | int | Since 1.2: unambiguous restatement of `files`. |
| `files_reused` | int\|null | Since 1.2: cache-warm files. `null` = this producer does not track the bucket. |
| `files_processed` | int\|null | Since 1.2: freshly parsed files. |
| `files_without_blocks` | int\|null | Since 1.2: readable files yielding no block. |

Accounting law while all buckets are tracked (non-null):
`files_scanned = files_reused + files_processed + files_without_blocks + parse_errors`.
`null` is distinct from `0` (untracked vs. tracked-and-zero);
producers predating per-bucket tracking (library API, server workers)
emit `null`.

### `config` — the run's effective tuning echo (6 keys)

Actual bytes from the probe run whose cluster is reproduced below
(`paths` prefix elided; everything else verbatim):

```json
"config": {
    "paths": ["…/corpus/promoted"],
    "min_block_size": 8,
    "normalization_mode": "aggressive",
    "similarity_threshold": 0.8,
    "tree_threshold": 0.85,
    "min_cluster_impact": 20
}
```

This block echoes the OUTPUT-side tuning of the run. The INPUT config
file surface (`phpdup.json` keys, types, validation) is documented in
`docs/config-schema.json` — a different artifact; do not parse one as
the other.

### `clusters[]` — every key, in emission order

| Field | Type | Notes |
|-------|------|-------|
| `id` | string | Content-derived cluster id (canonical FindingId stream since 1.3); usable as a stable UI key. |
| `kind` | string | Block kind of member[0] (method/function/closure/foreach/if/…). |
| `exact` | bool | True for type-1 (exact-canonical) clusters. |
| `similarity` | float | Min pairwise similarity [0,1]. |
| `confidence` | float | Anti-unification confidence [0,1]. |
| `safety` | float | Refactor-safety score [0,1]. |
| `impact` | int | Removal the cluster represents: `(members − 1) × mean member AST-node size − per-hole parameterization penalty`. A node-count score, NOT a line count. |
| `pattern_tags` | list&lt;string&gt; | e.g. `sql-builder`, `controller-action`, `loop-map`, `config-driven`. |
| `outlier_members` | list&lt;int&gt; | Indices of members flagged by coherence analysis. |
| `architectural_findings` | list&lt;object&gt; | `{analyzer, code, severity, message, suggestion}`. |
| `signature` | string\|null | Suggested abstraction signature — always ONE line (see below). |
| `members` | list&lt;object&gt; | `{file, start, end, kind, namespace, class, name, size, repo, scope}` — `start`/`end` are 1-based inclusive line numbers (`start` is a method's signature line); `name` is null for anonymous/inner blocks; `size` is the AST node count; `repo` (scan-root basename, `''` when no root matched) and `scope` are trailing additions since 1.4. |
| `pairs` | list&lt;object&gt; | `{blockA, blockB, matchTier, matchScore}` — intra-cluster scored edges; `matchTier` names the tier that accepted the pair (a probe's exact pair was labelled `exact-hash`); clusters whose members were grouped without per-pair scoring carry `[]` (see the example). |
| `holes` | list&lt;object&gt; | Anti-unification parameters — see below. |
| `scope` | string | `'single'` \| `'multi'` — trailing since 1.4 (`'multi'` when members span ≥2 distinct repos). |
| `template` | object\|null | `{id, file, start, end}` canonical template: the medoid when recorded, else members[0] — trailing since 1.5. |
| `source` | object | `{text: string\|null, truncated: bool}` capped template text (200 lines / 8 KiB). `text: null` is the "no source" value (unreadable/refused) — trailing since 1.5. |
| `evidence_diff` | object\|null | `{member, file, start, end, diff, truncated}` unified diff template↔median-scored peer (120 lines / 6 KiB); null when there is no peer, no pair evidence, or the texts are byte-identical — trailing since 1.5. |
| `hole_coverage` | float\|null | 0..1 fraction of the template inside hole subtrees; null without an AST — trailing since 1.5. |
| `re_expressed` | bool | The operator's `max_hole_coverage` stance is exceeded (default `1.0`: never) — trailing since 1.5. |
| `hole_fragment` | object\|null | `{stmt_start, stmt_length, code, truncated}` longest hole-free statement run; non-null only when `re_expressed` — trailing since 1.5. |
| `placement` | object | `{shape, rationale, same_host, lca_namespace, host_packages}` extraction-site advice — trailing since 1.6. `shape` is one of `'move'` \| `'expression_function'` \| `'data_table'` \| `'defaulted_function'` \| `'parameterized_function'`; `host_packages` is a sorted package list only when members span ≥2 scan roots with readable composer manifests, else null with the reason folded into `rationale` (conservative mode). |

### `signature` — one line, always

The signature is a SINGLE-line string: parameters join with `', '`,
there is no trailing comma, no indent and no embedded newline; a
zero-parameter cluster renders `function name(): mixed`. Reporters
wrap for display instead of re-fragmenting the stored value
(`php-dup-finder-output-058`; consumers must treat the field as one
line).

Parameter order is law: first the members' **promoted shared formal
parameters** (since the output-S49a/S49b era — the longest positional
prefix that every member declares identically: simple variable, same
name, type-equal up to normalization, no reference/variadic/default/
attributes; the generated abstraction receives them positionally),
then the required hole parameters, then optional-segment bools with
their `= false` defaults:

```
function settle(string $channel, int $threshold, string $value): mixed
```

— actual probe output, two `settle()` methods both declaring
`(string $channel, int $threshold)` and differing only in a string
literal. `$channel`/`$threshold` are promoted from the members' own
declarations and NEVER mint a `holes[]` entry (holes stay divergences);
`$value` is the synthesized hole parameter.

### `holes[]` — 8 keys (`JsonSchemaSpec::holeShape()`)

| Field | Type | Notes |
|-------|------|-------|
| `placeholder` | string | `__P<n>` value holes, `__O<n>` optional segments, `__cap` whole-template sentinel. |
| `kind` | string | e.g. `literal`, `name`, `subtree`, `optional_block`. |
| `inferred_type` | string | Synthesized parameter type (e.g. `"string"`; `"mixed"` when undecidable). |
| `suggested_name` | string | Display name, `$`-carrying (e.g. `"$value"`). |
| `observed` | list&lt;string\|null&gt; | Deduped value set; a null entry is an ABSENCE row (see semantics below). |
| `value_count` | int | Count of distinct observed values. |
| `degraded` | bool | Trailing since 1.5. |
| `present_in_members` | list&lt;int&gt; | `optional_block` holes only; member indices with a genuine VALUE row. |

Hole value semantics (schema 1.7): an `observed[]` entry is either a
raw source fragment or `null`. `null` means the member's row is an
ABSENCE — a structural gap, a never-gathered observation, or a
verified optional-segment absence (one canonical marker since 1.7; no
printable sentinel strings ever reach the wire). The hole's `degraded`
bool says how far to trust the value set; on an `optional_block` hole
it also disambiguates the nulls: `degraded:false` means every null row
is a VERIFIED absence — the member provably lacks the segment — while
`degraded:true` means some null rows were simply never observed.
`present_in_members` lists the indices of members with a genuine
VALUE row (cluster `members[]` order); absent-marked members,
whatever the kind of absence, never appear.

The `__cap` sentinel (output-S57): a routed cluster (more members than
the anti-unifier walks, 32) whose walk found zero divergences carries
one synthetic whole-template hole stating that the un-walked tail was
never observed. Real probe bytes (34 identical methods, schema 1.7):

```json
{
    "placeholder": "__cap",
    "kind": "subtree",
    "inferred_type": "mixed",
    "suggested_name": "",
    "observed": [null],
    "value_count": 1,
    "degraded": true
}
```

It renders NO parameter in `signature` — the signature of that probe's
method cluster is `function audit(): mixed`. A plugin should treat a
`__cap` hole as "evidence degraded by routing": value columns are not
fully trustworthy and the apply-side refuses this cluster, so surface
it read-only.

A genuine `optional_block` hole, real bytes from the shipped Golden
fixture `tests/Golden/optional-near-dups.json`:

```json
{
    "placeholder": "__O0",
    "kind": "optional_block",
    "inferred_type": "bool",
    "suggested_name": "$includeSomeOtherLogic",
    "observed": ["some_other_logic($here);", null],
    "value_count": 2,
    "degraded": false,
    "present_in_members": [0]
}
```

`degraded:false` + the null row = the other member provably LACKS the
segment; `present_in_members:[0]` names the one member that has it —
and the paired signature default (`bool $includeSomeOtherLogic =
false`) sits at the end of the parameter list.

### `declared_twins` — trailing top-level key since 1.3

The item-4.3 claim-vs-reality table: one row per doc-comment twin
declaration, ALWAYS present (`[]` when nothing was declared). Row keys
(`JsonSchemaSpec::declaredTwinShape()`): `file`, `keyword`,
`reference`, `kind` (`'claim'` | `'directive'`), `line`, `comment`,
`finding_id` (string|null, directives only), `target_file`
(string|null), `target_blocks` (int), `similarity` (float|null),
`cluster_id` (string|null — canonical FindingId of the claimant's
cluster when it has one), `verdict` (`'holds'` | `'drifted'` |
`'unresolved'` | `'unmeasurable'` | `'harvested'`).

### Full cluster example — real probe bytes

One `bin/phpdup --json` run over a scratch corpus (two `settle()`
methods sharing formal params, differing in one literal). Only the two
long text values marked `…` were elided; every byte shown is output
bytes, pretty-printed as the tool writes them:

```json
{
    "id": "F96363944049e207d",
    "kind": "method",
    "exact": true,
    "similarity": 1,
    "confidence": 1,
    "safety": 1,
    "impact": 96,
    "pattern_tags": ["config-driven", "loop-map", "loop-filter"],
    "outlier_members": [],
    "architectural_findings": [],
    "signature": "function settle(string $channel, int $threshold, string $value): mixed",
    "members": [
        {
            "file": "…/corpus/promoted/Gateways.php",
            "start": 8,
            "end": 25,
            "kind": "method",
            "namespace": "Scratch\\Promoted",
            "class": "PaymentGateway",
            "name": "settle",
            "size": 96,
            "repo": "promoted",
            "scope": "single"
        },
        {
            "file": "…/corpus/promoted/Gateways.php",
            "start": 30,
            "end": 47,
            "kind": "method",
            "namespace": "Scratch\\Promoted",
            "class": "ShippingGateway",
            "name": "settle",
            "size": 96,
            "repo": "promoted",
            "scope": "single"
        }
    ],
    "pairs": [],
    "holes": [
        {
            "placeholder": "__P0",
            "kind": "literal",
            "inferred_type": "string",
            "suggested_name": "$value",
            "observed": ["'usd'", "'eur'"],
            "value_count": 2,
            "degraded": false
        }
    ],
    "scope": "single",
    "template": {
        "id": "93151627_0",
        "file": "…/corpus/promoted/Gateways.php",
        "start": 8,
        "end": 25
    },
    "source": { "text": "…(template source text, capped at 200 lines / 8 KiB, flag marked by `truncated`)…", "truncated": false },
    "evidence_diff": { "member": "93151627_1", "file": "…/corpus/promoted/Gateways.php", "start": 30, "end": 47, "diff": "…(unified diff template↔peer, capped at 120 lines / 6 KiB)…", "truncated": false },
    "hole_coverage": 0.0118,
    "re_expressed": false,
    "hole_fragment": null,
    "placement": {
        "shape": "data_table",
        "rationale": "Every hole is a literal (2 distinct observed values) \u2014 the pair differs by data, not control flow; a lookup table plus one driver function replaces the cluster. Shared namespace root: Scratch\\Promoted.",
        "same_host": false,
        "lca_namespace": "Scratch\\Promoted",
        "host_packages": null
    }
}
```

(The `source.text` / `evidence_diff.diff` bodies and the absolute path
prefix are the marked elisions; every other byte is wire truth,
including the `\u2014` escape spelling — the encoder passes
`JSON_UNESCAPED_SLASHES` but not `JSON_UNESCAPED_UNICODE`, so
forward-slash paths render raw while non-ASCII text (em-dashes and
similar) arrives `\u`-escaped. Member-id prefixes (`93151627_…`) are
content-hash derived and shift between corpora.)

## Schema 1.8 — planned, NOT shipped

The once-S37 lane shipped its **carriers** (scan-signal fields on the
internal `Report` object, commit `88de264`) and a precise emission
proposal, but the wire event — `summary.scan_notes` /
`summary.parse_failures` / `summary.clusters_replayed` emission plus
the `SCHEMA_VERSION` 1.7 → 1.8 bump and golden regen — is an
OWNER-WINDOW item, deliberately gated as of 2026-11-23. Until it
ships, `"1.7"` is the only truth `schema_version` can carry; when it
lands it will follow the MINOR rules above (three trailing summary
keys, old consumers unharmed). Do not pre-code against 1.8; do ignore
unknown trailing keys, always.

## Plugin features that map to fields

| Plugin feature | Reads |
|----------------|-------|
| Inspection: "duplicate cluster" highlight | `members[*].file`, `members[*].start`, `members[*].end` |
| Quickfix: "extract suggested abstraction" | `signature` (single line — wrap for display yourself), `holes[*].suggested_name`, `holes[*].inferred_type` |
| Tool window: cluster grouping | `pattern_tags`, `architectural_findings`, `scope`/`members[*].repo` for repo grouping |
| Severity in inspection panel | take MAX of `architectural_findings[*].severity` |
| "Where should the extraction live?" panel | `placement.shape` + `placement.rationale` |
| Evidence pane ("why is this a cluster?") | `template`, `source`, `evidence_diff`, `hole_coverage`; refuse/gray-out apply affordances when `holes[*].placeholder == "__cap"` or `degraded` |
| Baseline diff / gate UX | exit codes `3`/`4` above; the gate that tripped is named in stderr |

## Golden fixtures (digest reference)

The three committed end-to-end payloads under `tests/Golden/` are the
canonical machine-readable examples of a real schema-1.7 report —
regenerated only by the sanctioned golden-serial lanes. Their git blob
ids at the tip this doc was regenerated against (`git rev-parse
b1aa72c:tests/Golden/<file>`):

| Fixture | Blob id |
|---------|---------|
| `notify-exact.json` | `e70db860ce04fc3959c77e447b183a4b8e0a58fc` |
| `optional-near-dups.json` | `006c85a2bb990a034c6977a921306ec8e798aac2` |
| `sql-exact.json` | `39b152d7a654945a313fa0dd77f15f91629a6480` |

A plugin-side parser regression suite should pin against these bytes,
not against hand-typed examples.

## Stability contract

- Field order is unspecified — never rely on JSON key order.
- New fields arrive only in MINOR bumps and always in TRAILING
  position at their nesting level; the plugin must use
  `getOrDefault`-style access and ignore unknown keys.
- Existing fields' types won't change without a MAJOR bump.
- Ids (`clusters[*].id`, member ids in `pairs`/`template`) are
  content-derived: stable for stable input, but treat them as opaque
  strings, not as addresses.

## Out of scope for this repo

- Bundling the IntelliJ plugin (separate `.jar` build).
- Running phpdup against in-flight unsaved buffers (PSI integration
  is plugin-side responsibility — write the buffer to a temp dir,
  invoke phpdup against it).
- Cross-version compatibility shims; consumers pin their phpdup
  range like any other library.
