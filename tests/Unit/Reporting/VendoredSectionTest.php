<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\VendoredSection;
use Phpdup\Scanning\VendorMarker;
use Phpdup\Util\LineRange;

/**
 * Item 5.2 (W2b) — the split semantics of the vendored-duplicates
 * section: only all-vendored clusters demote, mixed clusters stay,
 * totals cover everything while rows are presentation-capped, and the
 * rank order of both sides is preserved verbatim.
 */
final class VendoredSectionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup_vsection_' . uniqid('t', true);
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    @unlink($this->tmp . '/' . $e);
                }
            }
            @rmdir($this->tmp);
        }
    }

    public function testFullyVendoredClusterDemotesWithFullRow(): void
    {
        $a = $this->block('/root/vendor/acme/pkg/Alpha.php', 'method', new LineRange(1, 12));
        $b = $this->block('/root/vendor/acme/pkg/Beta.php', 'method', new LineRange(20, 25));
        $cluster = new Cluster('c-vend', [$a, $b], 0.95, false);
        $marker  = new VendorMarker([]);
        $marker->stampAll([$a, $b]);
        self::assertTrue($a->vendored);

        $split = VendoredSection::split([$cluster], $marker);

        self::assertSame([], $split['main'], 'a vendored↔vendored cluster leaves the reported slice');
        $section = $split['section'];
        self::assertSame(1, $section['clusters']);
        self::assertSame($cluster->totalLines(), $section['lines']);
        self::assertSame(0, $section['omitted']);
        self::assertCount(1, $section['rows']);

        $row = $section['rows'][0];
        self::assertSame('c-vend', $row['id']);
        self::assertSame('method', $row['kind'], 'kind is the medoid member\'s kind (first member)');
        self::assertSame(2, $row['members']);
        self::assertSame(2, $row['files']);
        self::assertSame($cluster->totalLines(), $row['lines']);
        self::assertSame(['vendor_dir'], $row['signals']);
        self::assertSame(['/root/vendor/acme/pkg/Alpha.php:1-12', '/root/vendor/acme/pkg/Beta.php:20-25'], $row['locations']);
    }

    public function testMixedClusterIsNotVendoredDuplicates(): void
    {
        $firstParty = $this->block('/root/src/Service.php', 'method', new LineRange(1, 12));
        $copy       = $this->block('/root/vendor/acme/pkg/Copy.php', 'method', new LineRange(1, 12));
        $cluster    = new Cluster('c-mixed', [$firstParty, $copy], 0.9, false);
        $marker     = new VendorMarker([]);
        $marker->stampAll([$firstParty, $copy]);

        $split = VendoredSection::split([$cluster], $marker);

        // The ruling says vendored<->vendored demotes. A fork-edited
        // twin alongside first-party code is exactly what --scope users
        // still want to see: it stays, and no section row is minted.
        self::assertSame([$cluster], $split['main']);
        self::assertSame(0, $split['section']['clusters']);
        self::assertSame([], $split['section']['rows']);
    }

    public function testEmptyMemberClusterNeverDemotes(): void
    {
        $cluster = new Cluster('c-empty', [], 0.5, false);
        $split   = VendoredSection::split([$cluster], new VendorMarker([]));

        self::assertSame([$cluster], $split['main'], 'vacuous "every member vendored" is refused');
        self::assertSame(0, $split['section']['clusters']);
    }

    public function testRankOrderPreservedOnBothSides(): void
    {
        $marker = new VendorMarker([]);
        $mk     = static function (string $id, string $file) use ($marker): Cluster {
            $block = new Block($file, new LineRange(1, 5), 'function', null, null, $id, new Node\Stmt\Nop());
            $marker->stamp($block);
            return new Cluster($id, [$block], 0.8, false);
        };
        $clusters = [
            $mk('first', '/r/src/A.php'),
            $mk('gone', '/r/vendor/x/B.php'),
            $mk('second', '/r/src/C.php'),
            $mk('gone2', '/r/vendor/y/D.php'),
        ];

        $split = VendoredSection::split($clusters, $marker);

        self::assertSame(['first', 'second'], array_map(static fn(Cluster $c): string => $c->id, $split['main']));
        self::assertSame(['gone', 'gone2'], array_map(static fn(array $r): string => $r['id'], $split['section']['rows']));
    }

    public function testPresentationCapCoversTotalsAndCountsOmitted(): void
    {
        $marker   = new VendorMarker([]);
        $clusters = [];
        $expectedLines = 0;
        for ($i = 0; $i <= VendoredSection::MAX_ROWS; $i++) { // MAX_ROWS + 1 copies
            $block   = new Block("/r/vendor/p{$i}/F.php", new LineRange(1, 7), 'function', null, null, "f{$i}", new Node\Stmt\Nop());
            $marker->stamp($block);
            $cluster = new Cluster("c{$i}", [$block], 0.7, false);
            $expectedLines += $cluster->totalLines();
            $clusters[]     = $cluster;
        }

        $split   = VendoredSection::split($clusters, $marker);
        $section = $split['section'];

        self::assertSame(VendoredSection::MAX_ROWS + 1, $section['clusters'], 'counting bound covers every demoted cluster');
        self::assertSame($expectedLines, $section['lines']);
        self::assertCount(VendoredSection::MAX_ROWS, $section['rows'], 'presentation cap truncates rows');
        self::assertSame(1, $section['omitted'], 'truncation is named, never silent');
        self::assertSame('c0', $section['rows'][0]['id']);
        self::assertSame('c' . (VendoredSection::MAX_ROWS - 1), $section['rows'][VendoredSection::MAX_ROWS - 1]['id']);
    }

    public function testLocationsAreCapped(): void
    {
        $marker = new VendorMarker([]);
        $blocks = [];
        for ($i = 0; $i < VendoredSection::MAX_LOCATIONS + 2; $i++) {
            $block = new Block("/r/vendor/pkg/M{$i}.php", new LineRange(1, 4), 'function', null, null, "m{$i}", new Node\Stmt\Nop());
            $marker->stamp($block);
            $blocks[] = $block;
        }
        $split = VendoredSection::split([new Cluster('c-wide', $blocks, 0.9, false)], $marker);

        $row = $split['section']['rows'][0];
        self::assertCount(VendoredSection::MAX_LOCATIONS, $row['locations']);
        self::assertSame(VendoredSection::MAX_LOCATIONS + 2, $row['members'], 'the cap presents, it never miscounts');
    }

    public function testSignalsCarryPackageDetailAndSortDistinct(): void
    {
        $root = $this->tmp . '/appRoot';
        mkdir($root . '/include/nusoap', 0o775, true);
        file_put_contents($root . '/composer.lock', (string) json_encode([
            'packages'     => [['name' => 'eusebiob/nusoap', 'version' => '1.0.0']],
            'packages-dev' => [],
        ]));

        $lockCopy = new Block($root . '/include/nusoap/lib.php', new LineRange(1, 6), 'function', null, null, 'a', new Node\Stmt\Nop());
        $vendor   = new Block($root . '/vendor/other/pkg/b.php', new LineRange(1, 6), 'function', null, null, 'b', new Node\Stmt\Nop());
        $marker   = new VendorMarker([$root]);
        $marker->stampAll([$lockCopy, $vendor]);

        $split = VendoredSection::split([new Cluster('c-sig', [$lockCopy, $vendor], 0.9, false)], $marker);
        self::assertSame(
            ['composer_lock_path:eusebiob/nusoap', 'vendor_dir'],
            $split['section']['rows'][0]['signals'],
            'distinct labels, sorted — a reader sees which package and which leg fired',
        );
    }

    public function testCleanCorpusReturnsZeroSectionAndUntouchedMain(): void
    {
        $a = $this->block('/root/src/A.php', 'method', new LineRange(1, 12));
        $b = $this->block('/root/src/B.php', 'method', new LineRange(1, 12));
        $cluster = new Cluster('c-clean', [$a, $b], 0.99, false);
        $marker  = new VendorMarker([]);
        $marker->stampAll([$a, $b]);

        $split = VendoredSection::split([$cluster], $marker);

        self::assertSame([$cluster], $split['main']);
        self::assertSame(
            ['clusters' => 0, 'lines' => 0, 'omitted' => 0, 'rows' => []],
            $split['section'],
            'an armed split over a vendored-free corpus is honestly zero-shaped (never omitted keys)',
        );
    }

    private function block(string $file, string $kind, LineRange $range): Block
    {
        return new Block($file, $range, $kind, null, null, 'm', new Node\Stmt\Nop());
    }
}
