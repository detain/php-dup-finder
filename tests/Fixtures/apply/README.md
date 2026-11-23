# tests/Fixtures/apply/ — end-to-end apply regression corpus (output-S53)

Corpus for `tests/Integration/ApplyEndToEndTest.php`: the transactional
apply chain (output-S44a/S44b/S45/S46/S47/S48/S49b/S50/S51/S52/S57)
exercised end to end. Every member file is a **`.php.dist`** source, never
a plain `.php`:

- `tests/Fixtures/**/*.php` is a FROZEN CONTRACT surface —
  `CliSmokeTest::testFixturesCorpusMatchesCommittedContract` pins
  `files_scanned=20 / clusters=19 / duplicated_lines=356` over the whole
  Fixtures tree. A plain-`.php` file added here would move those pins
  outside this lane's locks (FileScanner accepts `php|php8|phtml|inc`
  only — `.dist` is invisible to it, to the psalm/phpstan `src/` scope,
  and to composer autoload).
- The suite MATERIALIZES each unit into a fresh `sys_get_temp_dir()`
  scratch tree, renaming `<name>.php.dist` → `<name>.php`, and runs the
  real pipeline / `bin/phpdup --apply` against the COPY — apply rewrites
  member files in place, so the committed corpus must never be the write
  target itself. Chmods, planted symlinks and mutated ranges happen only
  inside those scratch copies.

## Inventory

| Unit | Files | Exercises |
|---|---|---|
| `namespaced_strict/` | `src/NotifyHigh.php.dist`, `src/NotifyLow.php.dist` | output-003 (S47): members inside `namespace App\Ledger;` under `strict_types` — the delegation must be `\Refactored\…` FULLY QUALIFIED (a relative name resolves as `App\Ledger\Refactored\…` and fatals; the leg RUNS a child php to prove it). Typed shared formals `$user/$score` promote (S49b) with the per-member literal row re-bound (S45); `keepMe()` is the S46 sibling survivor; also carries the rollback / planted-symlink / stale-range / missing-file failure-provocations and the CLI happy + coverage-floor legs |
| `samesite/` | `ledger-kit.php.dist` | output-004 half A (two member methods in ONE file, two classes: single read/parse, node-identity collection, one landing), output-002 keep-member-decls (S46) incl. the `: void` bare-expression delegation shape, one `optional_block` knob with per-member true/false (S45), shared formal `$value` promoted (S49b), `helperA/helperB` sibling survivors |
| `nested_pair/` | `src/BoxX.php.dist` | output-004 count-verify arm e2e: a cluster whose TWO members are in one file with one node NESTED inside the other — after the loose-knob merge (documented in the test) the single replacement pass reports 'only 1 of 2 collected member nodes' and refuses with zero writes |
| `storm/` | `storm33.php.dist` | output-S57 × output-020: 33 byte-identical members > `AntiUnifier::MAX_UNIFY_MEMBERS` (32) ⇒ routed through representatives, zero real holes, one whole-template `__cap` sentinel (degraded) — the emitted signature stays SENTINEL-INERT (`SignatureBuilder`/`ParameterSynthesizer` skip `__cap`), and direct apply refuses at the empty-holePath placement arm |
| `unsafe/` | `widget-a.php.dist`, `widget-b.php.dist` | output-008 (S50): members whose bodies touch `$this` → the reporter's `detectUnsafe` gate refuses the cluster from apply with the named hazard reason; zero writes (CLI leg) |

All sources are PHP 8.1-parseable; the corpus is read-only to the suite by
construction (materialize-then-mutate). Symlinks are built only at runtime
inside scratch.
