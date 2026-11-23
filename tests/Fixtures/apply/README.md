# tests/Fixtures/apply/ — end-to-end apply regression corpus (output-S53)

Corpus for `tests/Integration/ApplyEndToEndTest.php`: the transactional
apply chain (output-S44a/S44b/S45/S46/S47/S48/S49b/S50/S51/S52) exercised
end to end. Every member file is a **`.php.dist`** source, never a plain
`.php`:

- `tests/Fixtures/**/*.php` is a FROZEN CONTRACT surface —
  `CliSmokeTest::testFixturesCorpusMatchesCommittedContract` pins
  `files_scanned=20 / clusters=19 / duplicated_lines=356` over the whole
  Fixtures tree, and `EndToEndTest` pins per-corpus cluster shapes
  (`tests/Fixtures/README` era law). A plain-`.php` file added here would
  move count-guards outside this lane's locks (FileScanner scans
  `php|php8|phtml|inc` only — `.dist` is invisible to it, to psalm/phpstan
  scope `src/`, and to composer autoload).
- The suite therefore MATERIALIZES each unit into a fresh
  `sys_get_temp_dir()` scratch tree, renaming `<name>.php.dist` →
  `<name>.php`, and runs the real pipeline + `--apply` against the COPY —
  apply rewrites member files in place, so the committed corpus must never
  be the write target itself.

## Inventory

| Unit | Files | Exercises |
|---|---|---|
| `samesite/` | `ledger-kit.php.dist` | output-004 half A (two member methods in ONE file: single read/parse, node-identity collection, count-verify, one landing), output-002 keep-member-decls (S46), optional-block knob per-member true/false (S45), shared formal `$value` promoted (S49b), `: void` bare-statement delegation |
| `namespaced/` | `alpha.php.dist`, `beta.php.dist` | output-003 (S47): members inside `namespace App\Old;` — delegation must be `\Refactored\…` FULLY QUALIFIED (qualified-relative resolves to `App\Old\Refactored\…` and fatals); sibling declarations survive include-time |
| `strictkit/` | `tally-a.php.dist`, `tally-b.php.dist` | strict_types members: the directive survives the rewrite, typed shared formals `$count/$tag` promote through apply, runtime smoke under `declare(strict_types=1)` (S45/S49b) |
| `coverfloor/` | `flux-a.php.dist`, `flux-b.php.dist` | output-020 (S51): every matched statement carries a literal hole → template is mostly holes; `--max-hole-coverage=0.10` refuses apply with the NAMED summary reason and zero writes |
| `storm/` | `storm33.php.dist` | output-S57 × output-020: 33 byte-identical members > `AntiUnifier::MAX_UNIFY_MEMBERS` (32) ⇒ routed, zero real holes ⇒ whole-template `__cap` sentinel (degraded) ⇒ apply refused naming `__cap`, and the emitted signature stays SENTINEL-INERT (`SignatureBuilder` skips `__cap`) |
| `unsafe/` | `widget-a.php.dist`, `widget-b.php.dist` | output-008 (S50): members whose bodies touch `$this` → `detectUnsafe` refuses the cluster with the hazard-name reason; zero writes |
| `links/` | `twin-a.php.dist`, `twin-b.php.dist` | output-010 (S52): the suite plants an IN-ROOT member symlink (`c.php -> twin-b materialized`) at runtime — the scanner admits it, `ApplyExtractor::confineWriteTargets` refuses it (link refusals are mode-independent); `refactored` itself planted as an out-of-root dir symlink in a sibling leg |
| `rollback/` | `alpha.php.dist`, `beta.php.dist` | output-004 half B: one member file chmod-0444 in the scratch COPY (never the corpus) ⇒ mid-window write failure ⇒ earlier landings (artifact, bootstrap stub, first member) roll back byte-exact and the created dirs vanish |

All sources are PHP 8.1-parseable. Symlinks are never committed (git
mode-trap law): the `links/` leg builds its link at runtime inside scratch.
