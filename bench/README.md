# Comparative benchmarks

`bench/comparative.php` runs phpdup alongside other PHP duplicate-detection
tools on a shared corpus and prints a markdown table:

```
| Tool   | Wall (s) | Peak RSS (MB) | Clusters |
|---|---:|---:|---:|
| phpdup | 12.41    | 312.8         | 138      |
| phpcpd |  3.07    |  98.2         |  12      |
```

## Running

```bash
# Use any PHP corpus, e.g. tests/Fixtures or vendor/symfony/console
php bench/comparative.php tests/Fixtures
```

`/usr/bin/time -v` is shelled out for peak RSS — on systems without GNU time
the **Peak RSS (MB)** column shows `—`. `phpcpd` and `pmd` (CPD) are skipped
when not on `$PATH`.

## What "Clusters" means per tool

| Tool   | Counted from |
|--------|--------------|
| phpdup | `summary.clusters` in the JSON report |
| phpcpd | `Found N exact clones in M files` line |
| pmd-cpd| Number of `=========…` separator lines |

The numbers aren't directly comparable because each tool defines a "clone"
differently — phpdup reports type-1+2+3 with anti-unification, phpcpd does
exact token matching only, pmd-cpd does configurable token-stream matching.
The benchmark is mostly useful as a wall-time / memory comparison and as a
sanity check that all tools find duplicates in the same corpus.

## Adding a new tool

1. Append another `runTool(...)` call to `comparative.php` after the existing
   block.
2. Provide a `parseClusters` callback that pulls a number out of the tool's
   stdout (or its JSON output if you redirect it via `cmd`).
3. Wrap the call in a `commandExists()` check so the script still works on
   machines without that tool.

## CI usage

The script is intentionally not part of `vendor/bin/phpunit` — its output is
informational, not asserted. Run it manually or wire it into a separate
benchmark workflow that comments on a PR.
