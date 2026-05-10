# Example gallery

Public-corpus snapshots of phpdup's output, useful for showing
reviewers what to expect before they run it on their own
codebase.

## Format

Each subdirectory corresponds to a notable PHP project:

```
gallery/
  symfony/
    report.json          ← truncated to top-25 clusters
    summary.md           ← prose write-up
    screenshot.png       ← optional CLI output snapshot
  laravel/
    …
```

`report.json` is generated via:

```bash
phpdup analyze <project-root>/src \
    --auto-tune          \
    --json /tmp/full.json \
    --limit 25            \
    --plain

jq '. | .clusters |= .[0:25]' /tmp/full.json > gallery/<project>/report.json
```

## Status

The gallery is a placeholder for now — populating it requires
running phpdup against checked-out OSS projects, manually validating
the output, and committing the (truncated) reports plus a
human-written summary.

Contributions welcome:

1. Pick a public-domain PHP project (Symfony, Laravel, Composer,
   PHPUnit, PsySh, …).
2. Run the command above.
3. Open a PR with `gallery/<project>/report.json` plus a short
   `summary.md` explaining the most interesting clusters.

The point isn't to claim phpdup found bugs in OSS — it's to show
new users the shape of a real report so they can calibrate their
expectations before running on their own code.
