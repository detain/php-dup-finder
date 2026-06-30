# Kickoff prompt — phpdup remediation run

**How to use:** paste everything in the fenced block below into a fresh Claude Code session opened at the repo root (`/home/sites/php-dup-finder`). It boots the multi-agent run defined in [plan.md](plan.md) against the findings in [findings.md](findings.md), and is **resumable** — paste it again any time (new context window, after an interruption, next day) and it picks up at the next unstarted step by reading the progress log in `plan.md` §9.

Set the two `OPERATOR SETTINGS` at the top before first run. Defaults are safe.

---

```
You are the DRIVER for the phpdup remediation run. Your job is to take the changes
in findings.md to completion by orchestrating subagents exactly as specified in
plan.md. Read BOTH files in full before doing anything else; plan.md is the
authoritative protocol and findings.md is the authoritative list of changes.

=== OPERATOR SETTINGS (read these from here; do not re-ask unless unset) ===
- SCOPE: full            # "full" = plan.md Phases 1–12, or "1-4" = critical+security+artifacts only
- AUTONOMY: escalate     # "escalate" = pause only on real blockers; "confirm-phases" = check in after each phase
- MERGE: squash          # PR merge strategy
=========================================================================

NON-NEGOTIABLE RULES (from plan.md §0):
1. SERIAL. Exactly one step in flight. One step = one finding (or the grouped set
   plan.md names) = one branch = one PR = one squash-merge. Never run two steps'
   branches at once.
2. master is the resting state. After every step the working tree is back on master,
   clean, synced with origin/master. Branches are transient PR vehicles only.
3. DELEGATE, DON'T DO. You (driver) spawn ONE phase orchestrator at a time and wait.
   Each orchestrator spawns worker agents for ALL substantive work and does nothing
   itself but coordinate, read agent results, enforce gates, and report. You and the
   orchestrators may run read-only status commands (git status, gh auth status,
   reading a file to verify a claim) but must not author code, tests, or docs.
4. GREEN GATES before any merge (plan.md §4): `vendor/bin/phpunit` (all suites),
   `vendor/bin/phpstan analyse`, `vendor/bin/psalm` all clean, and psalm-baseline.xml
   must NOT grow. Honor failOnWarning/failOnNotice, the PHP 8.1 floor, and the
   JsonSchemaSpec::SCHEMA_VERSION bump + UPDATE_SNAPSHOTS=1 rule when a reporter shape
   changes.

PER-STEP LIFECYCLE (plan.md §3 — the orchestrator runs this, spawning a fresh agent
for each role and passing the finding's briefing verbatim each time):
  1. Implement agent — makes the change.
  2. Review agent ⇄ Fix agent — loop until the reviewer returns CLEAN (max 4 iters,
     then escalate to you with the working tree left clean).
  3. Test agent — add/update tests covering the new logic; run ALL gates; on failure
     loop through a Fix agent (max 4).
  4. Docs agent — heavily document the changed code AND update README.md / docs/ /
     CALIBER_LEARNINGS.md as relevant.
  5. Integration agent — the git workflow in plan.md §5: `unset GITHUB_TOKEN GH_TOKEN`
     FIRST, then branch → (caliber sync) → commit → push → `gh pr create` →
     `gh pr merge --squash --delete-branch` → `git checkout master` → `git pull` →
     delete local branch → verify clean on master.
  6. Record the step in the plan.md §9 progress log (status, PR URL, gate results).
Use the agent roster/subagent_types in plan.md §1 (code-reviewer for review; raise
model/effort for the hardest steps C1/C2/P1/S1; lower it for mechanical nits).

STARTUP SEQUENCE:
A. Read plan.md + findings.md. Open plan.md §9 progress log: if steps are already
   marked merged, this is a RESUME — skip Phase 0 re-checks that already passed and
   continue at the first unstarted step. Otherwise this is a fresh start.
B. Run Phase 0 pre-flight (plan.md Phase 0):
   0.1 Confirm clean `git status` on master and a green baseline from all gates
       (record the numbers). If already red, STOP and report.
   0.2 Resolve Caliber (plan.md §6): prefer running /setup-caliber once; if it can't
       be set up, use the manual `caliber refresh && git add ...` fallback before each
       commit. If caliber is entirely unavailable, STOP and ask the operator.
   0.3 `unset GITHUB_TOKEN GH_TOKEN` then `gh auth status`; confirm push/PR/merge work.
       Check whether branch protection on master blocks an automated squash-merge.
       If gh can't auth or protection blocks auto-merge, STOP and report to the operator.
   If any Phase 0 gate fails, do NOT proceed to Phase 1 — surface the blocker.
C. Honor SCOPE: run plan.md phases in order (1–12 for "full", 1–4 for "1-4").

MAIN LOOP:
  for each PHASE in the chosen scope, in order:
      spawn ONE phase orchestrator (general-purpose) with: the phase's step list from
        plan.md, the per-step lifecycle, the gates, the git workflow, and instruction
        to append to the §9 log. Wait for its report.
      after it returns: relay a 3–5 line phase rollup to the operator.
      if AUTONOMY = confirm-phases: pause for the operator before the next phase.
      if AUTONOMY = escalate: continue automatically to the next phase.
  When all in-scope phases are done: verify final state (on master, clean, synced,
  all gates green), annotate resolved findings, and give a final summary with every
  PR link.

ESCALATE (pause and ask the operator) ONLY for genuine blockers:
  - a Phase 0 gate fails (dirty tree, red baseline, no caliber, gh/branch-protection).
  - a step exceeds its review or test loop cap (4) — report what's stuck, leave the
    tree clean (discard the uncommitted partial; nothing was committed).
  - a destructive/ambiguous decision plan.md flags for operator confirmation (e.g. the
    D1 wire-vs-delete dispositions, or the large F1/3.2 feature designs).
  - gh/CI merge failure that isn't a code fix (auth, permissions, protection).
Do NOT escalate for ordinary review findings, test failures, or gate breaks — those
are handled inside the lifecycle by the fix/test loops.

PROGRESS REPORTING: after each merged step, post one line (step ID, PR URL, gates).
After each phase, post the rollup. Keep the plan.md §9 table current so a future
resume knows exactly where to continue.

Begin now with the STARTUP SEQUENCE. If this is a fresh start, run Phase 0 and report
the baseline + the three Phase 0 results before spawning the Phase 1 orchestrator.
```

---

## Notes for the operator

- **First run:** edit `OPERATOR SETTINGS` if you want `SCOPE: 1-4` or `AUTONOMY: confirm-phases`, then paste the block. The driver will run Phase 0 and report the green baseline + Caliber + `gh`/branch-protection results *before* touching code.
- **Resuming:** just paste the same block again. The driver reads the `plan.md` §9 progress log, skips finished steps, and continues at the next unstarted one. Keeping that table current (the prompt instructs every orchestrator to append to it) is what makes the run survive context resets.
- **Stopping cleanly:** interrupt any time. Because work merges one finding at a time and the tree always returns to `master`, a stop never leaves a half-applied change on `master` — at worst an in-flight step's *uncommitted* edits are discarded on the next resume.
- **Prerequisites it will check for you:** clean baseline, Caliber sync (it can run `/setup-caliber`), `gh` auth (with the `unset GITHUB_TOKEN GH_TOKEN` step), and whether `master` branch protection permits automated merge. Any of these failing pauses the run with a clear ask rather than guessing.
