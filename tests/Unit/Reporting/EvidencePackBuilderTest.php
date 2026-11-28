<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Architecture\Finding;
use Phpdup\Census\HelperCopy;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Git\ClusterHistory;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Reporting\EvidencePackBuilder;
use Phpdup\Reporting\FindingId;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SourceTextStore;
use Phpdup\Util\LineRange;
use PhpParser\Node\Stmt\Nop;

/**
 * Item 8.1: evidence packs — the pure build() seam, the honest truncation/
 * absence postures, the 3.2/4.1/4.2/5.1 joins, the TOP_K size cap, and the
 * output-011 boundary law at the file-landing door.
 */
final class EvidencePackBuilderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string)sys_get_temp_dir() . '/phpdup-pack-' . uniqid('', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(glob($this->tmp . '/{,*/}*', GLOB_BRACE) ?: []) as $f) {
            if (is_dir($f)) {
                @rmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($this->tmp);
    }

    // ── shape & ordering ─────────────────────────────────────────────────────

    public function testPackPerFindingInRankOrderSingletonsSkipped(): void
    {
        $pair      = $this->pairCluster();
        $singleton = $this->bareCluster('SOLO', [$this->bareBlock('ghost.php', 1, 3)]);

        $files = (new EvidencePackBuilder($this->quietStore()))->build($this->report([$singleton, $pair]));

        // Solo rides rank 1 (position in the ordered report) but earns no
        // pack; the pair's pack file name carries that rank.
        $this->assertCount(1, $files);
        $name = (string)array_key_first($files);
        $this->assertMatchesRegularExpression('/^002-F[0-9a-f]{16}\.pack\.json$/', $name);

        $doc = json_decode((string)$files[$name], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('evidence_pack', $doc['schema']);
        $this->assertSame('1', $doc['pack_version']);
        $this->assertSame(2, $doc['rank']);
        $this->assertSame('PAIR', $doc['cluster_id']);
        $this->assertSame(FindingId::for($pair, [$this->tmp]), $doc['finding_id']);
        $this->assertSame(['BLK0', 'BLK1'], array_column($doc['members'], 'id'));
        $this->assertStringEndsWith("\n", (string)$files[$name], 'every pack is newline-terminated JSON');
    }

    public function testTopKCapBoundsThePackSet(): void
    {
        $clusters = [];
        for ($i = 0; $i < EvidencePackBuilder::TOP_K + 5; $i++) {
            $clusters[] = $this->bareCluster('C' . $i, [
                $this->bareBlock('a.php', 1, 2),
                $this->bareBlock('b.php', 1, 2),
            ]);
        }
        $files = (new EvidencePackBuilder($this->quietStore()))->build($this->report($clusters));
        $this->assertCount(EvidencePackBuilder::TOP_K, $files);
    }

    public function testMemberExcerptsCarryRealCappedSource(): void
    {
        $cluster = $this->pairCluster();
        $doc     = $this->firstDoc([$cluster]);

        $this->assertStringContainsString('if ($s > 1)', (string)$doc['members'][0]['source']);
        $this->assertFalse($doc['members'][0]['truncated']);
        $this->assertStringContainsString('@@', (string)$doc['member_diffs'][0]['diff']);
        $this->assertStringStartsWith(
            '--- a/PAIR.template' . "\n" . '+++ b' . $cluster->members[0]->file . "\n",
            (string)$doc['member_diffs'][0]['diff'],
        );
        $this->assertTrue($doc['template']['available']);
        $this->assertStringContainsString('printer-normalized', (string)$doc['template']['fidelity']);
    }

    public function testLongExcerptIsTruncatedHonestly(): void
    {
        $file = $this->tmp . '/huge.php';
        $body = "<?php\nfunction huge() {\n";
        for ($i = 0; $i < 300; $i++) {
            $body .= "    echo {$i};\n";
        }
        $body .= "}\n";
        file_put_contents($file, $body);

        $block     = $this->extract($file)[0];
        $block->id = 'HUGE';
        $doc       = $this->firstDoc([$this->bareCluster('HG', [$block, $block])]);

        $this->assertTrue($doc['members'][0]['truncated']);
        $this->assertStringContainsString('truncated: showing', (string)$doc['members'][0]['source']);
        $this->assertLessThanOrEqual(8400, strlen((string)$doc['members'][0]['source']));
    }

    // ── absence postures ─────────────────────────────────────────────────────

    public function testUnfetchableMemberStaysNullWithNamedReason(): void
    {
        $cluster = $this->pairCluster();
        unlink($this->tmp . '/m1.php'); // vanishes between scan and pack

        $doc = $this->firstDoc([$cluster]);
        $this->assertNull($doc['members'][1]['source']);
        $this->assertStringContainsString(SourceTextStore::UNFETCHED, (string)$doc['members'][1]['source_note']);
        $this->assertNull($doc['member_diffs'][1]['diff']);
        $this->assertStringContainsString(SourceTextStore::UNFETCHED, (string)$doc['member_diffs'][1]['note']);
        $this->assertIsString($doc['members'][0]['source'], 'the fetchable side still rides its real bytes');
    }

    public function testStrippedClusterDegradesTemplateAndDiffs(): void
    {
        $cluster = $this->bareCluster('NOST', [
            $this->bareBlock('x.php', 1, 2),
            $this->bareBlock('y.php', 1, 2),
        ]);
        $doc = $this->firstDoc([$cluster]);

        $this->assertFalse($doc['template']['available']);
        $this->assertStringContainsString('lazy-AST', (string)$doc['template']['reason']);
        $this->assertSame([], $doc['member_diffs'], 'no template means no diff — never a placeholder-byte diff');
    }

    // ── the 4.1 / 4.2 / 3.2 / 5.1 joins ──────────────────────────────────────

    public function testDriftFlagsFilterTheDriftFamilyOnly(): void
    {
        $cluster                          = $this->pairCluster();
        $cluster->architecturalFindings   = [
            new Finding('DriftAnalyzer', 'drift-missing-guard', 'guard missing', 'warning', 'add one'),
            new Finding('SolidAnalyzer', 'SRP-1', 'not a drift code', 'note', null),
            new Finding('DriftAnalyzer', 'drift-odd-one-out', 'outlier', 'note', null),
        ];
        $doc = $this->firstDoc([$cluster]);

        $this->assertSame(
            ['drift-missing-guard', 'drift-odd-one-out'],
            array_column($doc['drift_flags'], 'code'),
        );
        $this->assertSame('add one', $doc['drift_flags'][0]['suggestion']);
    }

    public function testHistoryNullWhenUnarmedAndJoinedWhenArmed(): void
    {
        $cluster = $this->pairCluster();

        $this->assertNull($this->firstDoc([$cluster])['history'], 'unarmed window is the honest absence');

        $armed = new ClusterHistory(
            [[
                'cluster'   => 'PAIR',
                'verdict'   => 'drifted',
                'reason'    => 'one twin fixed in March',
                'members'   => [['file' => 'm0.php', 'last_sha' => 'abc', 'last_date' => '2026-03-01', 'lone' => null]],
                'co_change' => [],
            ]],
            12,
            0,
            [],
        );
        $doc = $this->firstDoc([$cluster], history: $armed);
        $this->assertTrue($doc['history']['covered']);
        $this->assertSame('drifted', $doc['history']['verdict']);
        $this->assertSame(12, $doc['history']['window_months']);

        $other  = $this->pairCluster();
        $other->id = 'OTHER';
        $miss = $this->firstDoc([$other], history: $armed);
        $this->assertFalse($miss['history']['covered']);
        $this->assertStringContainsString('no history row', (string)$miss['history']['note']);
    }

    public function testAdoptionJoinMirrorsThePlacementAdvisorLaw(): void
    {
        $cluster = $this->pairCluster(); // BLK0 tmp/m0.php 2..2, BLK1 tmp/m1.php 2..2

        $defMember = $this->helperCopy('Env::isDev', 'm0.php', 2, 2, ['other.php' => 2], ['other.php:4', 'other.php:9']);
        $lineExact = $this->helperCopy(
            'Str::slug',
            'z.php',
            1,
            9,
            [$cluster->members[1]->file => 1, 'w.php' => 1],
            [$cluster->members[1]->file . ':2', 'w.php:3'],
        );
        $fileLevel = $this->helperCopy('Arr::flat', 'z.php', 1, 9, [$cluster->members[1]->file => 3], ['w.php:3']); // capped: 1 sample < 3 sites
        $noTouch   = $this->helperCopy('Never::Seen', 'z.php', 1, 9, ['elsewhere.php' => 1], ['elsewhere.php:2']);

        $this->assertNull($this->firstDoc([$cluster])['adoption'], 'unarmed probe stays absent');

        $doc      = $this->firstDoc([$cluster], adoption: [$defMember, $lineExact, $fileLevel, $noTouch]);
        $labels   = array_column($doc['adoption']['helpers'], 'helper_label');
        $byLabel  = [];
        foreach ($doc['adoption']['helpers'] as $row) {
            $byLabel[$row['helper_label']] = $row;
        }

        $this->assertSame(['Env::isDev', 'Str::slug', 'Arr::flat'], $labels, 'untouched helpers never ride the pack');
        $this->assertSame('def', $byLabel['Env::isDev']['attach'][0]['mode']);
        $this->assertSame('line_exact', $byLabel['Str::slug']['attach'][0]['mode']);
        $this->assertSame('BLK1', $byLabel['Str::slug']['attach'][0]['member']);
        $this->assertSame('file_level', $byLabel['Arr::flat']['join'], 'capped samples cap the attribution too');
        $this->assertSame('file_level', $byLabel['Arr::flat']['attach'][0]['mode']);
    }

    public function testScopeSectionRidesThe51Fields(): void
    {
        $cluster = $this->pairCluster();
        $doc     = $this->firstDoc([$cluster]);

        $this->assertSame('single', $doc['scope']['level'], 'both members share one repo label');
        $this->assertSame(['alpha'], $doc['scope']['repos']);

        $cluster2                     = $this->pairCluster();
        $cluster2->members[1]->repo   = 'beta';
        $doc2                         = $this->firstDoc([$cluster2]);
        $this->assertSame('multi', $doc2['scope']['level']);
        $this->assertSame(['alpha', 'beta'], $doc2['scope']['repos']);
    }

    // ── boundary law + file landing ──────────────────────────────────────────

    public function testControlBytePathRefusesBeforeAnythingLands(): void
    {
        $cluster                     = $this->pairCluster();
        $cluster->members[1]->file  .= "\n--- /dev/null";

        $dir = $this->tmp . '/packs';
        try {
            (new EvidencePackBuilder($this->quietStore()))->writeTo($this->report([$cluster]), $dir);
            $this->fail('a newline-bearing member path must refuse the pack set');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('EvidencePackBuilder', $e->getMessage());
            $this->assertStringContainsString('newline (0x0A)', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dir, 'refusal precedes directory creation — no partial artifact set');
    }

    public function testWriteToLandsDecodablePacksAndIsDeterministic(): void
    {
        $report = $this->report([$this->pairCluster()]);
        $dir    = $this->tmp . '/packs';

        $builder = new EvidencePackBuilder($this->quietStore());
        $builder->writeTo($report, $dir);
        $builder->writeTo($report, $dir); // second pass over the same dir is idempotent

        $landed = glob($dir . '/*.pack.json') ?: [];
        $this->assertCount(1, $landed);
        foreach ($landed as $path) {
            json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        }

        $this->assertSame($builder->build($report), (new EvidencePackBuilder($this->quietStore()))->build($report), 'same report bytes in, same pack bytes out');
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function quietStore(): SourceTextStore
    {
        return new SourceTextStore([$this->tmp], warnOnSkip: false);
    }

    /** @param list<Cluster> $clusters */
    private function report(array $clusters, ?ClusterHistory $history = null): Report
    {
        $config = new Config(paths: [$this->tmp], exclude: []);
        return new Report(
            files: 2, blocks: 2, parseErrors: 0, clusters: $clusters, config: $config,
            clusterHistory: $history,
        );
    }

    /**
     * @param list<Cluster> $clusters
     * @param list<HelperCopy>|null $adoption
     * @return array<string,mixed>
     */
    private function firstDoc(array $clusters, ?ClusterHistory $history = null, ?array $adoption = null): array
    {
        $files = (new EvidencePackBuilder($this->quietStore(), $adoption))->build($this->report($clusters, $history));
        $this->assertNotEmpty($files);
        $doc = json_decode((string)reset($files), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($doc);
        return $doc;
    }

    /**
     * @param array<string,int> $inlineFiles
     * @param list<string>      $copyLocations
     */
    private function helperCopy(string $label, string $defFile, int $start, int $end, array $inlineFiles, array $copyLocations): HelperCopy
    {
        $inline = array_sum($inlineFiles);
        return new HelperCopy(
            helperLabel: $label,
            defFile: $this->tmp . '/' . $defFile,
            defStart: $start,
            defEnd: $end,
            helperKind: 'method',
            defStatements: 3,
            patternExact: 'e',
            patternAbstract: 'a',
            nodes: 4,
            rootType: 'Equal',
            sitesTotal: $inline,
            defSites: 0,
            inlineSites: $inline,
            inlineFiles: $inlineFiles,
            copyLocations: $copyLocations,
        );
    }

    /** @param list<Block> $members */
    private function bareCluster(string $id, array $members): Cluster
    {
        return new Cluster($id, $members, 0.8, false);
    }

    private function bareBlock(string $file, int $start, int $end): Block
    {
        $block       = new Block($this->tmp . '/' . $file, new LineRange($start, $end), 'function', null, null, 'f', new Nop());
        $block->id   = 'B-' . $file . '-' . $start;
        $block->size = $end - $start + 1;
        return $block;
    }

    /** @return list<Block> */
    private function extract(string $file): array
    {
        $stmts     = (new AstParser())->parseCode((string)file_get_contents($file));
        $extracted = (new BlockExtractor(minSize: 1))->extract($file, $stmts);
        $this->assertNotEmpty($extracted, "no block extracted from {$file}");
        return $extracted;
    }

    private function pairCluster(
        string $a = 'if ($s > 1) { send($u); }',
        string $b = 'if ($s > 2) { send($v); }',
    ): Cluster {
        $blocks = [];
        foreach ([$a, $b] as $i => $code) {
            $file      = $this->tmp . "/m{$i}.php";
            file_put_contents($file, "<?php\n" . $code . "\n");
            $block     = $this->extract($file)[0];
            $block->id = "BLK{$i}";
            $block->repo = 'alpha';
            $blocks[]  = $block;
        }
        $cluster        = new Cluster('PAIR', $blocks, 0.9, false);
        $cluster->pairs = [[
            'blockA' => 'BLK0', 'blockB' => 'BLK1', 'matchTier' => 'jaccard', 'matchScore' => 0.9,
        ]];
        (new AntiUnifier())->unify($cluster);
        return $cluster;
    }
}
