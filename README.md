# phpdup — AST-based PHP duplicate-logic detector

> A semantic clone detector and refactoring assistant for PHP codebases.
> Behaves more like an "extract function" advisor than a copy/paste finder.

[![CI](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml/badge.svg)](https://github.com/detain/php-dup-finder/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/php-dup-finder/branch/master/graph/badge.svg)](https://app.codecov.io/gh/detain/php-dup-finder)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue.svg)](https://www.php.net)
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

```bash
# Download PHAR (recommended)
curl -sSLO https://github.com/detain/php-dup-finder/releases/latest/download/phpdup.phar
curl -sSLO https://github.com/detain/php-dup-finder/releases/latest/download/phpdup.phar.sha256
sha256sum --check phpdup.phar.sha256
chmod +x phpdup.phar && sudo mv phpdup.phar /usr/local/bin/phpdup

# Or via Composer (dev dependency)
composer require --dev detain/php-dup-finder

# Or from source
git clone https://github.com/detain/php-dup-finder.git && cd php-dup-finder && composer install
```

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

- [Features](docs/CLI.md#features) — semantic detection, type-3/4, parameter discovery, pattern tags
- [Installation](docs/CLI.md#installation) — PHAR, Composer, from source
- [Self-update](docs/CLI.md#self-update) — `phpdup self-update`
- [How it works](docs/CLI.md#how-it-works) — 5-stage pipeline, normalization modes, clustering, anti-unification
- [Type-3 / optional-segment detection](docs/CLI.md#type-3--optional-segment-detection)
- [Type-4 / behavioural similarity](docs/CLI.md#type-4--behavioural-similarity-experimental)
- [ORM- / DB-aware deduplication](docs/CLI.md#orm---db-aware-semantic-deduplication) — `--db-aware`, `--trinity-collapse`
- [TUI mode](docs/CLI.md#tui-mode) — `--tui`, `--theme`, keyboard shortcuts
- [Watch mode](docs/CLI.md#watch-mode) — `--watch`
- [SIGINT soft-cancel](docs/CLI.md#sigint-soft-cancel)
- [`phpdup serve` REST API](docs/SERVER.md) — health, sync/async analyze, job polling
- [Output formats](docs/CLI.md#output-formats) — JSON, HTML, SARIF, GitLab SAST, diff, checkstyle, CSV, Prometheus, time-series, Graphviz, PlantUML, refactor patches, PHPUnit skeletons
- [Configuration](docs/CLI.md#configuration) — `phpdup.json` schema, per-directory overrides, project profiles
- [Programmatic use](docs/CLI.md#programmatic-use) — use the pipeline from PHP
- [Examples](docs/CLI.md#examples) — threshold-gated, CRUD, optional segments, strategy dispatch
- [Static analysis & validation](docs/CLI.md#static-analysis--config-validation) — PHPStan, Psalm, `--validate-config`
- [Benchmarks](docs/CLI.md#benchmarks) — comparative suite, feature matrix, internal scaling
- [Architecture](docs/CLI.md#architecture) — module map
- [Testing](docs/CLI.md#testing) — PHPUnit suites, coverage
- [Performance](docs/CLI.md#performance) — complexity, caches
- [Roadmap](docs/CLI.md#roadmap)
- [FAQ](docs/CLI.md#faq)

---

## License

MIT — see [`LICENSE`](LICENSE)
