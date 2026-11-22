# ADR 0001 — Cooperative pipeline architecture

**Status**: accepted
**Date**: 2026-04
**Amended**: 2026-11-22 (once-S68 doc-truth wave: live per-stage
`YIELD_EVERY` values replace the "16-64" band; cancellation-contract
census added below — the Decision records what was decided, the note
records what ships).

## Context

phpdup runs a 5-stage pipeline: Scanning → Preprocessing → Clustering
→ Refactoring → Reporting. Each stage is non-trivially long-running
on real corpora (10s of seconds for medium projects). The TUI
(`src/Tui/PhpdupModel.php`) needs to repaint progress while the
pipeline is mid-stage; the watch-mode runner needs to interrupt and
restart cleanly when files change; the SIGINT handler needs to
flip a soft-cancel flag and have the pipeline check it without busy-
spinning.

## Decision

Stages implement two interfaces:

```
StageInterface             — name(), run(state, output): void
CooperativeStageInterface  — adds iter(state, output): \Generator
```

Cooperative stages `yield Stage::<self>` periodically (every
`YIELD_EVERY` units of work). The band is a per-stage tuning, not a
shared constant — the live values (verified 2026-11):
`ScanningStage::YIELD_EVERY = 16` files, `PreprocessStage = 32`
files/records, `ClusterStage = 256` streamed edges, `RefactorStage =
4` refactored clusters. Each tracks its unit's cost per yield: a
file take or an edge-merge is cheap (batch widely), a cluster's
anti-unification pass is the expensive one (yield early). The
original text said "typically 16-64" (2026-04); by
2026-11 two of four stages sat outside that band through deliberate
re-tuning, and the constant was renamed into uniform spelling
(`ScanningStage` `YIELD_INTERVAL` → `YIELD_EVERY`, shell-S37) to
match this ADR's vocabulary — the code aligned to the ADR, the band
never did. The `Pipeline::iter()` generator forwards yields to its
caller, who can:

- repaint the TUI between yields
- check `state->cancelled` and short-circuit to Reporting — the
  hard guarantee is the PIPELINE boundary (`Pipeline::iter()` skips
  every remaining stage except Reporting once the flag is set); a
  cancel landing mid-stage only takes effect early in the stages
  that themselves consult the flag (census below)
- inspect mid-run progress via `state->currentTask`,
  `state->stageProgress`, etc.

Non-cooperative stages just implement `run()`; the cooperative
runner falls through to `run()` for them.

## Alternatives considered

- **Threads / multiprocessing**: PHP doesn't ship a thread API at
  the userland level. Forking via pcntl works for parallel work
  inside a stage but doesn't help with mid-stage UI updates from
  the parent.
- **Event-loop / promises** (ReactPHP, Amp): would require a major
  rewrite. The TUI already uses ReactPHP for input handling but the
  pipeline benefits from being driveable synchronously too (CI use,
  tests).
- **Polling thread**: too imprecise; misses fast stages, lags slow
  ones.

The Generator approach is the lightest-weight pattern that buys us
all three needs.

## Consequences

- Every long-running stage must implement `CooperativeStageInterface`
  to be friendly to the TUI / SIGINT / watch.
- `run()` always works as a fallback — call sites that don't need
  yields use it directly.
- Generator overhead is negligible vs the work each stage does.

## Status note — cancellation, as shipped 2026-11

Verified census of `$state->cancelled` readers inside the stage loops:

- **PreprocessStage** consults it between yields in both its serial
  and streaming legs, and hands the worker pool a cancel predicate
  (`$state->cancelled` polled between records, shell-S12 law).
- **ClusterStage** consults it at three mid-loop sites plus the pool
  predicate, and on early exit writes its partial cluster set into
  the state (a cancelled run reports what it finished, never a
  truncated all-clear — caveat text landed with shell-S37).
- **RefactorStage** consults it between yields in the serial and
  streaming legs, pool predicate included.
- **ScanningStage** yields every 16 files but does NOT read the flag
  mid-loop. A cancel raised during the file walk takes effect when
  the walk completes and `Pipeline::iter()` skips the remaining
  stages — precisely the stage whose duration the Context calls
  unbounded. This is the ADR's known weak point, filed as
  php-dup-finder-once-078 (DA-07); the fix leg rides with the later
  lane holding the `ScanningStage` lock (once-S37), which also owns
  the scan-signal/schema-1.8 window.

Until that leg lands, read the Decision's "check state->cancelled
and short-circuit to Reporting" as the boundary guarantee plus the
three stages that beat it from the inside.
