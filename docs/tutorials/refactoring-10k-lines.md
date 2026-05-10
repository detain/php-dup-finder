# Refactoring 10K lines of duplicated code

A walkthrough on a 10-kilo-line legacy PHP codebase. Goal: shrink
the project by ≈15% via mechanical refactor of the highest-impact
clusters phpdup finds.

## 1. Profile the corpus

```bash
phpdup analyze src/ --auto-tune --plain --summary-only
```

`--auto-tune` probes the file count, picks `min-block-size` /
`max-df` / `min-impact` for you, and prints the profile it picked.
The `--summary-only` line at the end gives the headline numbers:

```
phpdup auto-tune: medium corpus (3127 files): tightening max_df=0.005 and min_block_size=8.
✓ summary  84 clusters · 12018 duplicated lines · 38240 total impact
```

## 2. Generate the actionable artifacts

```bash
phpdup analyze src/ --auto-tune \
    --html report/                  \
    --refactor-patch  refactor-patches/   \
    --refactor-tests  refactor-tests/     \
    --diff            cluster-diffs/      \
    --csv             clusters.csv        \
    --timeseries      history.jsonl
```

You now have:

- A static HTML site to browse (`report/index.html`).
- One **patch file per cluster** (`refactor-patches/*.patch`).
- One **PHPUnit test skeleton per cluster** (`refactor-tests/*.php`).
- Per-cluster unified diffs (`cluster-diffs/*.diff`) for rapid
  side-by-side review.
- A spreadsheet-friendly CSV (`clusters.csv`) for stakeholders.
- A JSONL history line for tracking duplicate debt over time.

## 3. Sort by **safety**, not just impact

The default sort is `impact:desc` — most code saved at the top. But
the *first* cluster you should refactor isn't the biggest, it's the
**safest big one**. Re-rank by safety:

```bash
phpdup analyze src/ --auto-tune --sort safety:desc --limit 20 --plain
```

Look at the trailer line on each cluster:

```
  ✓ confidence 1.00 · safety 0.92
```

Anything ≥ 0.85 is mechanically refactor-able. Anything < 0.6 needs
a human in the loop.

## 4. Apply patches

```bash
git apply refactor-patches/X53edd72b.patch
```

If the patch's header says **MANUAL REVIEW REQUIRED**, skip it and
move on — the cluster uses `$this`, closures, or generators that
phpdup conservatively refuses to mechanically rewrite.

## 5. Verify with the auto-generated tests

```bash
cp refactor-tests/ClusterX53edd72bTest.php tests/Refactored/
vendor/bin/phpunit tests/Refactored/ClusterX53edd72bTest.php
```

The tests start as `markTestIncomplete` placeholders. Fill in the
expected return value for each provider row and re-run.

## 6. Track progress

```bash
phpdup analyze src/ --auto-tune --timeseries history.jsonl
```

Each run appends a JSON line with cluster count, duplicated-line
total, and the commit SHA. Pipe to a dashboard:

```bash
jq -s 'sort_by(.timestamp) | map({sha:.commit_sha, lines:.duplicated_lines})' history.jsonl
```

Watch the `lines` number drop as you land refactor PRs.

## 7. Wire the regression check into CI

Add `.github/actions/phpdup` to your workflow with a `fail-on-impact`
gate so a *new* high-impact cluster (introduced after the cleanup)
fails the build:

```yaml
- uses: detain/php-dup-finder/.github/actions/phpdup@master
  with:
    paths: src
    fail-on-impact: 100
```

The team can keep shipping; phpdup catches re-introduced duplication
before it lands.

## TL;DR

1. `--auto-tune` to set sensible defaults for your corpus.
2. `--refactor-patch` + `--refactor-tests` for actionable artifacts.
3. Sort by safety, not impact.
4. Track over time with `--timeseries`.
5. Gate against regressions with the GitHub Action's
   `fail-on-impact`.

Repeat weekly. The first cleanup pass usually halves duplicate-line
count; subsequent passes harvest the long tail.
