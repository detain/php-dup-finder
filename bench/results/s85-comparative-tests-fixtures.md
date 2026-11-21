| Tool | Status | Wall (s) | Peak RSS (MB) | Count (unit) |
|---|---|---:|---:|---|
| phpdup | ok | 0.97 | 83.3 | 18 (pipeline clusters) |
| phpcpd | not installed | — | — | — (not measured) |
| pmd-cpd | not installed | — | — | — (not measured) |

Fairness notes (mandatory reading — timings compare DIFFERENT workloads per tool):
  1. phpdup runs --min-impact 0 --max-df 0.5 --no-cache --no-incremental: max-df 0.5 is 50x the production default (0.01, src/Cli/Config.php), so the candidate-pair volume timed here is NOT the production workload.
  2. phpcpd runs --fuzzy --min-lines 5 --min-tokens 50; pmd-cpd runs cpd --minimum-tokens 50. Different tools under different floors count different workloads: wall times are per-tool work profiles, not a like-for-like race.
  3. Single-shot samples: no repeats, no variance, whatever host load applies at run time. Treat as order-of-magnitude only.
  4. Competitor counts are duplicate PAIRS from structured XML plus the transitive-closure family count (same normalization bench/run.php applies); phpdup emits pipeline clusters directly.

Corpus: tests/Fixtures (20 PHP files) · single-shot samples
