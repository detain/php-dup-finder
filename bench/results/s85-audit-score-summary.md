# once-S85 audit score-audit before/after — home-my `include/Api/Vps` window

Aggregate-only record (privacy gate — no finding titles, paths, or
payload content from the private corpus; regenerate with the recipe at
the bottom on a host holding the truth sources).

## Inputs

- Truth: `bench/audit-truth/extract-my.php` over the on-host report
  (digest `32c213480f…` — drifted from the stale `corpus-pins.txt`
  report pin, disclosed in `README.md` here; S86 owns enforcement),
  `--manifest` from `git -C /home/my ls-tree -r --name-only ad58b221c5`.
- Report: phpdup (sha recorded in the comparative artifact, PHP 8.3)
  `analyze <pinned-tree>/include/Api/Vps --json … --plain` —
  production default settings; 31 PHP files; 13 clusters emitted.

## Window anatomy (counts)

| count | value |
|---|---|
| truth findings in window | 9 |
| ranged findings (verified-gated recall obligation) | 4 |
| file-only cites (graze population) | 5 |
| truth-gap findings (all ranged tokens failed verified gate) | 0 |
| truth members in window | 61 |
| members excluded `verified:false` (never scored) | 1 |
| admitted ranged members (deduped exact (file,start,end)) | 24 |
| admitted llm-origin ranged members | 0 |
| global truth tokens on this host (context for the verified gate) | 797, of which 299 rangeless cites, 277 verified:false |

## Before — old scorer (floor(line/tol) bucketing, blended populations)

| metric | old |
|---|---|
| recall | 44.4% (4/9) |
| precision (in-window) | 100.0% |
| precision@top50 | 100.0% (13/13) |

Composition (established by the new scorer's per-detection provenance,
same inputs): **all 4 "detections" were file-level grazes with zero
line evidence** (rangeless cites, k=1 file-presence); **0 of the 4
ranged families matched**; every ranged miss had best bucket-overlap 0.
The 100.0% "precision" measured only "some cluster touched a cited
filename" for each of the 13 window clusters — not a detection-quality
metric. This reproduces the once-106 finding shape (the f15-era run it
cited showed 5/5 rangeless detections).

## After — once-106 cure (ranged vs file-only split, verified gate, ±2 one-to-one Kuhn)

| metric | new |
|---|---|
| **recall (ranged-evidence only)** | **0.0% (0/4)** |
| ranged findings missed (line evidence) | 4 |
| file-level grazes among file-only cites | 4/5 — **not counted as detections** |
| line-evidence relevance (clusters carrying >=1 ranged link / window clusters) | 0.0% |
| file-touch relevance (the renamed old "precision") | 100.0% |
| admitted verified:false tokens excluded from denominators | 1 |

Honest headline: this invocation has **zero line-evidence recall** in
the window; the previous 44.4%/100.0% pair was a grazing artifact
end-to-end. Miss analysis (aggregate): the 4 ranged families need
k=1,2,2,9 one-to-one ±2 matches; the largest (k=9) also shows the
27→18 admit-dedup effect (5 duplicate/retracted cites left the
obligation). Detector-side follow-ons (out of this lane): doc-cited
ranges describe copy-families spread across whole methods while phpdup
clusters per block-kind; a `--min-impact 0`-style widening run or a
nested/covers audit mode is an open design question for closeout.

## Matcher behavior proof (public fixture — re-runnable from this file)

Synthetic truth × report below; run
`php bench/score-audit.php --truth=<t> --report=<r>`:

| id | members | population | expected verdict |
|---|---|---|---|
| T1 | `a.php 8-18`, `b.php 28-38` (verified) | ranged | DETECTED at ±2 Kuhn vs report cluster `a.php 10-20`+`b.php 30-40` (both endpoints 2 apart — the retired bucket formula MISSED this via parity split) |
| T2 | `c.php 100-110` | ranged | MISSED vs `c.php 105-115` (5 apart — honest negative control: ±2 is real) |
| T3 | `d.php 50-60`, `verified:false` | truth-gap | excluded from every denominator (path absent at scan pin — truth-side error, not a detector miss) |
| T4 | `a.php` cite, `start:null`, `origin:"llm"` | file-only | graze of T1's cluster recorded and labelled NOT A DETECTION |

Fixture verdicts measured by this lane: old scorer detected only T4
(the graze; recall 25.0%, precision 50.0%) and missed T1; new scorer
reports recall 50.0% (1/2) = T1 detected `2/2` ranged-kuhn, T2 missed
best-match 0/1, T4 graze `0` detections, T3 excluded. The one-to-one
±LINE_TOLERANCE predicate mirrors `src/Testing/BenchmarkScoreCalculator`
(re-derived locally per the once-S09 handoff §1 — src matcher seam is a
follow-on row).
