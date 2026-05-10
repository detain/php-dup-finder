# ADR 0001 — Cooperative pipeline architecture

**Status**: accepted
**Date**: 2026-04

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
`YIELD_EVERY` units of work, typically 16-64). The `Pipeline::iter()`
generator forwards yields to its caller, who can:

- repaint the TUI between yields
- check `state->cancelled` and short-circuit to Reporting
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
