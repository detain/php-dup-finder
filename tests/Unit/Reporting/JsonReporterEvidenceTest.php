<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;
use Phpdup\Util\PathCanonical;

/**
 * Item 5.4(e): the trailing-additive evidence keys on every cluster —
 * template identity, capped source text, evidence diff, hole coverage,
 * re-expression posture — and the degraded/null hole-value belt.
 */
final class JsonReporterEvidenceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-ev-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testClusterKeysStayTrailingAdditiveAfterScope(): void
    {
        $payload = $this->payloadFor($this->pairCluster(), 1.0);
        $keys    = array_keys($payload['clusters'][0]);

        // Everything through `scope` must keep its exact 1.4 position, and
        // the six 1.5 keys plus the 1.6 `placement` key append after it.
        $this->assertSame('scope', $keys[14], '1.4 shape occupies indices 0..14');
        $this->assertSame(
            ['template', 'source', 'evidence_diff', 'hole_coverage', 're_expressed', 'hole_fragment', 'placement'],
            array_slice($keys, 15),
        );
        $this->assertSame(JsonSchemaSpec::SCHEMA_VERSION, $payload['schema_version']);
    }

    public function testTemplateResolvesToTheMedoidNotMembersZero(): void
    {
        $cluster = $this->pairCluster();
        $cluster->medoidId = $cluster->members[1]->id;

        $payload = $this->payloadFor($cluster, 1.0);
        $t = $payload['clusters'][0]['template'];

        $this->assertSame($cluster->members[1]->id, $t['id']);
        $this->assertSame($cluster->members[1]->file, $t['file']);
        $this->assertSame($cluster->members[1]->range->start, $t['start']);
    }

    public function testSourceCarriesRealTextAndEvidenceDiffAgainstPeer(): void
    {
        $cluster = $this->pairCluster();
        $payload = $this->payloadFor($cluster, 1.0);
        $c       = $payload['clusters'][0];

        $this->assertStringContainsString('if ($s > 1)', (string)$c['source']['text']);
        $this->assertFalse($c['source']['truncated']);

        $ev = $c['evidence_diff'];
        $this->assertNotNull($ev, 'members differ textually — evidence must show it');
        $this->assertSame($cluster->members[1]->id, $ev['member']);
        $this->assertStringContainsString('@@', (string)$ev['diff']);
        $this->assertFalse($ev['truncated']);
    }

    public function testEvidenceDiffNullWhenSourcesIdentical(): void
    {
        // Same text in two files: zero-hole pair, identical sources → no
        // textual evidence exists; emit null rather than an empty-diff blob.
        $cluster = $this->pairCluster('if ($s > 1) { x(); }', 'if ($s > 1) { x(); }');
        $payload = $this->payloadFor($cluster, 1.0);

        $this->assertNull($payload['clusters'][0]['evidence_diff']);
        $this->assertEqualsWithDelta(0.0, (float)$payload['clusters'][0]['hole_coverage'], 0.0001);
        $this->assertFalse($payload['clusters'][0]['re_expressed']);
    }

    public function testDegradedHoleSerializesGapsAsNullWithFlag(): void
    {
        $cluster = $this->pairCluster();
        // Hand-made degraded hole (the shape (b) produces for xor-null paths)
        // plus a legacy-cache hole carrying the old impostor string.
        $cluster->holes = [
            new Hole('__P0', 'literal', ['', "'x'"], true),
            new Hole('__P1', 'literal', ['<missing>', "'y'"], false),
        ];

        $holes = $this->payloadFor($cluster, 1.0)['clusters'][0]['holes'];
        $this->assertSame([null, "'x'"], $holes[0]['observed']);
        $this->assertTrue($holes[0]['degraded']);
        // Legacy belt: a pre-5.4 cache value is normalized AND flagged —
        // a reader must never see the impostor string.
        $this->assertSame([null, "'y'"], $holes[1]['observed']);
        $this->assertTrue($holes[1]['degraded'], 'legacy <missing> normalizes with degraded=true');
    }

    public function testDefaultThresholdNeverFiresEvenOnFullCoverage(): void
    {
        $cluster = $this->pairCluster();
        $cluster->holePaths = ['__P0' => []];   // whole-template hole → coverage 1.0

        $c = $this->payloadFor($cluster, 1.0)['clusters'][0];
        $this->assertEqualsWithDelta(1.0, (float)$c['hole_coverage'], 0.0001);
        $this->assertFalse($c['re_expressed'], 'strict > posture: default 1.0 stays byte-identical');
        $this->assertNull($c['hole_fragment']);
    }

    public function testFiredThresholdMarksAndAnnotatesWithoutDropping(): void
    {
        $cluster = $this->pairCluster();
        $cluster->holePaths = ['__P0' => []];

        $c = $this->payloadFor($cluster, 0.5)['clusters'][0];
        $this->assertTrue($c['re_expressed']);
        $this->assertNull($c['hole_fragment'], 'no hole-free run in a fully-holed template');
        $this->assertArrayHasKey('holes', $c, 'evidence annotated, never dropped');
    }

    public function testFragmentSurfacesTheHoleFreeRunWhenThresholdFires(): void
    {
        $cluster = $this->threeStmtClusterWithFirstStmtHole();

        $c = $this->payloadFor($cluster, 0.01)['clusters'][0];
        $this->assertTrue($c['re_expressed']);
        $frag = $c['hole_fragment'];
        $this->assertNotNull($frag);
        $this->assertSame(1, $frag['stmt_start']);
        $this->assertSame(2, $frag['stmt_length']);
        $this->assertStringContainsString('y();', (string)$frag['code']);
        $this->assertStringContainsString('z();', (string)$frag['code']);
        $this->assertFalse($frag['truncated']);
    }

    public function testSourceTruncationIsMarkedHonestly(): void
    {
        $file = $this->tmp . '/huge.php';
        $body = "<?php\nfunction huge() {\n";
        for ($i = 0; $i < 300; $i++) {
            $body .= "    echo {$i};\n";
        }
        $body .= "}\n";
        file_put_contents($file, $body);

        $block = $this->extract($file)[0];
        $block->id = 'HUGE';
        $cluster = new Cluster('HG', [$block], 1.0, false);

        $c = $this->payloadFor($cluster, 1.0)['clusters'][0];
        $this->assertTrue($c['source']['truncated']);
        $this->assertStringContainsString('truncated: showing', (string)$c['source']['text']);
        // Cap holds with slack for the marker line (200 lines / 8 KiB caps
        // live as private consts; the marker is ~60 bytes).
        $this->assertLessThanOrEqual(8400, strlen((string)$c['source']['text']));
    }

    // ── output-011: the evidence diff header is a stream surface too ────────

    public function testEvidenceDiffRefusesNewlineBearingFilePaths(): void
    {
        // The evidence field is a decoded consumer's patch stream: a forged
        // `--- a/…` / `+++ b/…` header rides inside it. Both embedding sites
        // must refuse, each naming its field.
        foreach ([0 => 'template file path', 1 => 'evidence peer file path'] as $i => $what) {
            $cluster = $this->pairCluster();          // real, differing sources → evidence emits
            $cluster->members[$i]->file .= "\n--- /dev/null";
            try {
                $this->payloadFor($cluster, 1.0);
                $this->fail("member[{$i}] path with a newline must refuse the build");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('JsonReporter', $e->getMessage());
                $this->assertStringContainsString($what, $e->getMessage());
                $this->assertStringContainsString('newline (0x0A)', $e->getMessage());
            }
        }
    }

    public function testEvidenceDiffStillEmitsForCleanPathsSchemaUnchanged(): void
    {
        // Belt: the guard is inert on honest corpora — the 1.6 payload shape
        // (and therefore the goldens) is byte-identical to before.
        $cluster = $this->pairCluster();
        $payload = $this->payloadFor($cluster, 1.0);
        $this->assertSame(JsonSchemaSpec::SCHEMA_VERSION, $payload['schema_version']);
        $ev = $payload['clusters'][0]['evidence_diff'];
        $this->assertNotNull($ev);
        // The evidence diff's own header still pairs the real paths — in
        // git's join convention (once-S101 fix1: these fixture paths are
        // absolute, so the leading slash rides inside the `--- a` + operand
        // pair — `--- a/tmp/...`, never the double-slash `--- a//tmp/...`).
        $this->assertStringContainsString(
            '--- a' . $cluster->members[0]->file . "\n+++ b" . $cluster->members[1]->file . "\n",
            (string)$ev['diff'],
        );
    }

    // ── output-038: unfetchable sources take the shape's own null posture ────

    public function testUnfetchableTemplateSourceIsNullNotPlaceholder(): void
    {
        $cluster = $this->pairCluster();
        unlink($this->tmp . '/m0.php'); // template vanishes between scan and report

        $payload = $this->payloadFor($cluster, 1.0);
        $source  = $payload['clusters'][0]['source'];
        $this->assertNull($source['text'], 'a vanished source is null — the <source unavailable: …> string is gone');
        $this->assertFalse($source['truncated']);
        $this->assertStringNotContainsString('<source unavailable', json_encode($source) ?: '');
        $this->assertSame(JsonSchemaSpec::SCHEMA_VERSION, $payload['schema_version'], 'null posture needs no schema bump: text already declares null');
    }

    public function testEvidenceDiffNullWhenPeerSourceUnfetchable(): void
    {
        $cluster = $this->pairCluster();
        unlink($this->tmp . '/m1.php'); // only the peer is gone

        $payload     = $this->payloadFor($cluster, 1.0);
        $clusterData = $payload['clusters'][0];
        $this->assertIsString($clusterData['source']['text'], 'the fetchable template still serializes its real bytes');
        $this->assertNull($clusterData['evidence_diff'], 'no evidence can be built against bytes never fetched — null, not a placeholder diff');
    }

    // ── once-S101 fix1 (php-dup-finder-once-126 XP-11): git-style operands ──

    public function testEvidenceHeadersNeverMintDoubleSlashForAbsolutePaths(): void
    {
        $cluster = $this->pairCluster();
        $ev      = $this->payloadFor($cluster, 1.0)['clusters'][0]['evidence_diff'];
        $diff    = (string)$ev['diff'];
        $fileA   = $cluster->members[0]->file;
        $fileB   = $cluster->members[1]->file;
        $this->assertStringStartsWith('/', $fileA, 'fixture members carry absolute paths');

        // The bug shape was `--- a//tmp/...` — a doubled slash no
        // unified-diff reader produces. Git's convention consumes the
        // leading slash into the a//b/ join; assert both spellings.
        $this->assertStringStartsWith('--- a' . $fileA . "\n", $diff);
        $this->assertStringContainsString('+++ b' . $fileB . "\n", $diff);
        $this->assertStringNotContainsString('--- a//', $diff);
        $this->assertStringNotContainsString('+++ b//', $diff);

        // The fix is the header join ONLY: payload member fields keep the
        // raw scanner path JSON consumers match against scan output.
        $this->assertSame($fileB, $ev['file']);
    }

    public function testDiffOperandJoinSpellsBothPosturesAsLiterals(): void
    {
        // Literals, not a mirror of the implementation: relative paths
        // keep the git-style `a/` prefix join; absolute operands shed
        // exactly the leading slash into that join; a backslash-joined
        // Windows path arrives in canonical slash form (PathCanonical's
        // class-wide separator law).
        $this->assertSame('a/tests/Fixtures/Pair.php', 'a/' . PathCanonical::diffOperand('tests/Fixtures/Pair.php'));
        $this->assertSame('a/tmp/phpdup-ev-1/m0.php', 'a/' . PathCanonical::diffOperand('/tmp/phpdup-ev-1/m0.php'));
        $this->assertSame('a/C:/proj/src/A.php', 'a/' . PathCanonical::diffOperand('C:\proj\src\A.php'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function payloadFor(Cluster $cluster, float $threshold): array
    {
        $config = new Config(paths: [$this->tmp], exclude: [], maxHoleCoverage: $threshold);
        $report = new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: [$cluster], config: $config,
        );
        return (new JsonReporter())->build($report);
    }

    private function pairCluster(
        string $a = 'if ($s > 1) { send($u); }',
        string $b = 'if ($s > 2) { send($v); }',
    ): Cluster {
        $blocks = [];
        foreach ([$a, $b] as $i => $code) {
            $file  = $this->tmp . "/m{$i}.php";
            file_put_contents($file, "<?php\n" . $code . "\n");
            $block     = $this->extract($file)[0];
            $block->id = "BLK{$i}";
            $blocks[]  = $block;
        }
        $cluster        = new Cluster('PAIR', $blocks, 0.9, false);
        $cluster->pairs = [[
            'blockA' => 'BLK0', 'blockB' => 'BLK1', 'matchTier' => 'jaccard', 'matchScore' => 0.9,
        ]];
        (new AntiUnifier())->unify($cluster);
        return $cluster;
    }

    private function threeStmtClusterWithFirstStmtHole(): Cluster
    {
        // Function_ root so the fragment operates on the BODY statement
        // list: stmts/0 carries the literal hole, y()/z() stay hole-free.
        $blocks = [];
        foreach (['function t($s) { if ($s > 1) { q(); } y(); z(); }', 'function t($s) { if ($s > 2) { q(); } y(); z(); }'] as $i => $code) {
            $file  = $this->tmp . "/t{$i}.php";
            file_put_contents($file, "<?php\n" . $code . "\n");
            $block     = $this->extract($file)[0];
            $block->id = "BLK{$i}";
            $blocks[]  = $block;
        }
        $cluster = new Cluster('FRAG', $blocks, 0.9, false);
        (new AntiUnifier())->unify($cluster);
        return $cluster;
    }

    /** @return list<Block> */
    private function extract(string $file): array
    {
        $stmts     = (new AstParser())->parseCode((string)file_get_contents($file));
        $extracted = (new BlockExtractor(minSize: 1))->extract($file, $stmts);
        $this->assertNotEmpty($extracted, "no block extracted from {$file}");
        return $extracted;
    }
}
