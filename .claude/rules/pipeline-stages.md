---
paths:
  - src/Pipeline/**
  - tests/Unit/Pipeline/**
---

# Pipeline stages

- Implement `Phpdup\Pipeline\StageInterface` (just `name(): Stage` + `run(...)`). For live TUI progress also implement `CooperativeStageInterface::iter(): \Generator` and have `run()` simply drain `iter()` (see every stage in `src/Pipeline/Stages/`).
- Cooperative stages must `yield Stage::<Self>` periodically using a `YIELD_EVERY` constant (16-64 typical) so `src/Tui/PhpdupModel.php` can repaint between pumps.
- Mutate `Phpdup\Pipeline\PipelineState` directly — `$state->blocks`, `$state->clusters`, `$state->timings[<key>]`, `$state->currentTask`, `$state->stageProgress`. Do not store private state across runs.
- Notify progress via `$this->listener->onXxx(...)` (see `src/Pipeline/ProgressListener.php`); accept `?ProgressListener` in the constructor and fall back to `NullProgressListener` when null.
- Append the stage in `src/Cli/Command.php::execute()`'s `$buildPipeline` closure in canonical order: Scanning → Preprocessing → Clustering → Refactoring → Reporting. Update `Stage` enum if introducing a new phase.
- Track per-stage timing as `$state->timings['<short>'] = microtime(true) - $t0;`.
