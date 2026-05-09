# CI integration

phpdup ships first-class drop-in templates for the major CI platforms. Each
emits machine-readable artifacts that the platform's existing surfaces can
consume, so you don't have to roll your own report parsing.

| Platform        | Surface                                           | Artifact                  |
|-----------------|---------------------------------------------------|---------------------------|
| GitHub Actions  | Code Scanning (Security tab)                      | SARIF 2.1.0               |
| GitLab CI       | Vulnerability Report (MR Security tab)            | GitLab SAST v15           |
| Jenkins         | Warnings NG                                       | Checkstyle XML            |
| CircleCI        | Native test summaries (via Checkstyle conversion) | Checkstyle XML            |
| Travis CI       | Build log + downloadable artifacts                | JSON / HTML               |
| Anywhere        | Time-series dashboards (Prom / BigQuery / etc.)   | Prometheus / JSONL        |

> 🤖 **Native PR/MR comment threading** is **NOT** in scope. SARIF / SAST
> already surface findings inline on the platform's own UI; see the
> "Deferred — GitLab/GitHub native integration" entry in `UPDATE_PLAN.md`.

## GitHub Actions

A maintained composite action lives at `.github/actions/phpdup/action.yml` in
this repo. From your workflow:

```yaml
- name: phpdup
  uses: detain/php-dup-finder/.github/actions/phpdup@master
  with:
    paths: 'src lib'
    min-impact: 20
    fail-on-impact: 100      # exit non-zero if any cluster's impact ≥ 100
    upload-sarif: true       # default; surfaces findings in the Security tab
```

The action:

- Pins PHP via `shivammathur/setup-php` (default 8.3; configurable).
- Caches Composer downloads under
  `phpdup-composer-${PHP}-${hash(composer.json)}`.
- Caches the phpdup AST/index dir under `phpdup-cache-${OS}-${SHA}` so
  successive runs on the same branch reuse parsed ASTs.
- Generates **all** report formats: JSON, SARIF, HTML, Checkstyle, CSV,
  Prometheus, timeseries-JSONL — each is uploaded as a workflow artifact.
- Optionally uploads SARIF to GitHub Code Scanning so findings appear under
  *Security → Code scanning alerts*.
- Optionally fails the job when any cluster's impact crosses
  `fail-on-impact`. Requires `jq` on the runner (preinstalled on
  `ubuntu-latest`).

### Outputs

| Output     | Meaning                                    |
|------------|--------------------------------------------|
| `json`     | Path to JSON report                        |
| `sarif`    | Path to SARIF report                       |
| `html`     | Path to HTML report directory              |
| `clusters` | Cluster count from the run (requires `jq`) |

## GitLab CI

Drop-in pipeline snippet at `.gitlab/phpdup-ci.yml`. From your project's
`.gitlab-ci.yml`:

```yaml
include:
  - remote: https://raw.githubusercontent.com/detain/php-dup-finder/master/.gitlab/phpdup-ci.yml

variables:
  PHPDUP_PATHS: "src lib"
  PHPDUP_MIN_IMPACT: "20"
```

- Emits the GitLab SAST v15 report at `gl-sast-report.json` so findings
  appear in the **MR Security tab → Vulnerability Report**.
- Publishes an HTML report under `public/phpdup/` — combine with the
  `pages` job to expose it as a browseable per-cluster site.
- Triggers on MRs and on the default branch by default; tweak `rules:`
  to fit your team's policy.

No MR comment threading — the SAST surface covers the same use case
without the auth-token / rate-limit baggage.

## Jenkins

The Warnings NG plugin reads phpdup's Checkstyle output:

```groovy
stage('phpdup') {
  steps {
    sh 'composer install --no-interaction --prefer-dist'
    sh 'php bin/phpdup analyze src \
          --min-impact 20 \
          --json out/phpdup.json \
          --checkstyle out/phpdup-checkstyle.xml'
  }
  post {
    always {
      recordIssues(
        tools: [checkStyle(pattern: 'out/phpdup-checkstyle.xml')],
        qualityGates: [[threshold: 1, type: 'TOTAL', criticality: 'UNSTABLE']],
      )
      archiveArtifacts artifacts: 'out/phpdup.json'
    }
  }
}
```

## CircleCI

```yaml
version: 2.1

jobs:
  phpdup:
    docker: [{ image: cimg/php:8.3 }]
    steps:
      - checkout
      - run: composer install --no-interaction --prefer-dist
      - run: |
          mkdir -p reports
          php bin/phpdup analyze src \
            --min-impact 20 \
            --json reports/phpdup.json \
            --checkstyle reports/phpdup-checkstyle.xml
      - store_artifacts: { path: reports }
      - store_test_results: { path: reports }
```

## Travis CI

```yaml
language: php
php: '8.3'
install:
  - composer install --no-interaction --prefer-dist
script:
  - php bin/phpdup analyze src
      --min-impact 20
      --json phpdup.json
      --html phpdup-report
after_success:
  - tar czf phpdup-artifacts.tgz phpdup.json phpdup-report/
deploy:
  provider: releases
  file: phpdup-artifacts.tgz
  on: { tags: true }
```

## Time-series dashboards

The `--prometheus` and `--timeseries` reporters target two different
ingest paths:

- **Prometheus** — push the `*.prom` file to a pushgateway in CI, scrape
  it via your existing Prometheus + Grafana stack. Useful for displaying
  cluster count / total impact over time on a real-time dashboard.
- **Timeseries JSONL** — append the per-run line to a long-lived JSONL
  file (per branch / per repo) and ingest into ClickHouse, BigQuery, or
  Elasticsearch for ad-hoc analysis. Each row carries the commit SHA, a
  timestamp, and the corpus shape.
