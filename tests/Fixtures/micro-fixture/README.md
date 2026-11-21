# micro-fixture

14-file synthetic corpus encoding the six detection blind spots of
improve_logic.md Appendix A (design: filing f15, bench lane). It is a
RED-STATE-AS-PIN: `tests/Integration/MicroFixtureAuditTest.php` runs the
in-process pipeline over these files (max_document_frequency=0.9,
min_cluster_impact=1) and asserts TODAY'S defective clusters EXACTLY.

Do not edit these files to keep a test green. When a WAVE-3 cure lands
(1.1, 1.3, 2.1, 2.3, 3.2, 3.3) the test fails on purpose — re-derive the
truth, update the signature + `SHOULD_FIND` table and the `FLIP_MARKER`
notes in one deliberate commit.

| pattern | wave item | blind spot |
|---|---|---|
| P1 preamble_*.php | 2.1 + 1.1 | shared 9-line service preamble found only as whole-method sim<1 cluster + 3 disjoint if fragments |
| P2 scripts_*.php | 1.3 | top-level script sequences invisible (only inner while/foreach cluster) |
| P3 Env.php vs inline_*.php:16-17 | 3.2 + 1.1 | one-line helpers re-implemented inline are never linked to their source |
| P4 inline_*.php:12 | 3.3 | duplicated URL string literal invisible (literals are not blocks) |
| P5 inline_*.php:11 | 3.3 | duplicated STATUS_MAP constant invisible (consts are not blocks) |
| P6 Env.php whole file | 2.3 | no class-shape/whole-file duplicate signal |

Mechanism probes (f15 §6): small control blocks ARE extracted when an exact
twin exists; property/const declarations are NOT extractable kinds at all.
