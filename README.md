# phpdup — AST-based PHP duplicate-logic detector

> A semantic clone detector and refactoring assistant for PHP codebases.
> Behaves more like an "extract function" advisor than a copy/paste finder.

[![CI](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/php-dup-finder/branch/master/graph/badge.svg)](https://app.codecov.io/gh/detain/php-dup-finder)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue.svg)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

`phpdup` parses every file in a PHP codebase into an Abstract Syntax
Tree, normalizes those ASTs into a canonical form, and finds clusters
of **parameterizable duplication** — places where the *shape* of the
code repeats and only literals, identifiers, method names, table
names, or *whole optional segments of code* vary.

For each cluster it doesn't just point at the duplicates, it tells you
**what the abstraction would look like** — its parameter list, types,
and a suggested function name — ready to drop into a refactor.

![phpdup CLI scanning a fixture corpus](docs/media/cli-basic.gif)

A run on `tests/Fixtures` returns its top 2 clusters by impact. Each
cluster's "Suggested abstraction" box is the function signature
phpdup is recommending you extract; the "Holes" table lists every
parameter with its inferred type and the values observed across cluster
members. Compare with classic copy/paste detectors that only highlight
the duplication; phpdup tells you the threshold and the role string
*are the parameters of the abstraction* with their inferred types and
observed values, ready to apply.

---

## Quick start

### Install

> **Status (verified against the live releases API, 2026-11-22):** no tag has
> been published through the release workflow yet — the only release,
> `v1.0.0`, predates the signing/publishing chain in
> [`.github/workflows/release.yml`](.github/workflows/release.yml) and carries
> **no assets**, so the phar URLs below currently return 404. The workflow
> attaches them to every `v*` tag pushed from now on.

**PHAR (recommended once published).** The release workflow builds a
self-contained phar, signs a tag-bound release manifest, and attaches the
artifact, its checksum sidecar, the manifest and signature, build metadata
and release notes to the GitHub release:

```bash
curl -sSLO https://github.com/detain/php-dup-finder/releases/latest/download/phpdup.phar
curl -sSLO https://github.com/detain/php-dup-finder/releases/latest/download/phpdup.phar.sha256
sha256sum --check phpdup.phar.sha256    # sidecar format: "<hash>  phpdup.phar"
chmod +x phpdup.phar && sudo mv phpdup.phar /usr/local/bin/phpdup
```

The sidecar confirms the download arrived intact; it authenticates nothing.
`phpdup self:update` instead verifies the **signed manifest** before
replacing the phar — see [docs/RELEASE.md](docs/RELEASE.md).

**Composer — not supported yet.** `composer require --dev
detain/php-dup-finder` does not resolve from a fresh stable project: the
package depends on 16 `sugarcraft/*` libraries pinned to `dev-master#<sha>`,
and pinned dev-version VCS requirements cannot be pulled in transitively —
the consuming root would have to declare the repositories and
`minimum-stability: dev` itself. Until those dependencies ship resolvable
tags, use **from source** instead:

```bash
git clone https://github.com/detain/php-dup-finder.git
cd php-dup-finder && composer install
```

Then run the CLI from the checkout via its launcher, e.g.
`bin/phpdup analyze src` (the Analyze/View sections below all use it).
To upgrade a source install: `git pull && composer install`.

### Analyze

```bash
# Scan and see top clusters in CLI
bin/phpdup analyze src

# Multi-format output: JSON + HTML report
bin/phpdup analyze src --json phpdup.json --html phpdup-report --min-impact 30

# CI gate (exact clones only, ~6s on 3,300-block corpus)
bin/phpdup analyze src --exact-only --min-impact 50
```

### View report

```bash
# Open HTML report in browser
open phpdup-report/index.html

# Or query the JSON
jq '.clusters | length' phpdup.json
```

---

## Key CLI flags

| Flag | Default | Description |
|------|---------|-------------|
| `--config`, `-c` | — | Load settings from `phpdup.json` |
| `--json FILE` | — | Structured JSON report |
| `--html DIR` | — | Interactive HTML report |
| `--min-impact N` | `20` | Minimum cluster impact (≈ duplicated lines) to include |
| `--min-block-size N` | `8` | Minimum AST node count per block |
| `--kinds K1,K2` | all | Block kinds to analyze: `method`, `closure`, `function`, `if`, `for`, etc. |
| `--exact-only` | off | Skip near-duplicate detection (fast Type-1 only) |
| `--optional-blocks on/off` | `on` | Enable Type-3 / optional-segment detection |
| `--mode strict/default/aggressive` | `aggressive` | Normalization strictness |
| `--similarity N` | `0.80` | Jaccard threshold for near-duplicate phase |
| `--db-aware` | off | Enable ORM-aware semantic deduplication (Eloquent, Doctrine, PDO, etc.) |
| `--workers N`, `-j` | `0` | Worker count (`0` = auto-detect from CPU cores) |
| `--auto-tune` | off | Probe corpus and pick size-appropriate defaults |
| `--tui` | off | Show interactive SugarCraft dashboard |
| `--watch` | off | Re-run analysis on file changes (poll-based) |

Full reference: [`docs/CLI.md`](docs/CLI.md)

---

## Documentation

| Topic | File |
|-------|------|
| CLI flag reference | [`docs/CLI.md`](docs/CLI.md) |
| HTTP API server (`phpdup serve`) | [`docs/SERVER.md`](docs/SERVER.md) |
| CI/CD integration recipes | [`docs/CI.md`](docs/CI.md) |
| JSON config schema | [`docs/config-schema.json`](docs/config-schema.json) |
| ML pair-similarity sidecar | [`docs/ML.md`](docs/ML.md) |
| ML training corpus format | [`docs/ml-corpus-format.md`](docs/ml-corpus-format.md) |
| Release & distribution process | [`docs/RELEASE.md`](docs/RELEASE.md) |
| Playground front-end | [`docs/PLAYGROUND.md`](docs/PLAYGROUND.md) |
| JetBrains IDE plugin contract | [`docs/JETBRAINS_PLUGIN.md`](docs/JETBRAINS_PLUGIN.md) |

---

## Table of contents

The long-form guide sections were moved into the docs tree; this index maps
each topic to where it actually lives. Every link is checked in CI.

- [Quick start](#quick-start) — install the phar, or from source
- [Key CLI flags](#key-cli-flags) — the everyday table
- [CLI reference](docs/CLI.md#cli-reference) — full flag and sub-command reference
- [Sub-commands](docs/CLI.md#sub-commands) — `analyze` / `serve` / `init` / `self:update` / `completion`
- [Tuning & Type-3 detection](docs/CLI.md#tuning) — thresholds, normalization modes, `--optional-blocks`, `--db-aware`, `--trinity-collapse`
- [Output formats](docs/CLI.md#output) — JSON, HTML, SARIF, GitLab SAST, diff, checkstyle, CSV, Prometheus, time-series, Graphviz, PlantUML, refactor patches, PHPUnit skeletons
- [File counters](docs/CLI.md#what-the-file-counters-mean) — scan signals, skips, exclude typos, parse failures
- [Runtime flags](docs/CLI.md#runtime) — workers, memory ceiling, cache toggles
- [Cache retention and pruning](docs/CLI.md#cache-retention-and-pruning) — content-addressed disk cache, documented rm-based reclaim
- [TUI and watch mode](docs/CLI.md#tui--watch) — `--tui`, `--theme`, `--watch`, keyboard shortcuts
- [CI baseline gate](docs/CLI.md#ci--baseline) — `--baseline` / `--baseline-out`, incremental exit-code 3 workflow
- [Config validation](docs/CLI.md#validation) — `--validate-config`
- [Self-update](docs/CLI.md#selfupdate--options) — `phpdup self:update` and the signed-manifest contract
- [Exit codes](docs/CLI.md#exit-codes) — 0 / 1 / 2 / 3, plus the SIGINT `130` soft-cancel convention
- [Configuration file](docs/CLI.md#configuration-file) — `phpdup.json` fields (+ machine-readable schema above)
- [Configuration precedence](docs/CLI.md#configuration-precedence) — per-directory overrides, project profiles
- [`exclude` glob dialect](docs/CLI.md#exclude-stacks--the-glob-dialect) — how exclude lists stack
- [Examples](docs/CLI.md#examples) — threshold-gated, CRUD, optional segments, strategy dispatch
- [`phpdup serve` REST API](docs/SERVER.md#routes) — health, sync/async analyze, job polling
- [Server security](docs/SERVER.md#security) — bind defaults, bearer token, path confinement
- [Clustering algorithm](docs/algorithms/clustering.md#clustering-algorithm) — phases, medoid-ball split, complexity
- [Anti-unification](docs/algorithms/anti-unification.md#anti-unification-algorithm) — hole typing
- [Architecture](docs/adr/0001-cooperative-pipeline.md) — cooperative-pipeline ADR (with 0002/0003 siblings)
- [CI integration](docs/CI.md) — composite action, GitLab/Jenkins/Circle/Travis recipes
- [Tutorials](docs/tutorials/refactoring-10k-lines.md) — 10k-line walkthrough (also `laravel.md`, `anti-unification-deep-dive.md`)
- [Benchmarks](bench/README.md) — comparative harness, feature matrix, committed raw results (`bench/results/`)
- [License](#license) — MIT

---

## License

MIT — see [`LICENSE`](LICENSE)
