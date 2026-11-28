<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use Phpdup\Ml\LlmPackCache;
use PHPUnit\Framework\TestCase;

/**
 * Item 8.2 — the judgment cache is the letter's "cache by evidence-pack
 * hash so reruns only pay for changed findings" clause. Keys bind
 * host + model + prompt version + pack content; entries hold verdicts
 * only (never pack/source bytes, never a token).
 */
final class LlmPackCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) sys_get_temp_dir() . '/phpdup-llmcache-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testPackKeyIsContentAddressedAndStable(): void
    {
        $cache = new LlmPackCache($this->dir, 'llm.example', 'm1');
        $pack  = ['finding_id' => 'Fabc', 'rank' => 1, 'members' => [['class' => 'A']]];

        $this->assertSame($cache->packKey($pack), $cache->packKey($pack));
        $this->assertMatchesRegularExpression('/^F-[0-9a-f]{64}$/', $cache->packKey($pack));

        // One changed field = a new verdict slot: the rerun pays only for
        // this finding (the letter's economy clause).
        $changed = $pack;
        $changed['rank'] = 2;
        $this->assertNotSame($cache->packKey($pack), $cache->packKey($changed));
    }

    public function testKeysBindHostModelAndPromptVersion(): void
    {
        $pack = ['finding_id' => 'Fabc'];
        $base = new LlmPackCache($this->dir, 'llm.example', 'm1');
        $otherHost = new LlmPackCache($this->dir, 'other.example', 'm1');
        $otherModel = new LlmPackCache($this->dir, 'llm.example', 'm2');

        $this->assertNotSame($base->packKey($pack), $otherHost->packKey($pack), 'a verdict earned from one endpoint is never fed to another from cache');
        $this->assertNotSame($base->packKey($pack), $otherModel->packKey($pack), 'model drift invalidates judgments');
    }

    public function testThemesKeyOrdersIndependentlyOverTheAnnotationSet(): void
    {
        $cache = new LlmPackCache($this->dir, 'llm.example', 'm1');
        $k1 = $cache->packKey(['finding_id' => 'A']);
        $k2 = $cache->packKey(['finding_id' => 'B']);

        $this->assertSame($cache->themesKey([$k1, $k2]), $cache->themesKey([$k2, $k1]), 'rank churn alone must not re-bill the theme pass');
        $this->assertNotSame($cache->themesKey([$k1, $k2]), $cache->themesKey([$k1]), 'a changed finding set invalidates the theme verdict too');
        $this->assertMatchesRegularExpression('/^T-[0-9a-f]{64}$/', $cache->themesKey([$k1]));
    }

    public function testPutGetRoundtripWithJudgmentOnlyEntries(): void
    {
        $cache = new LlmPackCache($this->dir, 'llm.example', 'm1');
        $row = ['finding_id' => 'Fabc', 'title' => 'retry loop', 'proposal' => 'extract', 'risk' => 'low', 'effort' => 'S', 'intentional' => false];

        $this->assertNull($cache->get($cache->packKey(['finding_id' => 'Fabc'])), 'cold dir is a miss');
        $this->assertTrue($cache->put($k = $cache->packKey(['x' => 1]), $row));
        $this->assertSame($row, $cache->get($k));

        $files = glob($this->dir . '/*.json') ?: [];
        $this->assertCount(1, $files);
        $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($files[0])), -4), 'judgment bytes stay owner-only');
    }

    public function testCorruptOrForeignEntriesDegradeToMiss(): void
    {
        $cache = new LlmPackCache($this->dir, 'llm.example', 'm1');
        $key = $cache->packKey(['x' => 1]);
        mkdir($this->dir, 0o700, true);

        file_put_contents($this->dir . '/' . $key . '.json', 'not json at all');
        $this->assertNull($cache->get($key), 'torn bytes are a miss, never a crash');

        file_put_contents($this->dir . '/' . $key . '.json', '[1,2,3]');
        $this->assertNull($cache->get($key), 'a JSON list is not a verdict entry shape');
    }

    public function testForeignShapedKeyIsNeverRead(): void
    {
        $cache = new LlmPackCache($this->dir, 'llm.example', 'm1');
        mkdir($this->dir, 0o700, true);
        $escape = dirname($this->dir) . '/phpdup-llmcache-evil-' . basename($this->dir) . '.json';
        file_put_contents($escape, '{}');

        $this->assertNull($cache->get('..' . DIRECTORY_SEPARATOR . basename($escape)), 'the hex gate refuses traversal-flavoured keys on read');
        $this->assertNull($cache->get('F-deadbeef'), 'short keys miss by definition');
        $this->assertNull($cache->get(basename($escape, '.json')), 'even a sibling file name is not a valid key');
        @unlink($escape);
    }
}
