# Benchmark suite

End-to-end comparative benchmark harness for phpdup vs alternative
PHP duplicate-detection tools. Runs every available tool against
every corpus, computes precision/recall/F1 against a synthetic
ground truth, and emits markdown results.

```
bench/
├── corpora.php              # download / regenerate corpora
├── run.php                  # execute every tool on every corpus
├── score.php                # precision/recall/F1 vs ground truth
├── comparative.php          # one-shot quick comparison
├── run-all.sh               # one-liner: corpora → tools → results
├── feature-matrix.md        # hand-curated capability matrix
├── corpora/
│   ├── synthetic-fuzz/
│   │   └── .ground-truth.json    # known duplication topology
│   └── …                          # downloaded OSS corpora (.gitignored)
├── tools/                   # auto-downloaded (.gitignored)
└── results/                 # per-run output (.gitignored)
    ├── latest.md
    ├── detection-rate.md
    └── <label>.json
```

## One-liner

```bash
bench/run-all.sh
```

That's the whole benchmark loop:

1. Download `phpcpd.phar` (≈3 MB) into `bench/tools/`.
2. `npm install jscpd@4` into `bench/tools/node_modules/`.
3. Shallow-clone the OSS corpora (Symfony Console, Laravel HTTP,
   PHPUnit, WordPress core).
4. Generate the synthetic-fuzz corpus (deterministic — `mt_srand`).
5. Run every tool on every corpus with a per-tool wall-time cap.
6. Score detection rate against `.ground-truth.json`.

Steps that can't run on the host (no network, no node, etc.) are
skipped — the harness reports `—` for that tool's column instead of
failing.

Output:

- `bench/results/latest.md` — wall time / RSS / cluster count per
  (tool, corpus).
- `bench/results/detection-rate.md` — precision / recall / F1 on the
  synthetic corpus.

## Running individual pieces

```bash
# Just download / refresh corpora.
php bench/corpora.php
php bench/corpora.php --refresh   # force re-clone
php bench/corpora.php --list      # show plan, exit

# Run only one corpus across all tools.
php bench/run.php --corpus=synthetic-fuzz --label=initial

# Score a specific run.
php bench/score.php bench/results/initial.json
```

## Tools probed

| Tool        | Source                       | Auto-installed by run-all.sh |
|-------------|------------------------------|------------------------------|
| **phpdup**  | `bin/phpdup` (this repo)     | always                       |
| phpcpd      | `bench/tools/phpcpd.phar`    | downloads from `phar.phpunit.de` |
| pmd-cpd     | system `pmd cpd`             | no — install separately      |
| jscpd       | `bench/tools/node_modules/.bin/jscpd` | `npm install jscpd@4` |
| simian      | system `simian`              | no — commercial              |

Missing tools show `—` in the results table; the harness never
errors out because of an absent tool.

## Detection-rate scoring

The synthetic corpus has a known duplication plan written to
`.ground-truth.json` at generation time. `bench/score.php` compares
each tool's reported clusters to ground truth using member-set
Jaccard ≥ 0.6 with a ±2-line boundary tolerance, then emits per-tool
precision / recall / F1 numbers.

This is the single most-honest comparison we can make: for the
synthetic corpus we know the right answer, so any tool's miss / false
positive is mechanically measurable. The OSS corpora are *speed*
benchmarks only — there's no ground truth there.

## Feature matrix

`feature-matrix.md` is the hand-curated capability comparison —
where phpdup wins, where it loses, what each tool does that the
others don't. Updated by hand as new features land.

## Adding a tool

1. Implement `Tool<Name>` invocation logic at the bottom of
   `bench/run.php` (look for the existing `runPhpdup` / `runPhpcpd`
   functions for the pattern).
2. Add a row to `feature-matrix.md`.
3. Add an install step to `run-all.sh`.

## Adding a corpus

1. Add an entry to the `$plan` array in `bench/corpora.php` with
   either a `git_url` (clone target) or `synthetic` (fuzz generator
   plan). Don't pick anything > 50 MB; the harness clones shallowly
   but huge repos still take minutes to fetch.
2. Run `php bench/corpora.php`.

## CI

The benchmark suite is intentionally not part of `vendor/bin/phpunit`
— it shells out to external tools and downloads corpora. The
relevant outputs (`latest.md`, `detection-rate.md`) get committed
periodically when we want to update the published numbers, but the
suite isn't gated.
