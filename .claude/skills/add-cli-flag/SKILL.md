---
name: add-cli-flag
description: Adds a new CLI flag in src/Cli/Command.php::configure() and threads it through ConfigLoader, Config, docs/config-schema.json, and docs/CLI.md. Use when the user says 'add flag', 'new CLI option', 'add --<name>', or 'expose <config> on the command line'. Covers the four flag groups (Detection tuning, Output/reports, Performance/runtime, Interactive/UI), default-handling, validation/exit-2 patterns, profile-key mapping, and ConfigLoaderTest coverage. Do NOT use for renaming an existing flag (breaking change — propose an alias instead) or for adding a brand-new subcommand (different scaffold under SymfonyCommand).
paths:
  - src/Cli/Command.php
  - src/Cli/ConfigLoader.php
  - src/Cli/Config.php
  - docs/config-schema.json
  - docs/CLI.md
  - tests/Unit/Cli/ConfigLoaderTest.php
  - profiles/*.json
---
# add-cli-flag

## Critical

- **Naming convention is non-negotiable**: kebab-case on the CLI (`--max-memory`), snake_case as the override-dict key and JSON-schema key (`max_memory`), camelCase as the `Config` property (`$maxMemory`). Mismatching breaks `src/Cli/ConfigLoader.php` silently — the value will fall back to the default with no error.
- **Six edit sites per flag** — skipping any one ships a half-wired option:
  1. `src/Cli/Command.php` configure method — `addOption(...)` in the right group block.
  2. `src/Cli/Command.php` grouped-help builder — list flag under the matching group comment.
  3. `src/Cli/Command.php` execute method — read via `$input->getOption('kebab-name')` and add to `$overrides['snake_name']` (or directly into `$reportArgs` for output-only flags).
  4. `src/Cli/ConfigLoader.php` load method — wire override into the `Config` constructor via the local `$get('snake_name', $base->camelName)` helper.
  5. `src/Cli/ConfigLoader.php` validate method — add to the `$known` whitelist and add an `$assertInt/$assertFloat01/$assertEnum` call so unknown-key/invalid-value runs fail at load time.
  6. `src/Cli/Config.php` constructor — add a `public readonly` property with default + range check inside the constructor body.
- **Two doc sites are mandatory**: `docs/config-schema.json` (matches the validator) and `docs/CLI.md` (matches the grouped-help builder). Both must reflect the new flag in the same commit.
- **Renames are breaking**: this skill never renames an existing flag — propose adding the new name as an alias and emitting a deprecation warning when the old one is used.
- **PHP target floor is 8.1**: do not use standalone `:null` return types (use `?T`). See `CALIBER_LEARNINGS.md` `[gotcha]`.
- **Never stage `composer.lock`**: it is gitignored by design.

## Instructions

### Step 1 — Decide the flag group and shape

Classify the new flag into exactly one group, matching the comment dividers in the `src/Cli/Command.php` configure method:

| Group | Comment marker | Examples |
|---|---|---|
| Configuration | `// ── Configuration ──` | `--config`, `--validate-config` |
| Detection tuning | `// ── Detection tuning ──` | `--min-block-size`, `--mode`, `--similarity`, `--max-df`, `--profile`, `--min-safety` |
| Output / reports | `// ── Output / reports ──` | `--html`, `--json`, `--sarif`, `--diff`, `--limit`, `--sort`, `--stats` |
| Performance / runtime | `// ── Performance / runtime ──` | `--workers (-j)`, `--no-cache`, `--max-memory`, `--stage` |
| Interactive / UI | `// ── Interactive / UI ──` | `--tui`, `--plain`, `--theme`, `--watch` |

Decide the flag's value shape:
- `InputOption::VALUE_NONE` — boolean toggle (no `=value`).
- `InputOption::VALUE_REQUIRED` — typed scalar (int/float/string/enum). Always pass through `(int)` / `(float)` / `strtolower((string)$x)` casts when reading.
- A trailing 5th argument to `addOption()` is the default surfaced in `--help`; only set it for human-visible defaults (e.g. `'limit', ..., 50`). Numeric/threshold defaults belong in `src/Cli/Config.php` so the JSON path also gets them.

**Verify** the group classification before proceeding: search `git log --oneline -- src/Cli/Command.php` for the most recent flag in the same group and mirror its style. Do not invent a new group.

### Step 2 — Add `addOption(...)` to the configure method

Edit `src/Cli/Command.php`. Append to the end of the matching group's chained `->addOption()` calls (preserve the trailing `;` on the last entry). Use this template (Detection tuning, VALUE_REQUIRED, range-typed):

```php
            ->addOption('max-clusters', null, InputOption::VALUE_REQUIRED, 'Cap report at N clusters before ranking. Default: unlimited. Useful for noisy first-pass runs.')
```

For a boolean toggle:

```php
            ->addOption('no-color', null, InputOption::VALUE_NONE, 'Disable ANSI colours in CLI output')
```

For a short alias (rare — only for top-level frequently-used flags), follow the `'workers', 'j'` precedent at line 75.

**Verify**: run `vendor/bin/phpunit --testsuite Unit --filter CommandConfigure` (if present) or at minimum `bin/phpdup --help` and confirm the new flag appears under its group.

### Step 3 — Update the grouped-help builder

In the same file, edit the heredoc inside the grouped-help builder method (around line 95). Add the bare flag name (no description) under the matching group comment line. Keep wrap width consistent with neighbours — break before the new entry if the existing line would exceed the surrounding pattern (see `--optional-blocks-containment` on its own line).

```
 Detection tuning
   --min-block-size, --mode, --similarity, --max-df,
   --optional-blocks, --optional-blocks-containment,
   --min-impact, --min-safety, --exact-only, --kinds, --auto-tune,
   --profile, --max-clusters
```

**Verify**: `bin/phpdup --help | grep -A2 'Detection tuning'` shows the new flag in the group block.

### Step 4 — Read the flag in the execute method and translate to overrides

Flags fall into two routing paths:

**Path A — Detection / runtime flags** that affect the pipeline: thread through `$overrides`. Find the existing `$overrides = array_filter([...], fn($v) => $v !== null);` block (around line 141) and add a row mapping the kebab name to the snake_case override key:

```php
        $overrides = array_filter([
            'min_block_size'              => $input->getOption('min-block-size'),
            // ... existing rows ...
            'max_clusters'                => $input->getOption('max-clusters'),
        ], fn($v) => $v !== null);
```

**Path B — Output/reporter flags**: add directly to the `$reportArgs` array (around line 304) and pass into the `ReportStage(...)` constructor inside `$buildPipeline`.

**Validation pattern for enum/range flags** — emit exit 2 with a `<error>` block before constructing the `Config`. Mirror the `--sort` pattern (line 167) for parser-backed validation, the `--optional-blocks` pattern (line 154) for boolean-ish enums, the `--kinds` pattern (line 180) for comma-list validation, and the `--min-safety` pattern (line 320) for `[0, 1]` numeric ranges:

```php
        if ($input->getOption('max-clusters') !== null) {
            $n = (int)$input->getOption('max-clusters');
            if ($n < 1) {
                $output->writeln('<error>phpdup: --max-clusters must be >= 1</error>');
                return 2;
            }
        }
```

**Verify**: invalid input must exit with code 2 and a single `<error>` line — never throw uncaught. Confirm with `bin/phpdup analyze --max-clusters=0 src; echo $?`.

### Step 5 — If the flag is profile-driven, update profile mappings

Profiles in `profiles/*.json` may seed defaults. If the new flag belongs in the profile dictionary, add its key to the `foreach ([...]) ...` list in `Command::profileToOverrides()` (line 490). Otherwise skip this step.

```php
        foreach (['min_block_size', 'max_block_size', 'normalization_mode',
                  'similarity_threshold', 'tree_threshold', 'min_cluster_impact',
                  'max_df', 'ngram_size', 'sort', 'max_clusters'] as $k) {
```

Then mirror the same key into `ConfigLoader::shapeOverrides()` (line 142) so per-directory `.phpdup.json` files honour it.

### Step 6 — Wire the override into the loader

Edit `src/Cli/ConfigLoader.php`. Add a row to the `Config(...)` constructor call (around line 60) using the local `$get` closure:

```php
            // ... existing rows ...
            sort: (string)($overrides['sort'] ?? $data['sort'] ?? $base->sort),
            maxClusters: (int)$get('max_clusters', $base->maxClusters),
            perDirectoryOverrides: $this->discoverPerDirectoryOverrides($resolvedPaths),
        );
```

The cast (`(int)`, `(float)`, `(string)`, `(bool)`) MUST match the `Config` property type. `$get` reads from `$overrides` first, then `$data` (the JSON file), then the default.

### Step 7 — Add validation in the loader

In the same file:

1. Append the snake_case key to the `$known` whitelist array (line 177). Unknown keys throw `RuntimeException` with field path — this is what makes typos in `phpdup.json` fail loudly.
2. Add a typed assertion using the existing closures (`$assertInt`, `$assertFloat01`, `$assertEnum`, `$assertListOfStrings`):

```php
        if (array_key_exists('max_clusters', $data)) {
            $assertInt($data['max_clusters'], 'max_clusters', 1);
        }
```

**Verify**: run `bin/phpdup analyze --config /tmp/bad.json --validate-config` against a JSON with the new key out-of-range — expect exit 2 and the field-path error.

### Step 8 — Add the `Config` property and constructor check

Edit `src/Cli/Config.php`. Add a `public readonly` property in camelCase with a default, then add an inline range/enum check (only if not already covered by the validator — both layers should defend, since `Config` may be constructed by tests or `withOverrides()` paths):

```php
        public readonly int $maxClusters = 0,    // 0 = unlimited
```

```php
        if ($maxClusters < 0) {
            throw new \InvalidArgumentException("max_clusters must be >= 0");
        }
```

If the property has a default that should appear in `--help`, also touch `Config::defaults()` (line 97) only when the default depends on `$paths`; otherwise the constructor default is sufficient.

### Step 9 — Update `docs/config-schema.json`

Add a new property to the top-level `properties` object that mirrors the validator exactly. The order, default, type, and minimum/maximum must agree with the validator — there is no JSON-Schema validator dependency, so divergence will not be caught automatically.

```json
        "max_clusters": {
            "type": "integer",
            "minimum": 1,
            "description": "Cap report at N clusters before ranking. Omit to keep all clusters. Equivalent to --max-clusters on the CLI."
        },
```

### Step 10 — Update `docs/CLI.md`

Add a one-line entry under the matching group section, matching neighbours' phrasing (lead with the flag, then a sentence). Keep mention of equivalent JSON key when applicable.

### Step 11 — Add a `ConfigLoaderTest` case

Edit `tests/Unit/Cli/ConfigLoaderTest.php`. Add at minimum (a) a happy-path test that the override flows through to the `Config` property, and (b) a negative test that out-of-range values throw `RuntimeException` with a field-path message. Mirror the existing `min_block_size`/`similarity_threshold` patterns:

```php
    public function testMaxClustersFlowsThrough(): void
    {
        $config = (new ConfigLoader())->load(
            paths: ['.'],
            configFile: null,
            overrides: ['max_clusters' => 25],
        );
        $this->assertSame(25, $config->maxClusters);
    }

    public function testMaxClustersOutOfRangeRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max_clusters must be >= 1');
        (new ConfigLoader())->validate(['max_clusters' => 0]);
    }
```

**Verify** — run the gating commands and confirm green:

```bash
vendor/bin/phpunit --testsuite Unit --filter ConfigLoader
vendor/bin/phpstan analyse
vendor/bin/psalm
bin/phpdup analyze --max-clusters=5 tests/Fixtures   # smoke
bin/phpdup analyze --config phpdup.json --validate-config; echo $?   # expect 0 on a well-formed file
```

## Examples

**User**: "Add a `--max-clusters=N` flag that caps the report."

**Actions**:
1. Classify as **Output / reports** group (it caps reporter output, not detection).
2. In `src/Cli/Command.php` configure — add `->addOption('max-clusters', null, InputOption::VALUE_REQUIRED, 'Cap report at N clusters before ranking')` to the Output / reports chain.
3. Grouped-help builder — append `, --max-clusters` to the Output / reports line.
4. Execute method — since this is reporter-side, route via `$reportArgs['maxClusters'] = $input->getOption('max-clusters') !== null ? (int)$input->getOption('max-clusters') : 0;` and thread into the `ReportStage` constructor; add range check before pipeline build (exit 2 on `< 1`).
5. `src/Cli/ConfigLoader.php` load — add `maxClusters: (int)$get('max_clusters', $base->maxClusters)` to the `Config(...)` call.
6. `src/Cli/ConfigLoader.php` validate — add `'max_clusters'` to `$known`; add `$assertInt($data['max_clusters'], 'max_clusters', 1)` guarded by `array_key_exists`.
7. `src/Cli/Config.php` — add `public readonly int $maxClusters = 0,` and `if ($maxClusters < 0) throw …`.
8. `docs/config-schema.json` — add the property block.
9. `docs/CLI.md` — add the bullet.
10. `tests/Unit/Cli/ConfigLoaderTest.php` — add the two test methods.

**Result**: `bin/phpdup analyze --max-clusters=10 src` works; `phpdup.json` with `"max_clusters": 10` works; `--validate-config` rejects `"max_clusters": 0` with the field-path error; PHPStan/Psalm green.

## Common Issues

- **`Unknown config key 'max_clusters' in phpdup.json`** during `--validate-config` after Step 9: Step 7 was skipped — add the snake_case key to the `$known` array in the loader's validate method.
- **CLI flag silently ignored, default value always used**: kebab/snake mismatch. Confirm the `addOption('max-clusters', …)` kebab name matches `$input->getOption('max-clusters')` exactly, and that `$overrides['max_clusters']` (snake_case) matches the `$get('max_clusters', …)` call in the loader.
- **`InvalidArgumentException` from the `Config` constructor instead of clean exit 2**: validation is happening too late. Add an early check inside the command's execute method that emits `<error>` and `return 2;` before `(new ConfigLoader())->load(...)` is called — see the `--sort` and `--min-safety` precedents.
- **`Cannot use object of type Closure as array` in the loader**: you placed the override row outside the `$get` closure scope or before `$get` is defined (line 32). Add the row inside the `Config(...)` call below line 60.
- **Property reads as `0` despite passing `--max-clusters=10`**: missing cast. Every `$get(...)` row needs an explicit cast matching the `Config` property type — e.g. `(int)$get('max_clusters', …)`. Without it, the value passes through as a string and the `int` property assignment may not throw under PHP 8.1's `declare(strict_types=1)`.
- **PHPStan: `Parameter $maxClusters of class Config constructor expects int, mixed given`**: add the cast in Step 6 (`(int)$get(...)`).
- **Profile flag stays at default after `--profile=laravel`**: Step 5 was skipped. Add the snake_case key to both `Command::profileToOverrides()` and `ConfigLoader::shapeOverrides()`.
- **`composer.lock` shows up in `git status`**: do not stage it — `.gitignore` line 2 excludes it intentionally.
- **`bin/phpdup --help` does not show flag in any group**: Step 3 was skipped. The Symfony auto-generated list shows it in `addOption()` order but the grouped cheat-sheet under `Help:` is hand-maintained.
