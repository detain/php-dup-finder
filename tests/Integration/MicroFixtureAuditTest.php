<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Micro-fixture audit — RED-STATE-AS-PIN.
 *
 * tests/Fixtures/micro-fixture/ is the 14-file corpus from improve_logic.md
 * Appendix A (filing f15 s4) encoding the six known detection blind spots.
 * This test pins TODAY'S defective detection exactly as measured on master
 * (in-process Scanning→Preprocess→Cluster→Refactor, Appendix-A knobs
 * max_document_frequency=0.9, min_cluster_impact=1, serial — no forking,
 * no network, CI-hermetic).
 *
 * EXPECTED-TO-FLIP-WAVE-3: wave items 1.1 / 1.3 / 2.1 / 2.3 / 3.2 / 3.3
 * each flip one pattern row below (and the signature). 1.1+1.3 (P1/P2,
 * item 2.1 fragment mint) and 2.3 (P6 class kind, item 2.3) have flipped
 * to FOUND 2026-11-28; 3.2/3.3 (P3/P4/P5) stay red-state pins. When a row's real
 * cure lands, THIS TEST SHOULD FAIL — update signature + pattern state to
 * the new truth and note the flip in the commit message. Never edit the
 * fixture files to preserve this pin.
 *
 * f15's 5-file prototype recorded 3× 5-member if-clusters plus a 2-member
 * method cluster; on the full 14-file corpus with Appendix-A knobs the
 * method cluster spans all five preambles at similarity 0.8903 as pinned
 * (re-measured 0.9226 post engine-S02a 4c543f3 — the sanctioned Wave-3
 * flip; see the row note in RED_STATE_SIGNATURE below). The three
 * 5-member if-clusters reproduce exactly (lines 14-16 / 19-21 / 22-24).
 * Disk-truth of this run governs the pin.
 */
final class MicroFixtureAuditTest extends TestCase
{
    /** Marker constant for the red state; see class docblock. */
    public const FLIP_MARKER = 'EXPECTED-TO-FLIP-WAVE-3';

    /**
     * Measured defective detection on master (ce9dc03 lineage; row 0
     * similarity re-measured on the 4c543f3 lineage, 2026-11-21 — see row note;
     * full signature re-derived on the f382e73/9fc8e86 lineage, 2026-11-22 —
     * the engine-S15 (d997604) exact-bucket pair mint at index 2, see its
     * row note). Fragment rows added 2026-11-28 (item 2.1: the opt-in
     * 'fragment' kind minted over this same payload — three exact stitched
     * rows, notes in situ).
     * Impact column re-derived 2026-11-28 (item 6.2): Ranker::impactOf now
     * expresses REMOVABLE LINES (per-file interval-union line span x (m-1)/m)
     * instead of AST nodes — integers renumbered, rows reordered under the
     * pinned canon; member ranges, counts and similarities are unchanged
     * from the 2.1 re-pin. Row notes keep citing their old node values as
     * history.
     * Each row: [kind, memberCount, impact, similarity, sortedMembers(file:start-end)].
     * Sorted canonically by impact desc, then kind, then first member.
     *
     * 16 rows since the 2026-11-28 item-2.3 flip (class kind on the
     * roster): +3 class rows, the standalone 5-member preamble method
     * row folded into the class-family mixed component, +1 overlap row
     * (method view carrying the inline class mass). Kind-mixing and
     * overlapping membership are pre-existing splitter semantics, not a
     * 2.3 invention — see the row notes.
     */
    public const RED_STATE_SIGNATURE = [
        // Flip 2026-11-28 (item 2.3): the opt-in 'class' kind joins the
        // roster. The five-preamble family view is now the CLASS-mixed
        // row below — union-find merges the go-exact method bucket with
        // the five near-exact class-skeleton buckets into one component,
        // and the splitter emits the whole family (10 members: 5 go
        // methods + 5 class declarations) at sim 0.9051, superseding the
        // standalone 5-member 0.9226 method row (P1's inexact baseline
        // moved here verbatim in spirit: similarity < 1.0, tails differ).
        // Kind-mixing inside one cluster is pre-existing Clusterer
        // behavior (the ngram tiers are kind-blind); the class_shape
        // table filters to class members, so the family's go verdict
        // reads divergent (5 strict, 4 fold hashes) — the honest
        // base-class signal the doc asks for.
        ['class', 10, 105, 0.9051, [
            "preamble_0.php:10-33", "preamble_0.php:8-34", "preamble_1.php:10-28",
            "preamble_1.php:8-29", "preamble_2.php:10-29", "preamble_2.php:8-30",
            "preamble_3.php:10-28", "preamble_3.php:8-29", "preamble_4.php:10-29",
            "preamble_4.php:8-30",
        ]],
        // Item 2.3 overlap disclosure: the inline class skeletons each
        // CONTAIN their handle() bag (a class's token stream is its
        // members' streams), so Jaccard >= threshold pairs every class
        // with its own method and the splitter lets the exact handle
        // family carry both species — members listed in TWO rows below
        // (this method@10 and the class@5). Overlapping cluster
        // membership is the engine-040 splitter law (shared members
        // round-trip faithfully through the cluster cache); the family
        // view is the class@5 row, this row is the method view widened
        // by the class mass.
        ['method', 10, 72, 1.0, [
            "inline_0.php:14-23", "inline_0.php:9-24", "inline_1.php:14-23",
            "inline_1.php:9-24", "inline_2.php:14-23", "inline_2.php:9-24",
            "inline_3.php:14-23", "inline_3.php:9-24", "inline_4.php:14-23",
            "inline_4.php:9-24",
        ]],
        // P6 CURED 2026-11-28 (item 2.3): the doc's same-shape-
        // different-name law, measured — all five CheckoutPage siblings
        // cluster shape-EXACT at sim 1.0 under the class roster. Their
        // class_shape table: shape_exact true, handle() verdict
        // 'parameterizable' (5 distinct strict body hashes — the literal
        // page names; 1 distinct fold hash — the shape after literals
        // blank). This is the skeleton-family base-class proposal.
        ['class', 5, 64, 1.0, [
            "inline_0.php:9-24", "inline_1.php:9-24", "inline_2.php:9-24",
            "inline_3.php:9-24", "inline_4.php:9-24",
        ]],
        // Flip 2026-11-28 (wave cure 4c543f3 engine-S02a / item 2.1 mint):
        // exact stitched cross-function run, all five preambles — lines
        // 12-13 and 17-18 (the hole-pinned glue) are now members of a 1.0
        // cluster. P1's blind spot is cured.
        // 6.2 impact: was 336 nodes -> 61 removable lines (union 76 x 4/5).
        ['fragment', 5, 61, 1.0, [
            "preamble_0.php:12-27", "preamble_1.php:12-26", "preamble_2.php:12-26",
            "preamble_3.php:12-26", "preamble_4.php:12-26",
        ]],
        // 6.2 impact: was 312 nodes -> 40 removable lines (union 50 x 4/5).
        ['method', 5, 40, 1.0, [
            "inline_0.php:14-23", "inline_1.php:14-23", "inline_2.php:14-23",
            "inline_3.php:14-23", "inline_4.php:14-23",
        ]],
        // Item 2.1: nested s9 family (falling support 5 -> 3) kept
        // deliberately — the chain is support-honest evidence, one step per
        // maximal row (the section-5c suppression law runs one-step
        // lookahead).
        // 6.2 impact: was 176 nodes -> 33 removable lines (union 49 x 2/3).
        ['fragment', 3, 33, 1.0, [
            "preamble_0.php:12-28", "preamble_2.php:12-27", "preamble_4.php:12-27",
        ]],
        // Item 2.3: the exact class pair mirroring the S15 method pair
        // below — OrderListService/NoteListService are the p0/p2
        // declarations, and their go() tails are the established
        // parameter-equivalence (query -> accumulator init -> while-append
        // -> json_response, string/identifier literals only). The class
        // row lifts the same tail truth to whole-declaration granularity:
        // shape_exact true, go verdict 'parameterizable'.
        // 6.2 impact: union 46 x 1/2 = 23 span-lines -> 25 lines (the 5-line
        // declaration prelude joins the removable span).
        ['class', 2, 25, 1.0, [
            "preamble_0.php:8-34", "preamble_2.php:8-30",
        ]],
        // Flip 2026-11-22 (audit-leg discipline honoured). CAUSE
        // (disk-proven, engine-S15 r1 verdict section 1): exact-bucket
        // re-emission — a structural-hash-exact method bucket previously
        // suppressed while riding the weaker 5-preamble component. Truth
        // adjudication on the fixture bytes: the 13-line preamble (lines
        // 12-24) is BYTE-IDENTICAL across all five preamble_* files, and
        // p0/p2 are the only pair whose tails are also parameter-
        // equivalent; this pin already recorded their while-tails as an
        // exact 1.0 clone (last row). The pair row lifts that tail truth
        // to method granularity. p1/p3/p4 tails differ in statement
        // shape, so the whole-family rows stay < 1.0. Rows re-derived
        // hermetically at f382e73, re-confirmed at 9fc8e86; 2.3 keeps
        // this row (the method-granularity view persists alongside the
        // class pair above). 6.2 impact: was 114 nodes -> 22 removable
        // lines (union 44 x 1/2).
        ['method', 2, 22, 1.0, [
            "preamble_0.php:10-33", "preamble_2.php:10-29",
        ]],
        // P2 cured: whole top-level run (lines 8-17, $rows=[] through the
        // trailing echo) exact across the three scripts.
        // 6.2 impact: was 140 nodes -> 20 removable lines (union 30 x 2/3).
        ['fragment', 3, 20, 1.0, [
            "scripts_0.php:8-17", "scripts_1.php:8-17", "scripts_2.php:8-17",
        ]],
        // 6.2: the four 5-member 3-line control rows converge on 12
        // removable lines each (union 15 x 4/5) — node-scale spread
        // (84/68/48/44) collapses to a tie broken by kind then first member.
        ['if', 5, 12, 1.0, [
            "inline_0.php:19-21", "inline_1.php:19-21", "inline_2.php:19-21",
            "inline_3.php:19-21", "inline_4.php:19-21",
        ]],
        ['if', 5, 12, 1.0, [
            "preamble_0.php:14-16", "preamble_1.php:14-16", "preamble_2.php:14-16",
            "preamble_3.php:14-16", "preamble_4.php:14-16",
        ]],
        ['if', 5, 12, 1.0, [
            "preamble_0.php:19-21", "preamble_1.php:19-21", "preamble_2.php:19-21",
            "preamble_3.php:19-21", "preamble_4.php:19-21",
        ]],
        ['if', 5, 12, 1.0, [
            "preamble_0.php:22-24", "preamble_1.php:22-24", "preamble_2.php:22-24",
            "preamble_3.php:22-24", "preamble_4.php:22-24",
        ]],
        // 6.2: scripts control rows land on a 6-line tie (union 9 x 2/3).
        ['foreach', 3, 6, 1.0, [
            "scripts_0.php:14-16", "scripts_1.php:14-16", "scripts_2.php:14-16",
        ]],
        ['while', 3, 6, 1.0, [
            "scripts_0.php:11-13", "scripts_1.php:11-13", "scripts_2.php:11-13",
        ]],
        // 6.2 impact: was 13 nodes -> 2 removable lines (union 4 x 1/2);
        // still the last row, the established tail-truth for the S15 note.
        ['while', 2, 2, 1.0, [
            "preamble_0.php:29-31", "preamble_2.php:27-27",
        ]],
    ];
    /**
     * What the audit truth says SHOULD be found — one row per blind spot:
     * [pattern id, wave item, should-find, today's measured state].
     */
    public const SHOULD_FIND = [
        ['P1', '2.1 + 1.1', 'one hole-pinned preamble-sequence cluster (lines 12-24) across all 5 preambles',
            'CURED 2026-11-28 (item 2.1): the exact 5-member fragment row (preamble_*:12-26/27, RED_STATE) stitches the shared run across declaration boundaries — statements 12-13 and 17-18 now sit in a sim-1.0 cluster; the whole-method 0.9226 near-dup and the exact p0<->p2 pair row persist as method-level views'],
        ['P2', '1.3', 'the three top-level scripts as one sequence family (one table name apart)',
            'CURED 2026-11-28 (item 2.1): the exact fragment row scripts_*:8-17 carries the whole top-level run (statements 8-10 and 17, one table name folded apart); the inner while/foreach control-block rows persist as narrower views'],
        ['P3', '3.2 + 1.1', 'Env::isDev/baseUrl helper bodies linked to the 5 inline re-implementations (lines 16-17 of inline_*)',
            'Env.php is in ZERO clusters (probe confirmed it only clusters with exact twins); inline copies visible only swallowed in the handle() method'],
        ['P4', '3.3', 'the duplicated $assetBase URL string literal (inline_*:12, 5 copies)',
            'zero members ever cover line 12 — string literals are not extractable blocks'],
        ['P5', '3.3', 'the duplicated STATUS_MAP constant literal (inline_*:11, 5 copies)',
            'zero members ever cover line 11 — class consts are not extractable blocks'],
        ['P6', '2.3', 'Env.php whole-class shape as a duplicate-architecture signal',
            'FOUND 2026-11-28 (item 2.3, opt-in class kind): the five inline_* declarations cluster shape-EXACT at sim 1.0 (same-shape-different-name, handle() verdict parameterizable across the family) and the preamble services surface as the 0.9051 class-family view; Env.php STILL appears in zero clusters — its shape has no twin in this fixture, so the helper↔declaration linkage half of the original P6 phrasing remains 3.2 domain, disclosed honestly rather than overclaimed'],
        ['P6b', '2.3', 'class_shape verdict vocabulary (identical-in-all / parameterizable / divergent) present in payload',
            'FOUND 2026-11-28: every class-lead cluster carries the class_shape table; micro family reads handle parameterizable, go divergent — see testP6ClassShapeComparisonExists'],
    ];

    public function testRedStateSignatureMatchesMeasuredMasterDetection(): void
    {
        $payload = $this->buildPayload();
        $actual = $this->canonicalRows($payload);

        $this->assertSame(
            self::RED_STATE_SIGNATURE,
            $actual,
            self::FLIP_MARKER . ': micro-fixture detection signature drifted — if a wave cure landed, re-derive truth and update RED_STATE_SIGNATURE + SHOULD_FIND deliberately'
        );
    }

    public function testP1PreambleSequenceIsFragmentedNotUnified(): void
    {
        $payload = self::buildPayload();
        $rows = $this->canonicalRows($payload);

        $methodPreamble = null;
        $fragmentPreamble = null;
        // Item 2.3 changed the vehicle, not the truth: the five go()
        // methods are still one inexact family — they now ride inside the
        // 10-member class-family component (5 methods + 5 declarations),
        // so the predicate is containment of all five go ranges, not a
        // 5-member method-only row. Similarity stays < 1.0 (tails differ).
        $goRanges = ['preamble_0.php:10-33', 'preamble_1.php:10-28', 'preamble_2.php:10-29', 'preamble_3.php:10-28', 'preamble_4.php:10-29'];
        foreach ($rows as $row) {
            $pre = (bool) preg_grep('/preamble_/', $row[4]);
            if (array_diff($goRanges, $row[4]) === [] && $pre) {
                $methodPreamble = $row;
            }
            if ($row[0] === 'fragment' && $pre && count($row[4]) === 5) {
                $fragmentPreamble = $row;
            }
        }
        $this->assertNotNull($methodPreamble, 'P1 baseline: 5-preamble go-view expected present (since 2.3 inside the class-family row)');
        $this->assertLessThan(1.0, (float) $methodPreamble[3], 'P1: whole-method preamble match stays inexact (tails differ)');
        // FLIPPED 2026-11-28 (item 2.1): the cross-function statement sequence
        // is now VISIBLE — a 5-member fragment cluster stitches the preamble
        // runs (lines 12-17/18 included) across declaration boundaries at
        // exact similarity. The blind spot ("exists solely inside the sim<1
        // method cluster") is cured; the cure's truth row is pinned here.
        $this->assertNotNull($fragmentPreamble, self::FLIP_MARKER . ' (P1): no 5-member fragment preamble row — cross-function stitching regressed');
        $this->assertSame(1.0, (float) $fragmentPreamble[3], 'P1 cure: stitched preamble fragment is exact');
        $coversGlue = false;
        foreach ($fragmentPreamble[4] as $member) {
            [, $range] = explode(':', $member, 2);
            [$start, $end] = array_map('intval', explode('-', $range));
            if ($start <= 13 && $end >= 17) {
                $coversGlue = true; // acquisition (12-13) AND resolve glue (17-18)
            }
        }
        $this->assertTrue($coversGlue, 'P1 cure: a fragment member covers preamble glue lines 12-13 and 17-18');
    }

    public function testP2TopLevelScriptsAreOnlyVisibleAsControlBlocks(): void
    {
        $rows = $this->canonicalRows($this->buildPayload());
        $fragmentScriptRow = null;
        foreach ($rows as $row) {
            if ($row[0] === 'fragment' && (bool) preg_grep('#scripts_\d\.php#', $row[4])) {
                $fragmentScriptRow = $row;
            }
            // Control-block rows keep the old reach bound (blind-spot
            // baseline for while/foreach members):
            if ($row[0] === 'fragment') {
                continue;
            }
            foreach ($row[4] as $member) {
                if (!preg_match('#scripts_\d\.php:(\d+)-(\d+)#', $member, $x)) {
                    continue;
                }
                [$start, $end] = [(int) $x[1], (int) $x[2]];
                $this->assertTrue(
                    $start >= 11 && $end <= 16,
                    "P2 baseline: control-block script member {$member} escaped 11-16"
                );
            }
        }
        // FLIPPED 2026-11-28 (item 2.1): top-level statements are visible —
        // the whole 6-statement run (lines 8-17, from $rows=[] through the
        // trailing echo) clusters across the three scripts at exact
        // similarity. The blind spot ("only control blocks") is cured.
        $this->assertNotNull($fragmentScriptRow, self::FLIP_MARKER . ' (P2): no fragment row over scripts_* — top-level stitching regressed');
        $this->assertCount(3, $fragmentScriptRow[4], 'P2 cure: all three scripts join the top-level fragment row');
        $this->assertSame(1.0, (float) $fragmentScriptRow[3], 'P2 cure: the stitched top-level run is exact');
        $scriptMembers = [];
        foreach ($rows as $row) {
            foreach ($row[4] as $member) {
                if (str_starts_with($member, 'scripts_')) {
                    $scriptMembers[] = $member;
                }
            }
        }
        $this->assertNotEmpty($scriptMembers, 'P2 baseline: control-block fragments must remain visible');
    }

    public function testP3EnvHelpersAreOrphanedFromInlineCopies(): void
    {
        $rows = $this->canonicalRows($this->buildPayload());
        $envInClusters = 0;
        foreach ($rows as $row) {
            foreach ($row[4] as $member) {
                if (str_starts_with($member, 'Env.php:')) {
                    $envInClusters++;
                }
            }
        }
        $this->assertSame(0, $envInClusters, self::FLIP_MARKER . ' (P3): Env.php helpers now cluster with something — helper↔inline linkage exists, flip P3');
    }

    public function testP4P5LiteralDuplicationIsInvisible(): void
    {
        $rows = $this->canonicalRows($this->buildPayload());
        foreach ($rows as $row) {
            foreach ($row[4] as $member) {
                if (!preg_match('#inline_\d\.php:(\d+)-(\d+)#', $member, $x)) {
                    continue;
                }
                [$start, $end] = [(int) $x[1], (int) $x[2]];
                // Item 2.3 posture: a class declaration SPANS its
                // member lines (inline_*:9-24 covers 11-12 because the
                // declarations live inside it) — that is whole-class
                // shape visibility, NOT literal extraction. The true
                // P4/P5 blind spot stays open until a member covers the
                // literal lines WITHOUT being the enclosing declaration
                // (i.e. a range inside 11-12, the 3.3 mint).
                $isDeclarationSpan = $start <= 9 && $end >= 24;
                $this->assertTrue($start > 12 || $isDeclarationSpan, self::FLIP_MARKER . " (P4/P5): member {$member} now covers the STATUS_MAP/assetBase literal lines 11-12 as a non-declaration block — literals became extractable, flip P4+P5");
            }
        }
    }

    /**
     * FLIPPED 2026-11-28 (item 2.3): class-kind clusters now exist under
     * the opt-in roster and every class-lead cluster carries the
     * class_shape verdict table. The doc's done-when — same-shape-
     * different-name class pairs become findable — is pinned on the
     * inline family; the mixed-family go verdict stays honest to the
     * fixture (tails differ in shape -> divergent, the base-class
     * proposal signal). Env.php remains in zero clusters: its twin does
     * not exist in this fixture (disclosed in the P6 SHOULD_FIND row).
     */
    public function testP6ClassShapeComparisonExists(): void
    {
        $payload = $this->buildPayload();
        $classRows = array_values(array_filter($payload['clusters'], static fn (array $c): bool => ($c['kind'] ?? null) === 'class'));
        $this->assertNotEmpty($classRows, self::FLIP_MARKER . ' (P6): class roster on, zero class-kind clusters — 2.3 regressed');

        $inline = null;
        $mixed = null;
        foreach ($classRows as $c) {
            $names = $c['class_shape']['class_names'] ?? [];
            if ($names === ['CheckoutPageZero', 'OneCheckoutPage', 'TwoCartPage', 'ThreeBillingPage', 'FourSupportPage']) {
                $inline = $c;
            }
            if (count($names) === 5 && in_array('OrderListService', $names, true)) {
                $mixed = $c;
            }
        }
        $this->assertNotNull($inline, 'P6 cure: the five same-shape-different-name inline classes must form the exact class cluster');
        $this->assertSame(1.0, (float) $inline['similarity'], 'P6 cure: the inline class family clusters at exact similarity (skeleton fold blanks every name + literal)');
        $this->assertTrue($inline['class_shape']['shape_exact'], 'P6 cure: class_shape.shape_exact is true for the inline family');
        $this->assertSame(['handle'], array_keys($inline['class_shape']['shared_methods']), 'P6 cure: handle is the family\'s single shared method name');
        $this->assertSame(
            ['members_with_method' => 5, 'distinct_strict_hashes' => 5, 'distinct_fold_hashes' => 1, 'verdict' => 'parameterizable'],
            $inline['class_shape']['shared_methods']['handle'],
            'P6 cure: handle() splits on the strict lane (literal page names) but folds to one shape -> template-method proposal'
        );
        $this->assertNotNull($mixed, 'P6 truth: the preamble class-family row (5 declarations, go-view overlap included) must persist');
        $this->assertFalse($mixed['class_shape']['shape_exact'], 'P6 truth: the preamble family skeletons are NOT identical (tails differ)');
        $this->assertSame(
            ['members_with_method' => 5, 'distinct_strict_hashes' => 5, 'distinct_fold_hashes' => 4, 'verdict' => 'divergent'],
            $mixed['class_shape']['shared_methods']['go'],
            'P6 truth: go() body SHAPE diverges across the family -> flagged for 6.1-grade unification, no false pull-up claim'
        );
        // Class members never contaminate method-lead rows' tables (the
        // method cluster has no class_shape key at all — emit gate is on
        // the class-lead, the table filter is on member kind).
        foreach ($payload['clusters'] as $c) {
            if (($c['kind'] ?? null) === 'class') {
                $this->assertArrayHasKey('class_shape', $c, 'class-lead clusters carry the verdict table');
                foreach ($c['class_shape']['class_names'] as $n) {
                    $this->assertTrue(!in_array($n, ['go', 'handle', 'bootstrap', 'baseUrl'], true), 'class_shape must never list a non-class member name: ' . $n);
                }
            } else {
                $this->assertArrayNotHasKey('class_shape', $c, 'non-class clusters never carry class_shape (conditional-key golden law)');
            }
        }
    }

    public function testTruthManifestCoversSixPatternsAndFileCount(): void
    {
        $this->assertCount(7, self::SHOULD_FIND, 'the Appendix-A truth manifest has six patterns plus the 2.3 verdict-vocabulary row');
        // 10 clusters since the 2026-11-22 wave-3 pair mint (see the row note
        // at RED_STATE_SIGNATURE); 13 since the 2026-11-28 fragment
        // mint (item 2.1: three exact stitched fragment rows); 16 since
        // the 2026-11-28 item-2.3 class mint (+3 class rows, +1 overlap
        // view, preamble method row folded into the family component —
        // see the signature header); the manifest count is pattern
        // state, not cluster count, and tracks SHOULD_FIND rows.
        $this->assertCount(16, $this->buildPayload()['clusters']);
    }

    /** @return array<string,mixed> */
    private function buildPayload(): array
    {
        $dir = realpath(__DIR__ . '/../Fixtures/micro-fixture');
        $this->assertNotFalse($dir, 'micro-fixture directory missing');

        $config = new Config(
            paths: [$dir],
            exclude: Config::defaults([])->exclude,
            maxDocumentFrequency: 0.9,
            minClusterImpact: 1,
            workers: 1,
            lazyAst: false,
            // Item 2.1 (2026-11-28): fragment runs join the roster — the P1/P2
            // cure is the opt-in 'fragment' kind (never implicit, like script).
            // Item 2.3 (2026-11-28): 'class' joins too — P6's whole-class
            // shape comparison is the opt-in class kind (never implicit,
            // same posture); the P6 row below flipped to FOUND.
            allowedKinds: ['function', 'method', 'closure', 'arrow', 'if', 'for', 'foreach', 'while', 'do', 'try', 'switch', 'match', 'fragment', 'class'],
            // 6.2 (E3): this file re-pins the FULL red-state census on every
            // ranking flip, so the run must be cold — a warm cluster cache
            // shared with sibling CLI/make runs replayed stale membership
            // here once (the default cacheDir lives inside the checkout).
            cacheDir: sys_get_temp_dir() . '/phpdup-e3-micro-' . getmypid() . '-' . (string) getmyuid(),
        );
        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        $report = new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
        $payload = (new JsonReporter())->build($report);
        $this->assertCount(14, $state->files, 'fixture must contain exactly 14 files');
        $this->assertSame(0, $state->parseErrors, 'fixtures must parse cleanly');

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{0:string,1:int,2:int,3:float,4:list<string>}>
     */
    private function canonicalRows(array $payload): array
    {
        $rows = [];
        foreach ($payload['clusters'] as $c) {
            $members = [];
            foreach ((array) $c['members'] as $m) {
                $members[] = basename((string) $m['file']) . ':' . (int) $m['start'] . '-' . (int) $m['end'];
            }
            sort($members);
            $rows[] = [(string) $c['kind'], count($members), (int) $c['impact'], (float) $c['similarity'], $members];
        }
        usort($rows, static fn (array $a, array $b): int => [$b[2], $a[0], $a[4][0]] <=> [$a[2], $b[0], $b[4][0]]);
        return $rows;
    }
}
