---
name: add-cli-flag
description: Adds a new CLI flag in src/Cli/Command.php::configure() and threads it through ConfigLoader (load + validate + shapeOverrides), Config constructor (with range check), docs/config-schema.json, docs/CLI.md, and ConfigLoaderTest. Use when the user says 'add flag', 'new CLI option', 'add --<name>', or 'expose <config> on the command line'. Enforces the kebab-case CLI / snake_case override-key / camelCase Config-property naming convention and the four flag groups (Detection tuning, Output/reports, Performance/runtime, Interactive/UI). Do NOT use for renaming an existing flag (breaking change — propose an alias instead) or for adding a new subcommand (different scaffold under SymfonyCommand).
---

# add-cli-flag

Adds a new CLI flag to `bin/phpdup analyze` and threads it through the full config stack: CLI parsing → overrides dict → JSON config loader → validation → typed `Config` property → docs → tests.

## Critical

- **Naming convention is non-negotiable** across the three layers:
  - CLI flag: `--kebab-case` (e.g. `--min-impact`, `--ml-pair-url`)
  - Override key & JSON config key: `snake_case` (e.g. `min_impact`, `ml_pair_url`)
  - `Config` property: `camelCase` (e.g. `$minClusterImpact`, `$mlPairUrl`)
- **Every layer must be updated**. Skipping any one of these silently breaks the flag:
  1. `src/Cli/Command.php` — `configure()` adds the option, `buildGroupedHelp()` lists it, `execute()` threads it into `$overrides`
  2. `src/Cli/ConfigLoader.php` — `load()` reads it, `validate()` asserts type/range, `shapeOverrides()` maps JSON → overrides for per-directory `.phpdup.json`
  3. `src/Cli/Config.php` — typed `readonly` constructor parameter + range/enum check in constructor body
  4. `docs/config-schema.json` — JSON-Schema entry under `properties`
  5. `docs/CLI.md` — row in the matching group's table
  6. `tests/Unit/Cli/ConfigLoaderTest.php` — at minimum one accept + one reject test
- **Range validation goes in BOTH `Config::__construct` (defensive) AND `ConfigLoader::validate` (early failure with field-path)**. They must agree. Mismatches mean the CLI accepts a value that `Config` then rejects mid-pipeline.
- **Never stage `composer.lock`** (gitignored). **Never widen psalm/phpstan scope to `tests/Fixtures/`** (intentionally malformed).
- **PHP 8.1 floor** — no `:null` standalone return; use `?T` or omit.

## Instructions

### Step 1 — Confirm the flag is genuinely new and pick the group

Grep for the proposed flag name to confirm it does not already exist:

```bash
Grep pattern="addOption\('<kebab-name>'" path="src/Cli/Command.php"
```

Decide which of the four groups in `Command.php::configure()` the flag belongs to (the comment banners delimit them):

- **Configuration** — config-file plumbing (`--config`, `--validate-config`)
- **Detection tuning** — affects clustering/normalization results
- **Output / reports** — emits files or changes report rendering
- **Performance / runtime** — caching, workers, memory ceilings, staging
- **Interactive / UI** — TUI, theme, watch mode

Verify: the group decision will dictate where you call `addOption()` AND which table row to add in `docs/CLI.md` (`### Tuning`, `### Output`, `### Runtime`, `### TUI / watch`).

### Step 2 — Register the option in `src/Cli/Command.php::configure()`

Append the `->addOption(...)` call to the matching group's chained block. Use the existing surrounding options as a template — `--min-block-size`, `--ml-pair-url`, etc. Patterns:

- `InputOption::VALUE_NONE` — boolean flag (no argument). Pair with `if ($input->getOption('flag')) { $overrides['flag_key'] = true; }` in `execute()`.
- `InputOption::VALUE_REQUIRED` — takes a value. Use `, default` 5th argument ONLY when the default belongs in argv (rare — `Config::defaults()` is the source of truth; leave the 5th arg off so `array_filter(..., fn($v) => $v !== null)` correctly skips it).
- Short name (2nd arg) — only for high-traffic flags (`-c` config, `-j` workers). Default to `null`.

Description string conventions seen in this file:
- Lead with the *what*, not the type. "Jaccard similarity threshold (0..1)" not "A float between 0 and 1."
- For new tiers / opt-in modes, include "Off by default" and link the design doc (`docs/plans/...`).
- For URL inputs, mention SSRF hardening (`http(s) only`).

Then update `buildGroupedHelp()` — add the flag to the matching `<comment>...</comment>` block. Order matches `addOption()` order so the auto-generated `--help` also stays grouped.

Verify: `bin/phpdup analyze --help` shows the flag under the right heading. Do not proceed if it appears in the wrong group.

### Step 3 — Thread the flag through `Command::execute()`

Uses Step 2's flag name. Two patterns in the existing code:

**Simple scalar / string flag** — extend the `$overrides = array_filter([...], fn($v) => $v !== null);` block:

```php
$overrides = array_filter([
    // ...
    'my_new_key' => $input->getOption('my-new-flag'),
], fn($v) => $v !== null);
```

**Boolean (`VALUE_NONE`)** — conditional set, AFTER the `array_filter` block:

```php
if ($input->getOption('my-flag')) {
    $overrides['my_flag'] = true;
}
```

**Enum / validated string** — explicit validate-then-set, returning exit code 2 on failure (mirror `--scorer` at `src/Cli/Command.php:176-184`):

```php
$opt = $input->getOption('my-flag');
if ($opt !== null) {
    $opt = strtolower((string)$opt);
    if (!in_array($opt, ['a', 'b'], true)) {
        $output->writeln('<error>phpdup: --my-flag must be one of a|b</error>');
        return 2;
    }
    $overrides['my_flag'] = $opt;
}
```

**Float in 0..1** — cast in `execute()`, range check happens in `Config::__construct` (see Step 5):

```php
$opt = $input->getOption('my-threshold');
if ($opt !== null) {
    $overrides['my_threshold'] = (float)$opt;
}
```

Verify: only set the override when the user passed the flag — leaving the override unset is what lets `Config::defaults()` win.

### Step 4 — Wire `ConfigLoader::load()` and `validate()` in `src/Cli/ConfigLoader.php`

**4a.** In `load()`, add a new line in the `new Config(...)` constructor call. Use the `$overrides[...] ?? $data[...] ?? $base->...` chain (NOT the `$get()` helper — the inline chain matches the newer flags like `mlPairUrl`):

```php
myNewKey: (float)($overrides['my_new_key'] ?? $data['my_new_key'] ?? $base->myNewKey),
```

Keep this in the same vertical position as your readonly param in `Config.php` (Step 5) for diffability.

**4b.** In `validate()`, add the snake_case key to the `$known` whitelist array (~line 261 of `ConfigLoader.php`). Order: append to the end. Without this, `phpdup analyze --config phpdup.json --validate-config` rejects the key with `Unknown config key '...'`.

**4c.** In `validate()`, add the type/range assertion below the existing checks. Use the existing helpers:

- Integer with `>= N`: `$assertInt($data['key'], 'key', 0);`
- Float in `[0, 1]`: `$assertFloat01($data['key'], 'key');`
- Enum: `$assertEnum($data['key'], 'key', ['a', 'b']);`
- Boolean: `if (array_key_exists('key', $data) && !is_bool($data['key'])) { throw new \RuntimeException("key must be a boolean$where"); }`
- Non-empty string: `if (!is_string($data['key']) || $data['key'] === '') { throw new \RuntimeException("key must be a non-empty string$where"); }`
- URL: reuse `\Phpdup\Ml\MlClient::isAllowedUrl(...)` (see `ml_pair_url` block at ConfigLoader.php:448).

Always guard with `if (array_key_exists('key', $data)) { ... }` — keys are optional.

**4d.** If the flag also makes sense in per-directory `.phpdup.json` overrides, add it to `shapeOverrides()` (~line 212):

```php
if (array_key_exists('my_new_key', $data)) {
    $out['my_new_key'] = $data['my_new_key'];
}
```

For flat scalar keys, the simplest pattern is to add the key to the existing `foreach (['min_block_size', ...] as $k)` list.

Verify: `php -r "require 'vendor/autoload.php'; (new Phpdup\\Cli\\ConfigLoader())->validate(['my_new_key' => <bad-value>]);"` raises the expected message.

### Step 5 — Add the property to `src/Cli/Config.php`

Append a `public readonly` constructor parameter at the END of the existing parameter list (before the closing `) {`). Match the canonical default from your design (NOT a placeholder).

```php
// Doc comment: what + why + CLI flag + default behavior.
public readonly float $myNewKey = 0.85,
```

Then add the range/enum check inside the constructor body, AFTER the existing block of `throw new \InvalidArgumentException` checks (Config.php:137-177). The validation here mirrors `ConfigLoader::validate()` — both must agree. Pattern:

```php
if ($myNewKey < 0 || $myNewKey > 1) {
    throw new \InvalidArgumentException("my_new_key out of range");
}
```

Verify: `composer install --no-interaction && vendor/bin/phpstan analyse` passes. The `psalm-baseline.xml` MUST stay clean — fix new errors, do not baseline them.

### Step 6 — Document in `docs/config-schema.json`

Add a JSON-Schema entry under `properties`. Use the surrounding entries as a template (`scorer`, `ir_threshold`, `ml_pair_url`):

```json
"my_new_key": {
    "type": "number",
    "minimum": 0.0,
    "maximum": 1.0,
    "default": 0.85,
    "description": "What it does, when to use it. CLI: --my-new-flag."
}
```

The `description` must end with `CLI: --<flag>.` so users can grep either name. Keep the file `additionalProperties: false` invariant intact — do not edit that line.

### Step 7 — Document in `docs/CLI.md`

Add a row to the table under the heading matching Step 1's group (`### Tuning`, `### Output`, `### Runtime`, `### TUI / watch`, `### Validation`). Match the existing column count (`| Option | Default | Description |`).

```markdown
| `--my-new-flag N`                          | `0.85`         | One-line description; reference design doc if applicable. |
```

### Step 8 — Cover with a unit test

Append at minimum one accept + one reject test to `tests/Unit/Cli/ConfigLoaderTest.php`. Mirror the existing minimal-fixture style (no `setUp`, no shared state):

```php
public function testValidateAcceptsMyNewKey(): void
{
    (new ConfigLoader())->validate(['my_new_key' => 0.5]);
    $this->expectNotToPerformAssertions();
}

public function testRejectsMyNewKeyOutOfRange(): void
{
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('my_new_key must be in [0, 1]');
    (new ConfigLoader())->validate(['my_new_key' => 1.5]);
}
```

If the flag also passes through `Config` (e.g. for boolean composition), add a `tests/Unit/Cli/ConfigTest.php` test that constructs `Config::defaults([...])` and asserts the property value.

Verify all three suites pass:

```bash
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse
vendor/bin/psalm
```

### Step 9 — If the flag affects pipeline behavior, refresh Golden snapshots

If the new flag's *default* changes the canonical output of any pipeline run (rare — most new flags ship `off`/`default`), regenerate the Golden suite:

```bash
UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden
```

Review the diff in `tests/Golden/*.json` BEFORE committing — unexpected churn means the default is wrong.

Never stage `composer.lock` (gitignored, line 2 of `.gitignore`).

## Examples

**User says:** "Add a `--max-cluster-members N` flag (int, ≥ 1, default 100) that drops clusters with more than N members from the report."

**Actions taken:**

1. Group: **Detection tuning** (affects what gets reported, similar to `--min-impact`).
2. `src/Cli/Command.php::configure()` — append `->addOption('max-cluster-members', null, InputOption::VALUE_REQUIRED, 'Drop clusters with more than N members from the report. Default: no cap.')` in the Detection-tuning group.
3. `buildGroupedHelp()` — add `--max-cluster-members` to the Detection-tuning comment block.
4. `Command::execute()` — add `'max_cluster_members' => $input->getOption('max-cluster-members')` to the `array_filter` block, cast `(int)` at `Config` consumption.
5. `ConfigLoader::load()` — add `maxClusterMembers: (int)($overrides['max_cluster_members'] ?? $data['max_cluster_members'] ?? $base->maxClusterMembers),`.
6. `ConfigLoader::validate()` — append `'max_cluster_members'` to `$known`, then `if (array_key_exists('max_cluster_members', $data)) { $assertInt($data['max_cluster_members'], 'max_cluster_members', 1); }`.
7. `ConfigLoader::shapeOverrides()` — add `'max_cluster_members'` to the simple-scalar `foreach` list.
8. `Config.php` — add `public readonly int $maxClusterMembers = 100,` plus `if ($maxClusterMembers < 1) { throw new \InvalidArgumentException("max_cluster_members must be >= 1"); }`.
9. `docs/config-schema.json` — add `"max_cluster_members": { "type": "integer", "minimum": 1, "default": 100, "description": "...CLI: --max-cluster-members." }`.
10. `docs/CLI.md` — add row to the `### Tuning` table.
11. `tests/Unit/Cli/ConfigLoaderTest.php` — accept + below-min reject tests.
12. Run `vendor/bin/phpunit --testsuite Unit && vendor/bin/phpstan analyse && vendor/bin/psalm`.

**Result:** `bin/phpdup analyze src --max-cluster-members 50` parses; `phpdup analyze --config bad.json --validate-config` (with `max_cluster_members: 0`) exits 2 with `max_cluster_members must be >= 1 in bad.json`.

## Common Issues

- **`Unknown config key 'my_new_key' in phpdup.json`** when running `--validate-config`. Cause: you forgot Step 4b (adding the key to `$known` in `ConfigLoader::validate()`). Fix: append the snake_case key to the `$known` array at `src/Cli/ConfigLoader.php:261-283`.

- **`InvalidArgumentException: my_new_key out of range`** thrown from `Config::__construct` when the CLI accepted the value. Cause: `Config` and `ConfigLoader::validate` disagree on bounds. Fix: align the two — copy the exact `< / >` comparison from Step 5 into Step 4c and vice versa.

- **Flag works on CLI but is ignored from `phpdup.json`.** Cause: missing `?? $data['my_new_key']` middle term in the `ConfigLoader::load()` chain. Fix: every entry must follow the 3-stage chain `$overrides['k'] ?? $data['k'] ?? $base->camelK`.

- **Flag works from CLI / root config but not from a nested `.phpdup.json`.** Cause: missing entry in `ConfigLoader::shapeOverrides()` (Step 4d). Per-directory configs route through that method, not `load()` directly.

- **`Phpdup\Cli\Config::__construct(): Argument #N must be of type ...`** during tests. Cause: a test calls `new Config(paths: [...])` and a NEW required positional has been wedged into the middle of the constructor. Fix: always APPEND your readonly param to the end of the parameter list — never insert in the middle, even for grouping.

- **`failOnWarning`/`failOnNotice` tripping a previously green test** after the change. Cause: PHPUnit configuration treats all warnings/notices as failures (`phpunit.xml`). Fix the root cause (uninitialised property access, `(float)` cast on `null`) — do not suppress.

- **`psalm-baseline.xml` grew during the change.** Cause: a new error was baselined instead of fixed. Per project rule, new errors must be fixed. Revert the baseline edit and resolve the static-analysis error in code.

- **`bin/phpdup analyze --help` shows the flag in the wrong section** of `buildGroupedHelp()`. Cause: Symfony preserves `addOption()` call order in `--help`; the grouped block in `buildGroupedHelp()` is hand-curated. Both must agree. Fix: move the flag in BOTH places.