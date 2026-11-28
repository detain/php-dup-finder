<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Git;

use Phpdup\Git\BlameIndex;
use Phpdup\Git\HistoryCache;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.2 — the sidecar cache law: a hit must round-trip the parsed
 * answer, and EVERY failure mode (missing file, foreign JSON, version
 * drift, head drift, unwritable root) must degrade to a silent miss.
 * The cache is an optimisation; git remains the only source of truth.
 */
final class HistoryCacheRoundtripTest extends TestCase
{
    private string $dir;
    private string $cacheBase;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpdup_hc_' . uniqid('t', true);
        $this->cacheBase = $this->dir . '/cache';
        mkdir($this->cacheBase, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if (is_link($entry) || is_file($entry)) {
                @unlink($entry);
            } elseif (is_dir($entry)) {
                @rmdir($entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testBlameRoundTrip(): void
    {
        $cache = new HistoryCache($this->cacheBase);
        $head  = str_repeat('a1', 20);
        $sha   = str_repeat('be', 20);
        $key   = $cache->blameKey($head, '/proj/src/A.php');

        $cache->storeBlame($key, $head, 'src/A.php', [1 => $sha, 2 => null], [$sha => ['author' => 'A', 'time' => 5, 'msg' => 'm']], ['src/Old.php']);

        $hit = $cache->loadBlame($key, $head, 'src/A.php');
        self::assertNotNull($hit);
        self::assertSame([1 => $sha, 2 => null], $hit->lineSha);
        self::assertSame(5, $hit->commit($sha)['time'] ?? null);
        self::assertSame(['src/Old.php'], $hit->previousPaths);
    }

    public function testHeadDriftFileDriftAndVersionRotAllMiss(): void
    {
        $cache = new HistoryCache($this->cacheBase);
        $head  = str_repeat('c3', 20);
        $key   = $cache->blameKey($head, '/p/B.php');
        $cache->storeBlame($key, $head, 'B.php', [1 => $head], [$head => ['author' => 'B', 'time' => 1, 'msg' => 'm']], []);

        self::assertNotNull($cache->loadBlame($key, $head, 'B.php'));
        self::assertNull($cache->loadBlame($key, str_repeat('d4', 20), 'B.php'), 'a moved HEAD invalidates the entry');
        self::assertNull($cache->loadBlame($key, $head, 'renamed/B.php'), 'a renamed path invalidates the entry');

        // Rot the stored shape: a foreign v2 payload must miss, not crash.
        $path = $this->cacheBase . '/history/blame/' . $key . '.json';
        $raw  = (string) file_get_contents($path);
        file_put_contents($path, str_replace('"v":1', '"v":2', $raw));
        self::assertNull($cache->loadBlame($key, $head, 'B.php'), 'version rot degrades to a miss');

        file_put_contents($path, '{ not json');
        self::assertNull($cache->loadBlame($key, $head, 'B.php'), 'a corrupt file degrades to a miss');
    }

    public function testLogRoundTripAndMisses(): void
    {
        $cache = new HistoryCache($this->cacheBase);
        $head  = str_repeat('e5', 20);
        $key   = $cache->logKey($head, 1_700_000_000);
        $records = [
            ['sha' => str_repeat('f6', 20), 'time' => 1_700_000_500, 'subject' => 'Fix x', 'files' => ['a.php', 'b.php']],
        ];
        $cache->storeLog($key, $head, 1_700_000_000, $records);

        self::assertSame($records, $cache->loadLog($key, $head, 1_700_000_000));
        self::assertNull($cache->loadLog($key, $head, 1_700_000_001), 'a different window day misses by design');
    }

    public function testEmptyCacheDirDisablesEverythingSilently(): void
    {
        $cache = new HistoryCache('');
        self::assertTrue($cache->disabled());
        $cache->storeBlame('k', str_repeat('0a', 20), 'x.php', [], [], []);
        self::assertNull($cache->loadBlame('k', str_repeat('0a', 20), 'x.php'));
    }

    public function testBlameHitRebuildsUsableIndex(): void
    {
        $cache = new HistoryCache($this->cacheBase);
        $head  = str_repeat('a1', 20);
        $sha   = str_repeat('9f', 20);
        $key   = $cache->blameKey($head, '/p/C.php');
        $cache->storeBlame($key, $head, 'C.php', [3 => $sha], [$sha => ['author' => 'C', 'time' => 9, 'msg' => 'msg']], []);

        $hit = $cache->loadBlame($key, $head, 'C.php');
        self::assertInstanceOf(BlameIndex::class, $hit);
        self::assertSame(['sha' => $sha, 'time' => 9, 'msg' => 'msg'], $hit->lastTouch(1, 10));
    }
}
