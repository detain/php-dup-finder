<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parsing;

use PhpParser\Lexer;
use PhpParser\Token;
use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\TokenCache;

final class TokenCacheTest extends TestCase
{
    private string $tmp;
    private string $sourceFile;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-tok-' . uniqid();
        mkdir($this->tmp, 0o775, true);
        $this->sourceFile = $this->tmp . '/Hello.php';
        file_put_contents($this->sourceFile, "<?php echo 'hello';\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->sourceFile);
        foreach (glob($this->tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmp);
    }

    public function testRoundTripsTokenStream(): void
    {
        // Construct a few synthetic tokens — the cache shouldn't care
        // what's inside the array, only that it's serializable.
        $tokens = [new Token(\T_OPEN_TAG, '<?php ', 1, 0)];
        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, $tokens);
        $loaded = $cache->get($this->sourceFile);
        $this->assertNotNull($loaded);
        $this->assertCount(1, $loaded);
    }

    public function testReturnsNullOnContentChange(): void
    {
        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
        // Mutate the source file; cache must miss.
        file_put_contents($this->sourceFile, "<?php echo 'changed';\n");
        $this->assertNull($cache->get($this->sourceFile));
    }

    public function testDisabledCacheReturnsNull(): void
    {
        $cache = new TokenCache('');
        $this->assertNull($cache->get($this->sourceFile));
        $this->assertFalse($cache->isEnabled());
    }
}
