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
> already surface findings inline on the platform's own UI, and a threading
> bot would add auth-token / rate-limit baggage for no new signal.

## Getting the tool: the materialize step

The integrations on this page run **inside your repository**, and your
repository does not contain phpdup. The first editions of these templates
pretended otherwise: they ran `composer install` in your project (installing
*your* dependencies) and then executed a non-existent `bin/phpdup` — the
integration could not run anywhere but in phpdup's own checkout
(php-dup-finder-once-017, fixed by once-S14). Every template here now
**materializes the tool into a scratch dir** before executing it, and fails
loudly when materialization breaks (a missing tool is a hard error, never a
silent skip). The canonical sequence, identical in the GitHub composite
action and the GitLab template:

```bash
TOOL_HOME="${TMPDIR:-/tmp}/phpdup-tool"           # $RUNNER_TEMP on Actions
rm -rf "$TOOL_HOME" && mkdir -p "$TOOL_HOME"
for ref in "refs/tags/$PHPDUP_VERSION" "refs/heads/$PHPDUP_VERSION" "$PHPDUP_VERSION"; do
  curl -fsSL --retry 3 -o "$TOOL_HOME/tool.zip" \
    "https://codeload.github.com/detain/php-dup-finder/legacy.zip/${ref}" && break
done
unzip -q "$TOOL_HOME/tool.zip" -d "$TOOL_HOME/src"
SRC=$(find "$TOOL_HOME/src" -mindepth 1 -maxdepth 1 -type d | head -n1)
composer install --no-dev --no-scripts --no-interaction --no-progress \
  --prefer-dist --working-dir "$SRC"
PHPDUP_BIN="$SRC/bin/phpdup"
php "$PHPDUP_BIN" --version                        # smoke; failure aborts the job
```

Why a **source zip** and not the other delivery channels, per the verified
publication state in [docs/RELEASE.md](RELEASE.md) ("Current publication
status"):

- **Packagist / `composer require`** is not a supported integration route:
  the `sugarcraft/*` deps are pinned `dev-master#<sha>` and pinned dev
  versions do not resolve transitively. (The zip route sidesteps this —
  inside the extracted tree those pins *are* root requirements, which is why
  `composer install --no-dev` there resolves.)
- **The `phpdup.phar` release asset** is the planned stable route; its URLs
  404 until the first `v*` tag runs through the release workflow. The GitHub
  composite already carries a `tool: phar` mode for that day; the download
  verifies the `phpdup.phar.sha256` sidecar (GNU coreutils two-space format,
  once-S12) when present and runs the artifact via `php`, not exec.
- **Do not pin `v1.0.0`**: that tag predates the once-S11 pin migration, and
  its code references the renamed `SugarCraft\*` packages — it installs but
  fatals at report time (`Class "SugarCraft\Kit\Theme" not found`, observed
  from the Packagist dist at once-S14). `master` (the default) or a SHA at/
  after that migration are the runnable refs today.

Extension truth (once-S10): `hash` is core, `mbstring` is required, `gmp` is
an optional accelerator (byte-table fallback when absent) — CI images install
all three anyway because that is what this repo's own green matrix runs.

## GitHub Actions

### Repository CI

`.github/workflows/ci.yml` tests PHP **8.3, 8.4 and 8.5** on
`ubuntu-latest`, with the 8.3 leg carrying coverage, plus a static-analysis
job (PHPStan + Psalm) on 8.3. The 8.1/8.2 legs were dropped: every required
`sugarcraft/*` package (and phpdup itself) declares `"php": "^8.3"`, so
`composer install` cannot resolve below 8.3 — those legs could never pass.
The 8.5 leg needs a dev-resolvable toolchain: Psalm is pinned `6.20.9`, whose
`require.php` includes `~8.5.0` (the previous `6.0.0` pin capped at `~8.4.0`
and blocked the whole 8.5 install), and PHPStan's `^2.1` resolves to ≥ 2.1.32,
the first release with full PHP 8.5 support. Golden snapshots regenerate with
`serialize_precision=-1` pinned in `phpunit.xml` and whole-number score fields
emitted as JSON ints (`Num::json`), so snapshot bytes are box- and
version-independent; finding ids hash member paths relative to the configured
scan roots, so the same cluster mints the same id on any machine.

The Psalm leg is green at current master: `psalm-baseline.xml` was re-derived
against the pinned 6.20.9 by the sanctioned re-baseline lane (output-S01,
14 → 5 suppressed occurrences) and `vendor/bin/psalm --no-cache` exits 0
against HEAD (re-verified for this document; 98.63 % type inference). The
earlier "9 InvalidArgument errors, red leg awaiting an owner ruling" state
described the pre-re-baseline era and no longer applies. Failure tolerance:
no test leg swallows failures — the matrix runs `fail-fast: false` (a red leg
does not cancel its siblings but still fails the workflow), and the only
`continue-on-error` steps are infrastructure: the Codecov upload in ci.yml
and the SARIF upload inside the consumer composite action
(`.github/actions/phpdup/action.yml`).

### Consumer composite action

A maintained composite action lives at `.github/actions/phpdup/action.yml` in
this repo. From your workflow:

```yaml
- name: phpdup
  uses: detain/php-dup-finder/.github/actions/phpdup@master
  with:
    paths: 'src lib'
    version: master          # or a release tag / full SHA once those exist
    min-impact: 20
    fail-on-impact: 100      # fail (exit 3) if any cluster's impact ≥ 100
    upload-sarif: true       # default; surfaces findings in the Security tab
```

The action:

- Pins PHP via `shivammathur/setup-php` (default 8.3 — phpdup requires
  `^8.3`; configurable) with `hash, mbstring, gmp`.
- **Materializes phpdup before executing it** (see the section above):
  `tool: zip` (default) fetches the source zip of the `version` ref and runs
  `composer install --no-dev` inside it, in `$RUNNER_TEMP`; `tool: phar`
  fetches the release's `phpdup.phar` asset, verifies the sidecar checksum
  when one ships, and runs `php phpdup.phar`. Your own `composer.json` /
  vendor dir is never involved, and your tree is never mutated.
- Caches Composer downloads keyed on the **tool** install
  (`phpdup-composer-${PHP}-${tool}-${version}`) and the phpdup AST/index dir
  (`phpdup-cache-${OS}-…`) so successive runs reuse parsed ASTs.
- Generates **all** report formats: JSON, SARIF, HTML, Checkstyle, CSV,
  Prometheus, timeseries-JSONL — each is uploaded as a workflow artifact.
- Runs the tool under `set -euo pipefail` and **propagates phpdup's original
  exit code** into the job (see the table below), with a `::error::` line
  naming what the code means. The analyze pipeline sits in an if-condition
  head so the mapping still executes under the runner's implicit errexit
  (`bash --noprofile --norc -eo pipefail`).
- Optionally uploads SARIF to GitHub Code Scanning so findings appear under
  *Security → Code scanning alerts*.
- Optionally gates on impact: `fail-on-impact: N` fails the step with
  **exit 3** when any cluster's impact ≥ N — the per-cluster maximum. This is
  the ONE canonical `--fail-on-impact` formula, identical on both surfaces:
  the CLI's own `--fail-on-impact N` gate (available through the tool's
  standard flags in the GitLab/Jenkins/… snippets) uses the same per-cluster
  maximum with `>=`, not a total (the two once disagreed — unified at
  once-S15; the action re-checks the JSON report in its own step only so
  pinned tool refs without the native CLI flag still gate). A missing JSON
  report while gating is ON is an error, not a pass; the old silent
  `exit 0` when `jq` was unavailable is gone — the gate and the
  `clusters` output parse the JSON with PHP itself, so no external `jq` is
  required anywhere in the action anymore.

### Outputs

| Output     | Meaning                                    |
|------------|--------------------------------------------|
| `json`     | Path to JSON report                        |
| `sarif`    | Path to SARIF report                       |
| `html`     | Path to HTML report directory              |
| `clusters` | Cluster count from the run (no `jq` needed) |

## Exit-code mapping (all platforms)

Per the contract in [docs/CLI.md](CLI.md) — verified live against the
materialized tool at once-S14 (unknown option → 1, malformed config → 2,
`--fail-on-impact` over budget → 3, clean run with findings → 0):

| Code | Meaning                              | GitHub action             | GitLab job              |
|------|--------------------------------------|---------------------------|-------------------------|
| `0`  | Success **including findings**       | continues to artifacts    | job passes              |
| `1`  | Runtime error / unknown option       | step fails with `::error` | job fails               |
| `2`  | Invalid configuration                | step fails with `::error` | job fails               |
| `3`  | CI gate tripped (`--fail-on-impact`, `--max-clusters`) | step fails with `::error` | job fails   |
| `4`  | Baseline found new clusters (`--baseline`) | step fails with `::error` | job fails    |

A scan that merely *finds* clones exits 0 — gate on the report, or set a
gate flag, exactly as documented for the CLI.

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

- **Materializes the tool into scratch** (`/tmp/phpdup-tool`) from the
  `PHPDUP_VERSION` ref's source zip and runs `composer install --no-dev`
  there, then smoke-tests it; any failure aborts the job. Your checkout is
  only scanned.
- The `php:8.3-cli` base image already ships `mbstring` but **not** `gmp`
  (and its trixie edition has no libgmp headers, so the snippet apt-installs
  `libgmp-dev` before `docker-php-ext-install` — verified in a container at
  once-S14). The extension step installs whatever of `mbstring gmp` the
  chosen image lacks, and hard-errors if a non-official image has neither
  `mbstring` nor `docker-php-ext-install`.
- Variables: `PHPDUP_PATHS`, `PHPDUP_MIN_IMPACT`, `PHPDUP_WORKERS`,
  `PHPDUP_PHP_IMAGE`, `PHPDUP_VERSION` (materialized ref, default `master`),
  `PHPDUP_FAIL_ON_IMPACT` (native CLI gate — canonical per-cluster maximum,
  trip at ≥ N, default `0` = off; a
  trip exits 3 and fails the job). Runners behind GitHub's anonymous API
  ceilings: set `GITHUB_AUTH_TOKEN` (masked CI variable — Composer honors
  it), pin `PHPDUP_VERSION`, and add a composer `cache:` to the job;
  the dependency pins are `dev-master#<sha>`-exact, so cached resolutions
  never drift. The composite action auto-passes its workflow token the
  same way (`GITHUB_AUTH_TOKEN: ${{ github.token }}`).
- Emits the GitLab SAST v15 report at `gl-sast-report.json` (registered under
  `artifacts:reports:sast` **and** kept in `paths:`) so findings appear in
  the **MR Security tab → Vulnerability Report**.
- Publishes an HTML report under `public/phpdup/` — combine with the
  `pages` job to expose it as a browseable per-cluster site.
- Triggers on MRs and on the default branch by default; tweak `rules:`
  to fit your team's policy.

No MR comment threading — the SAST surface covers the same use case
without the auth-token / rate-limit baggage.

## Jenkins

The Warnings NG plugin reads phpdup's Checkstyle output. Materialize first
(`sh` steps share their shell within one `sh` call — export the path once):

```groovy
stage('phpdup') {
  steps {
    sh '''
      TOOL=/tmp/phpdup-tool
      rm -rf "$TOOL" && mkdir -p "$TOOL"
      curl -fsSL -o "$TOOL/tool.zip" https://codeload.github.com/detain/php-dup-finder/legacy.zip/refs/heads/master
      unzip -q "$TOOL/tool.zip" -d "$TOOL/src"
      SRC=$(find "$TOOL/src" -mindepth 1 -maxdepth 1 -type d | head -n1)
      composer install --no-dev --no-scripts --no-interaction --working-dir "$SRC"
      php "$SRC/bin/phpdup" analyze src \
        --min-impact 20 \
        --json out/phpdup.json \
        --checkstyle out/phpdup-checkstyle.xml
    '''
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
      - run: |
          TOOL=/tmp/phpdup-tool
          rm -rf "$TOOL" && mkdir -p "$TOOL"
          curl -fsSL -o "$TOOL/tool.zip" https://codeload.github.com/detain/php-dup-finder/legacy.zip/refs/heads/master
          unzip -q "$TOOL/tool.zip" -d "$TOOL/src"
          SRC=$(find "$TOOL/src" -mindepth 1 -maxdepth 1 -type d | head -n1)
          composer install --no-dev --no-scripts --no-interaction --working-dir "$SRC"
          mkdir -p reports
          php "$SRC/bin/phpdup" analyze src \
            --min-impact 20 \
            --json reports/phpdup.json \
            --checkstyle reports/phpdup-checkstyle.xml
      - store_artifacts: { path: reports }
      - store_test_results: { path: reports }
```

(`cimg/php` images carry `mbstring`; add `sudo apt-get install -y libgmp-dev`
+ pecl-style setup only if you want the `gmp` accelerator.)

## Travis CI

```yaml
language: php
php: '8.3'
install:
  - curl -fsSL -o /tmp/phpdup.zip https://codeload.github.com/detain/php-dup-finder/legacy.zip/refs/heads/master
  - mkdir -p /tmp/phpdup-src && unzip -q /tmp/phpdup.zip -d /tmp/phpdup-src
  - SRC=$(find /tmp/phpdup-src -mindepth 1 -maxdepth 1 -type d | head -n1)
  - composer install --no-dev --no-scripts --no-interaction --working-dir "$SRC"
script:
  - php "$SRC/bin/phpdup" analyze src
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

## Local dry-run plan (manual check rows)

Both workflow templates are plain shells; the plan below mirrors every step on
one machine (it is what once-S14 executed before landing; adapt paths).

1. **Composite action mirror** — with `RUNNER_TEMP` pointing at a scratch
   dir, run the canonical materialize block from *Getting the tool*, then the
   exact analyze line the action assembles:

   ```bash
   php "$PHPDUP_BIN" analyze src --min-impact 20 --workers 0 \
     --json phpdup-report.json --sarif phpdup-report.sarif --html phpdup-report \
     --checkstyle phpdup-checkstyle.xml --csv phpdup-report.csv \
     --prometheus phpdup-metrics.prom --timeseries phpdup-history.jsonl --plain
   ```

   Expect exit 0 on a clean/finding scan, the seven report files present, and
   `php -r '$j=json_decode(file_get_contents("phpdup-report.json"),true); echo $j["summary"]["clusters"];'`
   to print the count the action exposes as its `clusters` output.
2. **Gate legs** — `--fail-on-impact 1` against any corpus with clones must
   exit 3; the action's `fail-on-impact` input enforces the same canonical
   per-cluster-maximum formula, exits 3 on a max-impact hit and exits 1 when
   the JSON report is missing or unparseable while gating.
3. **GitLab mirror** — drive the template's `before_script` + `script` strings
   verbatim inside the default image against a fixture project:

   ```bash
   python3 - "$repo" <<'PY'
   import sys, yaml
   d = yaml.safe_load(open(f"{sys.argv[1]}/.gitlab/phpdup-ci.yml"))
   open("/tmp/step.sh", "w").write("set -e\n" + "\n".join(d["phpdup"]["before_script"] + d["phpdup"]["script"]))
   PY
   docker run --rm -v "$PWD":/e2e -e PHPDUP_PATHS=src -e PHPDUP_MIN_IMPACT=20 \
     -e PHPDUP_WORKERS=0 -e PHPDUP_VERSION=master -e PHPDUP_FAIL_ON_IMPACT=0 \
     php:8.3-cli bash -c 'cd /e2e/project && bash /e2e/step.sh'
   ```

   Expect: apt + ext step green, tool materialized + `--version` smoke, the
   analyze run green, `gl-sast-report.json` (and the other artifact paths)
   present afterwards.
4. **YAML parse gate** — `python3 -c 'import yaml; yaml.safe_load(open(".github/actions/phpdup/action.yml"))'`
   and the same for `.gitlab/phpdup-ci.yml` must exit 0; both files are
   additionally parse-checked by any consumer that `include:`s or `uses:`
   them (this repo ships no such consumer — there is no root `.gitlab-ci.yml`
   and no workflow runs the composite — so the manual gate above is the only
   one that executes here).

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
