# bench/results — committed raw benchmark artifacts

This directory's `s85-*` / future labelled files are the **evidence
chain for published numbers** (once-S85 / PD-BENCH-08 cure): every
measured figure cited in `bench/feature-matrix.md`, README, or docs
must be re-derivable from a raw JSON committed here.

## The law

1. **Figures cite artifacts.** A number enters a document only with a
   pointer to the raw JSON backing it. A claim with no committed run is
   either measured and committed, or labelled *not measured* — never
   silently quoted.
2. **UTC-stable.** Committed artifacts carry no wall-clock timestamps;
   provenance lives in `phpdup_sha` / `host` / `php_version` fields and
   in the git history of the commit that records them.
3. **Privacy gate.** audit-truth payloads (derived from private
   reports/trees) are NEVER committed — only aggregate counts and
   public fixture tables appear here (see `s85-audit-score-summary.md`
   and `bench/audit-truth/README.md`).
4. **Provenance / corpus state (disclosed twice).** These results are
   scored on the CURRENT corpus state at commit time: the synthetic
   corpus is the family-granular planter output of `bench/corpora.php`
   (once-S09 shape), and the home-my audit window was materialized at
   its scan pin `ad58b221c5` — while the report source on the recording
   host has drifted from its `bench/corpus-pins.txt` digest (pinned
   425580 B / `8d1a98f6…`, on-host 561172 B / `32c21348…`; S86's
   pin-enforcement row owns the cure). once-S86 re-pins corpora and
   RE-RENDERS these results — expect a second labelled commit event.
5. **Gitignore quirk.** `.gitignore` excludes `bench/results/*.json`
   and `*.md` (working renderings must not churn diffs); the committed
   exemplars here were added with `git add -f`. A follow-on
   `.gitignore` negation for labelled artifacts is owed — `.gitignore`
   was outside this lane's locks.

## Files

| File | What it backs |
|---|---|
| `s85-comparative-tests-fixtures.json` | raw single-shot comparative run (`php bench/comparative.php tests/Fixtures --timeout=120`) at sha `6ac5809c9…`, host+PHP recorded inside; the ONLY committed backing for wall-time/RSS/cluster figures in `bench/feature-matrix.md` |
| `s85-comparative-tests-fixtures.md` | the rendered table + mandatory fairness notes exactly as the script emitted them (re-derivation: run the command above on the pinned sha; expect same shape, drifting timing digits) |
| `s85-audit-score-summary.md` | aggregate-only before/after of the once-106 score-audit cure on the home-my `include/Api/Vps` window + the public ±tolerance matcher fixture proof |

Unlabelled `run-*.json`, `latest.md`, `detection-rate.md` are working
output of `bench/run-all.sh` / `bench/score.php` and stay uncommitted
unless promoted by naming them here.
