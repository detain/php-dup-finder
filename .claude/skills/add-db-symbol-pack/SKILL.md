---
name: add-db-symbol-pack
description: Creates a new ORM/DB-aware symbol pack under profiles/db-aware-<name>.json plus the matching ProjectProfileDetector::detectIn() composer-package marker and KNOWN_PROFILES entry, threading it through tests/Unit/Cli/ProjectProfileDetectorTest.php. Use when user says 'add db-aware profile for <orm>', 'support <orm> in --db-aware', 'add symbol pack', 'extend DbOpRegistry for <library>', or adds a file to profiles/db-aware-*.json. Capabilities: enforce lower-cased method keys, validate values against db.read|db.write|db.delete|db.execute|db.query, pick the right composer-package signal (require + require-dev), register in KNOWN_PROFILES in correct order, cover detection in ProjectProfileDetectorTest. Do NOT use for framework auto-tune profiles (laravel/symfony/drupal/wordpress — those live at profiles/<framework>.json and tune excludes/min_block_size, not db_symbols), do NOT use to edit existing db-aware packs (just modify the JSON in-place), do NOT use for renaming/removing an existing pack.
paths:
  - profiles/db-aware-*.json
  - src/Cli/ProjectProfileDetector.php
  - tests/Unit/Cli/ProjectProfileDetectorTest.php
---
# add-db-symbol-pack

Add a new ORM/DB symbol-equivalence pack so `--db-aware` folds `<library>`'s call shapes into the canonical `__DB_<OP>__` tokens emitted by `src/Normalization/DbOpCanonicalizer.php`. Three files change in lockstep: the profile JSON, the detector, and the detector test.

## Critical

- **Method keys MUST be lower-case.** The matcher in `src/Normalization/DbOpRegistry.php` lower-cases call names before lookup; an uppercase key will silently never match. Verify with a grep for uppercase letters inside the `"methods"` block returning no matches.
- **Values are restricted to exactly five strings:** the five canonical op tags. These are the public constants on `Phpdup\Normalization\DbOpRegistry` (`OP_READ`/`OP_WRITE`/`OP_DELETE`/`OP_EXECUTE`/`OP_QUERY`, see `src/Normalization/DbOpRegistry.php`). Any other value is rejected by `ConfigLoader::validate()` in `src/Cli/ConfigLoader.php`.
- **This skill is for `profiles/db-aware-*.json` only.** Framework auto-tune profiles (in `profiles/`) carry `exclude`/`kinds`/`min_block_size` and are handled by the `add-language-profile` skill. They do NOT carry `db_symbols`.
- **Never stage `composer.lock`** (`.gitignore` line 2). Composer changes are not part of this skill — symbol packs are static JSON.
- **Op-tag choices follow CRUD semantics.** Use the read op tag for SELECT/find/fetch/exists/count; the write op tag for insert/update/upsert/save/merge/increment; the delete op tag for delete/destroy/remove; the execute op tag for transaction control (begin/commit/rollback/transactional); the query op tag for builder constructors that haven't been executed yet (createQuery, createQueryBuilder, raw). When unsure, compare to the closest existing pack (`profiles/db-aware-doctrine.json` for ORM-style, `profiles/db-aware-laravel.json` for Eloquent-style, `profiles/db-aware-medoo.json` for thin-DBAL).

## Instructions

### Step 1 — Pick the canonical pack name and composer marker

Decide `<name>` (the slug used in the profile JSON filename AND in `--profile=db-aware-<name>`). Match existing convention: short, lower-case, ORM/driver name, no version (e.g. `doctrine`, `cycle`, `propel`, `redbean`, `idiorm`, `mongodb`).

Identify the composer package(s) that signal this library. Required source-of-truth: the Packagist page or the library's `composer.json` `name`. A pack may have multiple OR'd markers. Use multiple `||` clauses in the detector — see existing examples in `src/Cli/ProjectProfileDetector.php`.

**Verify** the slug is not already taken: glob the `profiles/` directory for the candidate filename and confirm no matches. Verify the composer package name is not already wired into another `hasComposerPackage()` call in `src/Cli/ProjectProfileDetector.php`'s `detectIn()` method.

### Step 2 — Write the profile JSON

Copy the shape of `profiles/db-aware-doctrine.json` exactly. Top-level keys are ONLY `_description` and `db_symbols`. `ProfileRegistry::load()` in `src/Cli/ProfileRegistry.php` strips `_description` before validation, so include a rich one.

Template:

```json
{
    "_description": "<Library>-specific DB symbol equivalence pack. Extends the stock DbOpRegistry with <Library> method names that aren't covered by the generic CRUD-verb pattern. Use with --profile=db-aware-<name> together with --db-aware so <Library>-specific call shapes fold into the same canonical __DB_<OP>__ tokens as the rest of the recognised DB ecosystem.",
    "db_symbols": {
        "methods": {
            "librarymethodname": "db.read"
        }
    }
}
```

Rules for entries:

- Keys: lower-case method name only, no class prefix, no parens. `findOneBy` → `"findoneby"`.
- Use aligned colons inside one block for readability (see `profiles/db-aware-doctrine.json`).
- Only add methods that the **generic CRUD-verb fallback in `DbOpRegistry` cannot already classify**. Skip plain `find`, `findone`, `save`, `delete`, `update`, `insert`, `select` — those are already in the stock registry (`src/Normalization/DbOpRegistry.php`).
- A `"functions"` bucket is also valid (free functions like `mysqli_query`); `ConfigLoader` supports it in `src/Cli/ConfigLoader.php`. Omit if the library is purely method-based.

**Verify**: parse the JSON to confirm it is valid, and every value matches the canonical five op-tag strings.

### Step 3 — Register in `src/Cli/ProjectProfileDetector.php`

Two edits in one file:

**3a. Add to `KNOWN_PROFILES`** at the top of the class. Insert the new entry near related packs (alphabetical-ish, but in practice they cluster by category: SQL ORMs together, NoSQL together). Keep `'generic'` last.

**3b. Add the detection clause** to `detectIn()` between the existing `hasComposerPackage` checks (after framework/dir markers, before the `composer.json → 'generic'` fallback at the end of the method). Pattern is one-liner per pack:

```php
if ($this->hasComposerPackage($root, '<vendor>/<package>')) {
    return 'db-aware-<name>';
}
```

For multiple markers, use `||`:

```php
if ($this->hasComposerPackage($root, 'a/b') || $this->hasComposerPackage($root, 'c/d')) {
    return 'db-aware-<name>';
}
```

Ordering matters only when one package implies another (e.g. Doctrine ORM ships with DBAL — list the more specific package first). For unrelated packs, append at the end of the db-aware block.

**Verify**: `vendor/bin/phpstan analyse src/Cli/ProjectProfileDetector.php` passes (level 6).

### Step 4 — Add detection tests in `tests/Unit/Cli/ProjectProfileDetectorTest.php`

Each composer marker gets its own test method. Copy the shape of an existing detection test like `testDoctrinePackageDetectsDbAwareDoctrine`:

```php
public function test<Name>PackageDetectsDbAware<Name>(): void
{
    $root = $this->mkproject(['composer.json' => json_encode(['require' => ['<vendor>/<package>' => '^X.0']])]);
    $this->assertSame('db-aware-<name>', (new ProjectProfileDetector())->detect([$root]));
}
```

Add one test per OR'd marker — see the existing Redis tests (`testPredis...`, `testCredis...`, `testPhpredis...`) in `tests/Unit/Cli/ProjectProfileDetectorTest.php`.

Use only `'require'` (not `'require-dev'`) for tests; `hasComposerPackage()` already checks both in `src/Cli/ProjectProfileDetector.php`.

Do NOT modify `testRegistryLoadsBundledProfiles` — `ProfileRegistry::listAvailable()` reads `profiles/` directly, so the new file is auto-discovered.

**Verify**: `vendor/bin/phpunit --testsuite Unit --filter ProjectProfileDetectorTest` is all green.

### Step 5 — Final validation

Run the full check chain. `phpunit.xml` has `failOnWarning` and `failOnNotice` ON — any deprecation counts as failure.

```bash
vendor/bin/phpunit --testsuite Unit --filter ProjectProfileDetectorTest
vendor/bin/phpstan analyse
vendor/bin/psalm
bin/phpdup analyze --config /dev/null --profile db-aware-<name> --validate-config && echo OK
```

The last command runs ConfigLoader's validator against the new pack (loaded via `ProfileRegistry`). Exit code 0 means the methods/values shape passed `ConfigLoader::validate()` in `src/Cli/ConfigLoader.php`. Exit 2 means a bad value — fix the JSON.

## Examples

**User says:** "Add a db-aware profile for Spiral ORM."

**Actions:**

1. Glob the `profiles/` directory for the candidate filename → no matches, slug is free.
2. Browse the Cycle-shaped pack (`profiles/db-aware-cycle.json`) for the closest template since Spiral DBAL is conceptually similar.
3. Write the new profile JSON with `_description` and a `db_symbols.methods` map: `"fetchall"`, `"execute"`, `"insert"`, `"update"`, `"delete"`, `"query"`, all lower-case, mapped to canonical op tags.
4. Edit `src/Cli/ProjectProfileDetector.php`: add the new slug to `KNOWN_PROFILES` (next to `'db-aware-cycle'`); add an `if ($this->hasComposerPackage(...))` clause near the Cycle ORM clause.

```php
if ($this->hasComposerPackage($root, 'spiral/database')) {
    return 'db-aware-spiral';
}
```

5. Add `testSpiralDatabasePackageDetectsDbAwareSpiral` to `tests/Unit/Cli/ProjectProfileDetectorTest.php` using the `mkproject()` helper.
6. `vendor/bin/phpunit --testsuite Unit --filter ProjectProfileDetectorTest` → green; `vendor/bin/phpstan analyse` → 0 errors.

**Result:** Running `bin/phpdup analyze <spiral-project> --db-aware` auto-detects the new pack and folds Spiral DBAL calls into the canonical DB-op tokens used for clustering across ORMs.

## Common Issues

- **`RuntimeException: db_symbols.methods.<name> must be one of ...`** — A value in the new JSON is misspelled (e.g. `"db.reads"`, `"read"`, `"DB.READ"`). Fix the offending value to one of the five exact canonical strings. Source: `src/Cli/ConfigLoader.php`.
- **`RuntimeException: db_symbols.methods keys must be non-empty strings`** — There is a numeric or empty key in the JSON (from accidental array syntax). Rewrite as an object with quoted string keys. Source: `src/Cli/ConfigLoader.php`.
- **`RuntimeException: Unknown profile 'db-aware-<name>'`** — `KNOWN_PROFILES` was updated but the file is missing, OR the slug in step 2 does not match the slug in step 3. Glob the `profiles/` directory and confirm the entry in `src/Cli/ProjectProfileDetector.php`'s `KNOWN_PROFILES` use the identical string.
- **Test passes but `--db-aware` still emits the raw method name in canonical output** — Method key is uppercase or camelCase. Grep for uppercase letters inside the `"methods"` block of the new JSON and lower-case every key. The runtime lookup is case-sensitive against pre-lower-cased call names.
- **`testRegistryLoadsBundledProfiles` failing with `'_description' should have been stripped`** — The profile JSON has an extra top-level key besides `_description` and `db_symbols`. Remove anything else; `ProfileRegistry::load()` only strips `_description` and passes the rest to validation, which rejects unknown keys (`src/Cli/ConfigLoader.php`).
- **Detector returns `'generic'` instead of the new pack in a real project** — The new `hasComposerPackage()` clause was placed AFTER the `composer.json → 'generic'` fallback at the end of `detectIn()` in `src/Cli/ProjectProfileDetector.php`. Move it above that fallback.
- **Two packs both match the same project** — `detectIn()` returns on the first hit, so the order in the method matters. Place the more specific check first. Add a test that creates a `composer.json` requiring both packages and asserts the expected winner.
- **PHPStan complains about `KNOWN_PROFILES` shape** — Maintain the `@var list<string>` doc on the constant and keep the entries as plain strings.
