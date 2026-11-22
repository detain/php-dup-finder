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
 * each flip one pattern row below (and the signature). When a row's real
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
     * row note).
     * Each row: [kind, memberCount, impact, similarity, sortedMembers(file:start-end)].
     * Sorted canonically by impact desc, then kind, then first member.
     */
    public const RED_STATE_SIGNATURE = [
        // Flip 2026-11-21 (wave cure 4c543f3 engine-S02a): row 0 similarity
        // 0.8903 -> 0.9226. The binding edge is the Containment-tier pair
        // preamble_0.php:10-33 <-> preamble_1.php:10-28. Pre-cure, aggressive
        // ordinal call-renaming cascaded across the tail (p0's extra
        // next_record shifted json_response to __CALL8 while p1 kept
        // __CALL6, plus a spurious 6/6 ordinal collision), costing 5 of 155
        // ngram matches; name-stamped sentinels (__CALL_json_response on
        // both sides, folded to the family base in the bag) restore honest
        // shared-vocabulary alignment: common mass 138/155 -> 143/155,
        // i.e. 0.8903225806 -> 0.9225806452. Re-derived hermetically
        // (git-archive extract + hardlinked vendor, earliest mover located
        // by bisection dbbf493..HEAD); all other eight rows byte-identical.
        // Similarity stays < 1.0 — P1 remains an unfixed blind spot.
        ['method', 5, 434, 0.9226, [
            "preamble_0.php:10-33", "preamble_1.php:10-28", "preamble_2.php:10-29",
            "preamble_3.php:10-28", "preamble_4.php:10-29",
        ]],
        ['method', 5, 312, 1.0, [
            "inline_0.php:14-23", "inline_1.php:14-23", "inline_2.php:14-23",
            "inline_3.php:14-23", "inline_4.php:14-23",
        ]],
        // Flip 2026-11-22 (audit-leg discipline honoured: "if a wave cure
        // landed, re-derive truth and update RED_STATE_SIGNATURE + SHOULD_FIND
        // deliberately"). CAUSE (disk-proven, engine-S15 r1 verdict §1): the
        // 10th cluster is engine-S15 d997604's exact-bucket re-emission — a
        // structural-hash-exact method bucket previously suppressed while
        // riding the weaker 5-preamble component. Causal evidence: parent
        // f583c1e ran this file 7/7 green (Integration OK 87/1558); d997604
        // produced exactly these two failures. Era context (cures since the
        // f541a8ad re-pin): engine-S18a int-postings 588c431, engine-S18b
        // gram-dict interning 15c4a0c, engine-S15 tier-honesty d997604,
        // engine-S09 memoise 7fc37bf/ecedabd, engine-S31b/c + shell-* batches.
        // Detection strictly IMPROVED: an exact method-pair cluster is now
        // minted for preamble_0.php:10-33 <-> preamble_2.php:10-29. Truth
        // adjudication on the fixture bytes: the 13-line preamble (lines
        // 12-24) is BYTE-IDENTICAL across all five preamble_* files, and
        // p0/p2 are the only pair whose tails are also parameter-equivalent
        // (query -> accumulator init -> while-append -> json_response,
        // differing only in string/identifier literals — the fixture's
        // designed parameterization); this pin ALREADY recorded their
        // while-tails as an exact 1.0 clone (preamble_0.php:29-31 <->
        // preamble_2.php:27-27, last row). The pair row lifts that
        // established tail truth to method granularity: a genuine
        // parameterizable clone, precisely what the tool exists to find.
        // p1/p3/p4 tails differ in statement shape (e.g. p1's count(*)/
        // return one-liner), so the 5-member cluster correctly stays at
        // sim 0.9226 < 1.0 — P1 remains open. SHOULD_FIND DECISION (kept at
        // 6 patterns, deliberately): the manifest lists BLIND SPOTS, and the
        // new pair is detected truth, not a blind spot — a 7th entry would
        // invert the manifest's meaning. P1's should-find (one hole-pinned
        // preamble-sequence cluster across ALL 5 preambles) remains unmet:
        // the pair covers 2 of 5 at method level without exposing the
        // 12-24 fragment. Rows re-derived hermetically at f382e73 and
        // re-confirmed at 9fc8e86 (git-archive extracts + hardlinked
        // read-only vendor, PHP 8.3): all nine pre-existing rows
        // byte-identical, only this row inserted (clusters 9 -> 10).
        ['method', 2, 114, 1.0, [
            "preamble_0.php:10-33", "preamble_2.php:10-29",
        ]],
        ['if', 5, 84, 1.0, [
            "inline_0.php:19-21", "inline_1.php:19-21", "inline_2.php:19-21",
            "inline_3.php:19-21", "inline_4.php:19-21",
        ]],
        ['if', 5, 68, 1.0, [
            "preamble_0.php:22-24", "preamble_1.php:22-24", "preamble_2.php:22-24",
            "preamble_3.php:22-24", "preamble_4.php:22-24",
        ]],
        ['if', 5, 48, 1.0, [
            "preamble_0.php:19-21", "preamble_1.php:19-21", "preamble_2.php:19-21",
            "preamble_3.php:19-21", "preamble_4.php:19-21",
        ]],
        ['while', 3, 48, 1.0, [
            "scripts_0.php:11-13", "scripts_1.php:11-13", "scripts_2.php:11-13",
        ]],
        ['if', 5, 44, 1.0, [
            "preamble_0.php:14-16", "preamble_1.php:14-16", "preamble_2.php:14-16",
            "preamble_3.php:14-16", "preamble_4.php:14-16",
        ]],
        ['foreach', 3, 28, 1.0, [
            "scripts_0.php:14-16", "scripts_1.php:14-16", "scripts_2.php:14-16",
        ]],
        ['while', 2, 13, 1.0, [
            "preamble_0.php:29-31", "preamble_2.php:27-27",
        ]],
    ];

    /**
     * What the audit truth says SHOULD be found — one row per blind spot:
     * [pattern id, wave item, should-find, today's measured state].
     */
    public const SHOULD_FIND = [
        ['P1', '2.1 + 1.1', 'one hole-pinned preamble-sequence cluster (lines 12-24) across all 5 preambles',
            'whole-method 5-preamble near-dup at sim 0.9226 + (since the 2026-11-22 wave-3 mint) an exact p0<->p2 method pair from parameter-equivalent tails; statements 12-13 and 17-18 still never appear as shared fragments — no hole-pinned sequence view yet'],
        ['P2', '1.3', 'the three top-level scripts as one sequence family (one table name apart)',
            'only inner while/foreach control blocks cluster; script statements (lines 8-10, 17) invisible'],
        ['P3', '3.2 + 1.1', 'Env::isDev/baseUrl helper bodies linked to the 5 inline re-implementations (lines 16-17 of inline_*)',
            'Env.php is in ZERO clusters (probe confirmed it only clusters with exact twins); inline copies visible only swallowed in the handle() method'],
        ['P4', '3.3', 'the duplicated $assetBase URL string literal (inline_*:12, 5 copies)',
            'zero members ever cover line 12 — string literals are not extractable blocks'],
        ['P5', '3.3', 'the duplicated STATUS_MAP constant literal (inline_*:11, 5 copies)',
            'zero members ever cover line 11 — class consts are not extractable blocks'],
        ['P6', '2.3', 'Env.php whole-class shape as a duplicate-architecture signal',
            'Env.php appears in no cluster; class-level shape comparison does not exist'],
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
        $ifPreambleRows = [];
        foreach ($rows as $row) {
            $pre = (bool) preg_grep('/preamble_/', $row[4]);
            if ($row[0] === 'method' && $pre && count($row[4]) === 5) {
                $methodPreamble = $row;
            }
            if ($row[0] === 'if' && $pre) {
                $ifPreambleRows[] = $row;
            }
        }
        $this->assertNotNull($methodPreamble, 'P1 baseline: whole-method 5-preamble cluster expected present');
        $this->assertNotEmpty($ifPreambleRows, 'P1 baseline: preamble if-fragment clusters expected present');
        $this->assertLessThan(1.0, (float) $methodPreamble[3], self::FLIP_MARKER . ': method match became exact — sequence fragment now visible, flip P1');
        // The signature carries 5-member preamble if-fragments at 14-16 /
        // 19-21 / 22-24 only; the acquisition statements (lines 12-13) and the
        // resolve/get_service glue (17-18) are covered by NO fragment — the
        // 9-line sequence (10-24) exists solely inside the sim<1 method cluster.
        foreach ($ifPreambleRows as $row) {
            foreach ($row[4] as $member) {
                [, $range] = explode(':', $member, 2);
                [$start, $end] = array_map('intval', explode('-', $range));
                $this->assertFalse(
                    ($start <= 13 && $end >= 12) || ($start <= 18 && $end >= 17),
                    self::FLIP_MARKER . " (P1): if-fragment {$member} now covers preamble glue lines — flip P1"
                );
            }
        }
    }

    public function testP2TopLevelScriptsAreOnlyVisibleAsControlBlocks(): void
    {
        $rows = $this->canonicalRows($this->buildPayload());
        foreach ($rows as $row) {
            foreach ($row[4] as $member) {
                if (!preg_match('#scripts_\d\.php:(\d+)-(\d+)#', $member, $x)) {
                    continue;
                }
                [$start, $end] = [(int) $x[1], (int) $x[2]];
                $this->assertTrue(
                    $start >= 11 && $end <= 16,
                    self::FLIP_MARKER . " (P2): script member {$member} reaches top-level statements — flip P2"
                );
            }
        }
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
                $this->assertTrue($start > 12, self::FLIP_MARKER . " (P4/P5): member {$member} now covers the STATUS_MAP/assetBase declaration lines 11-12 — literals became visible, flip P4+P5");
            }
        }
    }

    public function testP6WholeClassShapeComparisonDoesNotExist(): void
    {
        $rows = $this->canonicalRows($this->buildPayload());
        foreach ($rows as $row) {
            $this->assertNotSame('class', $row[0], self::FLIP_MARKER . ' (P6): a class-kind cluster appeared — flip P6');
        }
    }

    public function testTruthManifestCoversSixPatternsAndFileCount(): void
    {
        $this->assertCount(6, self::SHOULD_FIND, 'the Appendix-A truth manifest has exactly six patterns');
        // 10 clusters since the 2026-11-22 wave-3 pair mint (see the row note
        // at RED_STATE_SIGNATURE index 2); the manifest count 6 is pattern
        // state, not cluster count, and stays.
        $this->assertCount(10, $this->buildPayload()['clusters']);
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
        );
        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false))->run($state, $out);
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
