---
name: add-language-profile
description: Adds a framework-aware tuning profile by creating profiles/<framework>.json, registering it in src/Cli/ProjectProfileDetector.php (detectIn() + KNOWN_PROFILES), and adding a marker-detection unit test in tests/Unit/Cli/ProjectProfileDetectorTest.php. Use when the user says 'add profile for <framework>', 'auto-tune for <framework>', 'support <framework> conventions', or 'detect <CMS> projects'. Capabilities: writes JSON profile shape (exclude, kinds, min_block_size, min_cluster_impact), inserts detection rule in correct specificity order, updates KNOWN_PROFILES constant, adds detection test + updates testRegistryLoadsBundledProfiles. Do NOT use for one-off corpus tuning (that is `--auto-tune` driven by src/Cli/AutoTuner.php), for editing existing profile values without adding a new framework, or for adding non-PHP-ecosystem profiles.
paths:
  - profiles/*.json
  - src/Cli/ProjectProfileDetector.php
  - src/Cli/ProfileRegistry.php
  - tests/Unit/Cli/ProjectProfileDetectorTest.php
---
# Add a language/framework profile

Creates a new auto-applied tuning profile that seeds framework-aware excludes and block-size tuning whenever `phpdup analyze --profile auto` (or `--profile <name>`) detects the matching project layout.

## Critical

- The profile JSON validator **rejects unknown keys**. Allowed top-level keys: `_description`, `exclude`, `kinds`, `min_block_size`, `max_block_size`, `normalization_mode`, `similarity_threshold`, `tree_threshold`, `min_cluster_impact`, `max_df`, `ngram_size`, `sort`. Anything else fails `--validate-config` and breaks `vendor/bin/phpunit`.
- `kinds` values must come from `BlockExtractor::ALL_KINDS`: `method`, `function`, `closure`, `if`, `for`, `foreach`, `while`, `do`, `try`, `switch`, `match`. No others.
- Profiles are folded in as **low-priority overrides** — explicit CLI flags and `--config` always win (see `src/Cli/Command.php` execute method ~line 200). Do not assume your profile values are final; pick conservative defaults.
- Detection order in `src/Cli/ProjectProfileDetector.php` is **specificity-first**: framework-specific marker files come before the generic `composer.json` fallback. Insert your new check above the `composer.json` branch, in a position consistent with how distinctive its markers are.
- Detection markers must be **files** (`is_file()` via `any()`), not directories. Pick markers that are nearly always present and rarely fake — entry scripts, version stamps, or required core classes.
- Three excludes are mandatory in **every** profile: `vendor/**`, `node_modules/**`, `**/.phpdup-cache/**`. The cache exclude prevents phpdup's own cache from being scanned in subsequent runs.
- You must update **three** locations atomically: the profile JSON under `profiles/`, `KNOWN_PROFILES` in `src/Cli/ProjectProfileDetector.php`, and `testRegistryLoadsBundledProfiles` in the test file. Forgetting any one breaks tests or `--profile <name>` lookup.

## Instructions

### Step 1 — Identify marker files

Decide 1-3 paths whose presence uniquely identifies a `<framework>` project. Look at the existing markers in `src/Cli/ProjectProfileDetector.php` for the bar:

- Laravel: the artisan entry script and core kernel/config files
- Symfony: the console entry script and Kernel class plus services config
- Drupal: the core Drupal lib (under `core/` or `web/core/`)
- WordPress: the wp-config files and the wp-includes version stamp

Verify before proceeding: each marker must be a **file path** relative to the project root, must be present in a default install, and must not collide with markers in another already-supported profile.

### Step 2 — Write the profile JSON

Create the new profile file under `profiles/` (lowercase, no spaces). Use this exact shape, mirroring `profiles/laravel.json`:

```json
{
    "_description": "Framework — auto-applied when its marker files are detected. One sentence on what this profile excludes and why.",
    "exclude": [
        "vendor/**",
        "node_modules/**",
        "**/.phpdup-cache/**"
    ],
    "kinds": ["method", "function", "closure", "if", "for", "foreach", "while", "do", "try", "switch", "match"],
    "min_block_size": 6,
    "min_cluster_impact": 18
}
```

Tune from these reference points:
- `min_block_size`: `6` if the framework's app code has tight handlers (Laravel/Symfony/WordPress); `8` if it generates lots of boilerplate hook stubs (Drupal).
- `min_cluster_impact`: `15` (WordPress) – `25` (Drupal). Higher for boilerplate-heavy ecosystems.
- `kinds`: include `closure` only if the framework's idioms use closures heavily (routes, container bindings). WordPress/Drupal omit it.
- `exclude`: add the framework's generated/cache/build dirs (e.g., Laravel `bootstrap/cache/**`, Symfony `var/**`, Drupal `core/**`, WordPress `wp-admin/**`, `wp-includes/**`). Also exclude vendored bundled plugins/themes that ship with the CMS.

4-space indent. Trailing newline. Use double quotes (JSON).

Verify before proceeding:

```bash
php -r 'json_decode(file_get_contents("profiles/codeigniter.json"), false, 512, JSON_THROW_ON_ERROR); echo "ok\n";'
```

should print `ok`.

### Step 3 — Register the profile in `src/Cli/ProjectProfileDetector.php`

1. Add the profile name to `KNOWN_PROFILES` (line 18). Keep `'generic'` last:
   ```php
   private const KNOWN_PROFILES = ['laravel', 'symfony', 'drupal', 'wordpress', 'codeigniter', 'generic'];
   ```
2. Add a new branch inside the `detectIn()` method **before** the `composer.json` fallback. Use the `$this->any($root, [...])` helper with the markers from Step 1:
   ```php
   if ($this->any($root, ['marker1', 'marker2'])) {
       return 'codeigniter';
   }
   ```
   Place it next to ecosystem peers (e.g., other CMSes near WordPress/Drupal; other application frameworks near Laravel/Symfony) so the file remains readable.

Do not modify the `detect()` loop, `any()` helper, or `knownProfiles()` getter.

Verify before proceeding: `vendor/bin/phpstan analyse src/Cli/ProjectProfileDetector.php` exits 0.

### Step 4 — Add a detection test

Edit `tests/Unit/Cli/ProjectProfileDetectorTest.php`. Append a new test method following the existing naming + body pattern (`testLaravelMarkerDetectsLaravel`):

```php
public function testCodeIgniterMarkerDetectsCodeIgniter(): void
{
    $root = $this->mkproject(['spark' => '#!/usr/bin/env php']);
    $this->assertSame('codeigniter', (new ProjectProfileDetector())->detect([$root]));
}
```

File contents in `mkproject()` only need to make `is_file()` true — `'<?php // marker'` or `'#!/usr/bin/env php'` is enough. Do not write real framework code.

Then extend `testRegistryLoadsBundledProfiles` (around line 59) to assert your new profile is bundled:

```php
$this->assertContains('codeigniter', $available);
```

Add it next to the existing `assertContains('wordpress', $available);` line in alphabetical / grouping consistency with the existing assertions.

Do not change `testComposerJsonAloneIsGeneric`, `testEmptyDirIsGeneric`, or `testRegistryRejectsUnknownProfile`.

### Step 5 — Verify the full chain

Run in order. Stop and fix at the first failure:

```bash
vendor/bin/phpunit --testsuite Unit --filter ProjectProfileDetectorTest
vendor/bin/phpstan analyse
vendor/bin/psalm
bin/phpdup analyze --config profiles/codeigniter.json --validate-config
```

All must pass. The `--validate-config` step is the canonical check that your JSON keys are valid (the loader strips `_description` automatically per `ProfileRegistry::load()`).

Then smoke-test detection on a real project root if available:

```bash
bin/phpdup analyze --profile auto <path-to-test-project>
```

The first line of output should print `phpdup profile auto-detect: codeigniter`.

## Examples

**User says:** "Add a profile for CodeIgniter 4."

**Actions taken:**

1. Pick markers: the spark CLI entry, the App config under `app/Config/`, and the framework's CodeIgniter class under `system/`.
2. Create `profiles/codeigniter.json`:
   ```json
   {
       "_description": "CodeIgniter 4 — auto-applied when its marker files are detected. Excludes the framework's system/ tree and writable/ runtime dirs so user app/ + Modules/ code dominates the report.",
       "exclude": [
           "vendor/**",
           "system/**",
           "writable/**",
           "public/**",
           "node_modules/**",
           "**/.phpdup-cache/**"
       ],
       "kinds": ["method", "function", "closure", "if", "for", "foreach", "while", "do", "try", "switch", "match"],
       "min_block_size": 6,
       "min_cluster_impact": 18
   }
   ```
3. In `src/Cli/ProjectProfileDetector.php`:
   - `KNOWN_PROFILES` → `['laravel', 'symfony', 'drupal', 'wordpress', 'codeigniter', 'generic']`
   - After the Symfony `if` branch, insert:
     ```php
     if ($this->any($root, ['spark', 'app/Config/App.php', 'system/CodeIgniter.php'])) {
         return 'codeigniter';
     }
     ```
4. In `tests/Unit/Cli/ProjectProfileDetectorTest.php`, add:
   ```php
   public function testCodeIgniterMarkerDetectsCodeIgniter(): void
   {
       $root = $this->mkproject(['spark' => '#!/usr/bin/env php']);
       $this->assertSame('codeigniter', (new ProjectProfileDetector())->detect([$root]));
   }
   ```
   And in `testRegistryLoadsBundledProfiles`, add `$this->assertContains('codeigniter', $available);`.
5. Run `vendor/bin/phpunit --testsuite Unit --filter ProjectProfileDetectorTest` → green.
6. Run `vendor/bin/phpstan analyse` and `vendor/bin/psalm` → no new errors.

**Result:** `bin/phpdup analyze --profile auto path/to/ci-app` prints `phpdup profile auto-detect: codeigniter` and seeds the listed excludes/tuning before any CLI overrides are applied.

## Common Issues

**`RuntimeException: Profile 'codeigniter' is not valid JSON`**
The file is empty, has a BOM, or has a trailing comma. Run `php -r 'var_dump(json_decode(file_get_contents("profiles/codeigniter.json"), true));'` — if it prints `NULL`, JSON is malformed. Re-emit with double quotes and no trailing commas.

**`phpdup: --validate-config` rejects an unknown key**
You added a key not in the validator's allowlist. Allowed keys are listed in `Critical` above and enforced by `ConfigLoader::validate()`. Move arbitrary metadata into `_description` (it is stripped by `ProfileRegistry::load()` line 43).

**`testRegistryLoadsBundledProfiles` fails with `Failed asserting that ... contains 'codeigniter'`**
You created the profile JSON but didn't update the test's `assertContains` block. Add the assertion. Check that the filename is exactly the profile name (lowercase, no underscores unless intentional) — `ProfileRegistry::listAvailable()` strips the `.json` suffix verbatim.

**`testRegistryLoadsBundledProfiles` passes but `--profile codeigniter` fails with `--profile must be one of ...`**
You forgot to add the name to `KNOWN_PROFILES` in `src/Cli/ProjectProfileDetector.php`. The CLI checks `$registry->listAvailable()` for membership but the constant is what `knownProfiles()` returns to other callers; keep them in sync.

**Auto-detect picks the wrong profile (e.g., a Laravel-on-top-of-WordPress repo resolves to `wordpress`)**
Detection order is first-match-wins. Move the more-specific framework's branch above the more generic one. The Laravel branch sits first deliberately. If two frameworks legitimately coexist, document the conflict in the profile's `_description` and prefer the application-layer framework over the host CMS.

**`vendor/bin/phpstan analyse` reports `Constant ... has unknown type`**
Double-check the `KNOWN_PROFILES` literal stays a `list<string>` — no trailing commas inside the array on PHP 8.1, no nested arrays, no non-string entries.

**Unit test passes locally but Golden suite fails after profile change**
You edited an existing profile rather than adding a new one. Existing profile values are baked into golden snapshots. If the change is intentional, refresh: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --testsuite Golden`. If not, revert and create a new profile name instead.
