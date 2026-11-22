<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use PhpParser\PrettyPrinter\Standard;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\RefactorTestReporter;
use Phpdup\Util\LineRange;

/**
 * once-S35 (php-dup-finder-once-039) — the RefactorTestReporter half of the
 * hole-row bookkeeping: rows are RESOLVED BY MEMBER ID (`rowForId`), and a
 * member whose hole carries no row renders an explicit structural gap
 * instead of the retired positional `?? null` masquerade.
 *
 * This file is the once-S35 lane's own territory. The injection-sink /
 * quoting pins for the SAME class live in the sibling
 * `Phpdup\Tests\Unit\Reporting\RefactorTestReporterTest` (output-S35
 * loop, cee7030) — ADD-never-rewrite governs that file; nothing here
 * restates or edits those pins.
 */
final class RefactorTestReporterTest extends TestCase
{
    public function testHoleShortOfMemberCountRendersExplicitGapNotBareFalseOrNull(): void
    {
        // The keyed-lookup acceptance: pre-once-S35 the positional
        // `?? null` sent a member with NO recorded row into the old null
        // arm — `false` on optional holes (a VERIFIED-absence claim the
        // evidence never made — the gap-row-as-present/as-absent lie
        // output-S27 legislated against) and a bare `null` on value holes.
        // An unrecorded row is now an annotated structural gap: still
        // parseable, never silently counted.
        $cluster = $this->cluster(3, ['X', 'Y', 'Z']);
        $cluster->holes = [
            new Hole('__P0', 'literal', ['1', '2']),
            new Hole('__O0', 'optional_block', ["'x();'"]),
        ];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertStringContainsString('member_0_', $skeleton);
        $this->assertStringContainsString('member_1_', $skeleton);
        $this->assertStringContainsString('member_2_', $skeleton);

        $this->assertMatchesRegularExpression(
            "/'member_2_[^']*'\s*=>\s*\[[^\]]*null \/\* degraded: member had no recorded row for this hole \*\/[^\]]*null[^]]*\]/",
            $skeleton,
            'the unaddressed member gets the gap annotation in BOTH hole slots',
        );

        // The honest arms stay exact for the members that DO have rows.
        $this->assertMatchesRegularExpression("/'member_0_[^']*'\s*=>\s*\[\s*1,/", $skeleton);
        $this->assertMatchesRegularExpression("/'member_1_[^']*'\s*=>\s*\[\s*2,/", $skeleton);

        // The retired masquerade is unreachable: no provider row may end
        // on a bare false (an unverified "skip the segment" claim), and no
        // bare 'null' may follow a comma into the row tail.
        $this->assertStringNotContainsString(', false]', $skeleton);
        $this->assertStringNotContainsString(', null]', $skeleton);

        $this->assertLints($skeleton);
    }

    public function testVerifiedArmsStayByteIdenticalUnderKeyedLookup(): void
    {
        // The keyed resolve must not disturb the S27 projection law it
        // inherits: a MISSING row on an honest optional hole still reads
        // `false`, a degraded MISSING still annotates, and the legacy
        // printable sentinels keep their belt arms. Same expectations the
        // Reporting-side pins hold — restated here only for the resolve
        // path, never over the sibling file.
        $cluster = $this->cluster(2, ['X', 'Y']);
        $cluster->holes = [
            new Hole('__O0', 'optional_block', ["'p();'", Hole::MISSING], false),
        ];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertMatchesRegularExpression("/'member_1_[^']*'\s*=>\s*\[\s*false\s*\]/", $skeleton);
        $this->assertStringNotContainsString('degraded', $skeleton);

        $cluster->holes = [
            new Hole('__P0', 'literal', ['<missing>', "'kept'"], true),
        ];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertStringContainsString('null /* degraded: observed value was a structural gap */', $skeleton);
        $this->assertStringNotContainsString('<missing>', $skeleton);

        $cluster->holes = [
            new Hole('__O1', 'optional_block', ['<absent>', "'q();'"]),
        ];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $this->assertMatchesRegularExpression("/'member_0_[^']*'\s*=>\s*\[\s*false\s*\]/", $skeleton);
    }

    public function testIdlessClusterAddressesByPositionAndStaysAligned(): void
    {
        // Hand-built fixtures reach the resolve with EMPTY ids (only
        // PreprocessStage stamps them) — the keyed lookup falls back to a
        // per-position key and the aligned list still lands beside its
        // member.
        $cluster = $this->cluster(2, ['', '']);
        $cluster->holes = [new Hole('__P0', 'literal', ['p', 'q'])];
        $skeleton = (new RefactorTestReporter())->buildTest($cluster);

        $this->assertMatchesRegularExpression("/'member_0_[^']*'\s*=>\s*\[\s*'p'\s*\]/", $skeleton);
        $this->assertMatchesRegularExpression("/'member_1_[^']*'\s*=>\s*\[\s*'q'\s*\]/", $skeleton);
    }

    public function testEndToEndSeedReorderPutsEachMemberOwnCallOnItsOwnRow(): void
    {
        // The producer↔consumer chain across the once-S35 rewrite. The
        // seed must be a NON-ZERO member under the REAL selection law
        // (fewest holes wins, largest size ranks first): member 1's call
        // carries one extra node (the unary plus), so it is both the
        // largest and the tightest template — every other seed shape
        // forces two holes where this one forces a single subtree hole.
        // AntiUnifier must remap its dense local-order rows to cluster
        // order addressed by member id — and each provider row must then
        // carry that member's OWN call. Any shift (rows left in walk
        // order, or re-paired wrongly) puts a foreign call on the row.
        $cluster = $this->pipelineCluster([
            'function w0($x) { k(1); }',
            'function w1($x) { +(k(2)); }',
            'function w2($x) { k(3); }',
        ], ['CIDX0', 'CIDXSEED', 'CIDX2']);

        (new AntiUnifier())->unify($cluster);

        $printer = new Standard();
        $this->assertSame(
            trim($printer->prettyPrint([$cluster->members[1]->ast])),
            trim($printer->prettyPrint([$cluster->generalizedAst])),
            'fixture vacuity crux: the seed must be cluster index 1 (a true reorder)',
        );

        $this->assertCount(1, $cluster->holes);
        $hole = $cluster->holes[0];
        $this->assertSame('subtree', $hole->kind);
        $this->assertSame('k(1)', $hole->observedValues[0], 'row 0 owns member 0’s call — not the seed’s');
        $this->assertStringContainsString('k(2)', $hole->observedValues[1]);
        $this->assertSame('k(3)', $hole->observedValues[2]);
        $this->assertFalse($hole->isDegraded(), 'every member was observed at the path');
        $this->assertArrayHasKey($hole->placeholder, $cluster->holePaths);
        $this->assertNotEmpty($cluster->holePaths[$hole->placeholder]);

        $skeleton = (new RefactorTestReporter())->buildTest($cluster);
        $lines = array_values(array_filter(
            explode("\n", $skeleton),
            static fn(string $l): bool => str_contains($l, '=> ['),
        ));
        $this->assertCount(3, $lines, 'one provider row per member, in member order');
        $this->assertStringContainsString("'member_0_", $lines[0]);
        $this->assertStringContainsString("'member_1_", $lines[1]);
        $this->assertStringContainsString("'member_2_", $lines[2]);
        $this->assertStringContainsString('k(1)', $lines[0]);
        $this->assertStringContainsString('k(2)', $lines[1]);
        $this->assertStringContainsString('k(3)', $lines[2]);
        $this->assertStringNotContainsString('k(2)', $lines[0]);
        $this->assertStringNotContainsString('k(1)', $lines[1]);
        $this->assertStringNotContainsString('k(2)', $lines[2]);
        $this->assertLints($skeleton);
    }

    public function testEndToEndChainByteIdenticalAcrossIdAddressedAndPositionalFallback(): void
    {
        // The same reorder run without ids (positional 0↔seedIdx fallback)
        // must yield byte-identical skeletons: the id-addressed remap is a
        // stronger ESTABLISHMENT of the same cluster-order invariant, not
        // a payload change — the ApplyExtractor/SignatureBuilder consumers
        // of the S44/S46/S48 chain cannot observe a difference.
        $codes = [
            'function w0($x) { k(1); }',
            'function w1($x) { +(k(2)); }',
            'function w2($x) { k(3); }',
        ];

        $stamped = $this->pipelineCluster($codes, ['CIDX0', 'CIDXSEED', 'CIDX2']);
        (new AntiUnifier())->unify($stamped);

        $unstamped = $this->pipelineCluster($codes, ['', '', '']);
        (new AntiUnifier())->unify($unstamped);

        $reporter = new RefactorTestReporter();
        $this->assertSame(
            $reporter->buildTest($unstamped),
            $reporter->buildTest($stamped),
        );
    }

    /** @param list<string> $functions @param list<string> $ids @return Cluster */
    private function pipelineCluster(array $functions, array $ids): Cluster
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = [];
        foreach ($functions as $i => $code) {
            $stmts     = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("ch{$i}.php", $stmts);
            $this->assertNotEmpty($extracted);
            // Block ids are assigned by PreprocessStage in the real
            // pipeline; stamp deterministic stand-ins so the id-addressed
            // remap is exercised (once-039).
            $extracted[0]->id = $ids[$i];
            $blocks[]         = $extracted[0];
        }
        return new Cluster('CHAIN', $blocks, 1.0, false);
    }

    /** @param list<string> $ids */
    private function cluster(int $memberCount, array $ids): Cluster
    {
        $blocks = [];
        for ($i = 0; $i < $memberCount; $i++) {
            $blocks[] = $this->blk("t{$i}.php", $ids[$i]);
        }
        return new Cluster('KEYED', $blocks, 1.0, false);
    }

    private function blk(string $file, string $id): Block
    {
        $stmts = (new AstParser())->parseCode('<?php $x;');
        $block = new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
        $block->id = $id;
        return $block;
    }

    private function assertLints(string $skeleton): void
    {
        $tmpFile = sys_get_temp_dir() . '/phpdup_once_s35_' . uniqid() . '.php';
        try {
            file_put_contents($tmpFile, $skeleton);
            $result = shell_exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1');
            $this->assertStringContainsString('No syntax errors', $result ?? '', 'skeleton must parse');
        } finally {
            @unlink($tmpFile);
        }
    }
}
