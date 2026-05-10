# phpdup on Laravel projects

Laravel codebases benefit from phpdup's framework-aware tags
(`controller-action`, `migration`, `eloquent-model`,
`repository-method`, `event-listener`, `service-provider`). The
recipes below assume a stock Laravel layout.

## Recommended config

`phpdup.json` at the repo root:

```json
{
  "paths":   ["app", "config", "database/factories"],
  "exclude": [
    "vendor/**",
    "node_modules/**",
    "bootstrap/cache/**",
    "storage/**",
    "database/migrations/**"
  ],
  "min_block_size": 8,
  "min_cluster_impact": 30,
  "kinds": ["method", "function"],
  "sort": "safety:desc"
}
```

Why exclude migrations? Laravel migrations are intentionally
duplicated by design (each is a self-contained schema delta).
phpdup's `migration` tag flags them, but you usually want them
filtered out entirely.

## Targeted runs

```bash
# Just the controllers — usually the highest-leverage refactor target.
phpdup analyze app/Http/Controllers --json controllers.json

# Just the repositories — find duplicated query-builder chains.
phpdup analyze app/Repositories --json repositories.json
```

The `controller-action` and `query-builder-chain` tags will surface
in the JSON `pattern_tags` array per cluster.

## Service-provider boilerplate

Laravel's `register()` and `boot()` methods are notorious for
duplication. phpdup's `service-provider` + `container-registration`
tags catch them:

```bash
phpdup analyze app/Providers --pattern-detection
```

The clusters typically have `config-driven` holes — extract a
single shared registration helper.

## Detecting "fat controllers"

The `controller-action` + `srp-mixed-concerns` finding combination
flags controllers that mix DB calls and side-effect calls (mailer,
event dispatch). Filter the JSON for them:

```bash
phpdup analyze app/Http/Controllers --json out.json
jq '.clusters[] | select(.architectural_findings[]?.code == "srp-mixed-concerns")' out.json
```

The output is a queue of high-leverage refactor candidates: extract
the side-effect path into a service / job class.

## CI gating

The provided GitHub Action gates against new clusters:

```yaml
- uses: detain/php-dup-finder/.github/actions/phpdup@master
  with:
    paths: app
    config: phpdup.json
    fail-on-impact: 100
```

`fail-on-impact: 100` is a sensible Laravel default — most existing
duplication will be 50–80 impact, so the gate only trips on
genuinely-new big clusters.
