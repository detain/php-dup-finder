<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeCensus;
use Phpdup\Cli\Config;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\PreprocessWorker;

/**
 * Item 3.1 substrate, worker half: the idiom flavor emits from the
 * SAME statement lists the stmtseq leg mines (§5c one-walker law), it
 * rides the wire as a scalar JSON payload (SOCKETPAIR law), and —
 * default OFF — the whole row stream is byte-identical to the historic
 * one. The later census lane flips the flag; every current caller must
 * see nothing.
 */
final class PreprocessWorkerIdiomTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup_widiom_' . uniqid('w', true);
        mkdir($this->tmpDir . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmpDir . '/cache/*/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir . '/cache');
        @rmdir($this->tmpDir);
    }

    private function config(): Config
    {
        return new Config(
            paths: [$this->tmpDir],
            exclude: [],
            minBlockSize: 1,
            allowedKinds: [BlockExtractor::FRAGMENT_KIND],
            cacheDir: $this->tmpDir . '/cache',
        );
    }

    /** @return list<array{type:string,file:string,flavor?:string,payload?:string}> */
    private function minedRows(array $rows, string $flavor): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (($r['type'] ?? '') === 'mined' && ($r['flavor'] ?? '') === $flavor) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public function testDefaultOffKeepsEveryStreamRowIdentical(): void
    {
        file_put_contents($this->tmpDir . '/a.php', <<<'PHP'
            <?php
            function a($x) { if ($x instanceof Foo || $y instanceof Foo) { return strlen(trim($x)); } }
            class K { public function m($v) { return bin2hex(random_bytes(16)); } }
            PHP);
        $files = [$this->tmpDir . '/a.php'];

        $off = (new PreprocessWorker($this->config(), astDiskCache: false))->process($files);
        $on  = (new PreprocessWorker($this->config(), astDiskCache: false, idiomMining: true))->process($files);

        self::assertSame([], $this->minedRows($off, 'idiom'), 'unarmed worker never enumerates');
        self::assertNotEmpty($this->minedRows($off, 'stmtseq'), 'baseline flavor still flows (belt)');

        $project = function (array $rows): string {
            $out = [];
            foreach ($rows as $r) {
                if (($r['type'] ?? '') === 'mined' && ($r['flavor'] ?? '') === 'idiom') {
                    continue;
                }
                $copy = $r;
                if (($copy['type'] ?? '') === 'block') {
                    $copy['block'] = 'BLOCK-PROXY'; // Blocks carry state; the row ORDER and scalars are the contract
                }
                $out[] = $copy;
            }
            return json_encode($out, JSON_THROW_ON_ERROR);
        };
        self::assertSame($project($off), $project($on), 'idiom rows are strictly ADDITIVE');
    }

    public function testArmedWorkerEmitsScalarIdiomRowsThatFeedTheCensus(): void
    {
        $code = <<<'PHP'
            <?php
            function a($x) { if ($x instanceof Foo || $y instanceof Foo) { return 1 + (1 + (-1)); } }
            function b($p) { if ($p instanceof Foo || $q instanceof Foo) { return 2 + (2 + (-2)); } }
            PHP;
        file_put_contents($this->tmpDir . '/a.php', $code);
        file_put_contents($this->tmpDir . '/b.php', $code);

        $rows = (new PreprocessWorker($this->config(), astDiskCache: false, idiomMining: true))
            ->process([$this->tmpDir . '/a.php', $this->tmpDir . '/b.php']);

        $idiom = $this->minedRows($rows, 'idiom');
        self::assertCount(2, $idiom);
        foreach ($idiom as $row) {
            self::assertIsString($row['payload'], 'SOCKETPAIR law: the wire carries strings, never objects');
            $decoded = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('sites', $decoded);
            self::assertArrayHasKey('edges', $decoded);
        }

        // The pairs are the census's input contract end-to-end.
        $census = new SubtreeCensus();
        foreach ($idiom as $row) {
            $census->recordFileJson($row['file'], $row['payload']);
        }
        $abstracts = [];
        foreach ($census->rows() as $r) {
            $abstracts[$r->abstractHash] = $r->sites;
        }
        self::assertNotEmpty($abstracts);
        // 'x instanceof Foo || y instanceof Foo' recurs across the two
        // files => the multi-site tier materialises locations.
        $recalls = array_values(array_filter($census->rows(), static fn($r) => $r->sites >= 2));
        self::assertNotEmpty($recalls, 'cross-file repeats counted >=2 sites');
        self::assertNotEmpty($recalls[0]->locations, '>=2 sites => capped samples exist');
    }

    public function testIdiomEmissionFollowsFileWithoutIdiomsToNil(): void
    {
        file_put_contents($this->tmpDir . '/tiny.php', "<?php\n\$i = 1 + 2;\n");
        $rows = (new PreprocessWorker($this->config(), astDiskCache: false, idiomMining: true))
            ->process([$this->tmpDir . '/tiny.php']);
        self::assertSame([], $this->minedRows($rows, 'idiom'), 'a tree below the window adds no row at all');
    }
}
