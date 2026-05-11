---
paths:
  - profiles/db-aware-*.json
  - src/Cli/ProjectProfileDetector.php
  - src/Normalization/DbOpRegistry.php
---

# DB-aware profiles

- Each `profiles/db-aware-*.json` is a symbol pack — keys allowed by `docs/config-schema.json` only (`_description`, `db_symbols.methods`, `db_symbols.functions`).
- Method/function keys MUST be lower-cased; values are one of `db.read` | `db.write` | `db.delete` | `db.execute` | `db.query` (matches `DbOpRegistry` op constants).
- Register the profile name in `ProjectProfileDetector::KNOWN_PROFILES` AND add a detection branch in `ProjectProfileDetector::detectIn()` — composer-package marker via `hasComposerPackage($root, '<vendor>/<package>')` is the canonical signal for ORMs/DB libs.
- Verify with: `bin/phpdup analyze --config phpdup.json --profile=db-aware-<name> --db-aware --validate-config`.
- Cover detection with `tests/Unit/Cli/ProjectProfileDetectorTest.php`; cover registry with `tests/Unit/Normalization/CustomDbSymbolsTest.php`.
- Cache invalidation: `Config::$dbSymbolsMethods`/`$dbSymbolsFunctions` are folded into both `PreprocessWorker::toolFor()` cache-key sprintf AND `PreprocessStage`'s IndexStore config-key hash — adding a new field there requires updating both.
