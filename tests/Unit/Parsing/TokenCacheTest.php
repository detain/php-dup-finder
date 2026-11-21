<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parsing;

use PhpParser\Token;
use PHPUnit\Framework\TestCase;
use Phpdup\Persistence\SerializedClassAllowList;
use Phpdup\Parsing\CacheBlob;
use Phpdup\Parsing\TokenCache;

/**
 * Pins the engine-018/020 hardening of the token disk cache: parity
 * with {@see AstCache} through the shared {@see CacheBlob} framing and
 * decode screening, and private dir/file modes.
 */
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
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmp . '/perm-cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp . '/perm-cache');
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
        $this->assertInstanceOf(Token::class, $loaded[0]);
        $this->assertSame('<?php ', $loaded[0]->text, 'scalars survive the framed round-trip');
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

    public function testTruncatedBlobIsMiss(): void
    {
        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
        $file = $this->onlyBlob();

        file_put_contents($file, substr((string)file_get_contents($file), 0, -8));
        $this->assertNull($cache->get($this->sourceFile), 'truncated frame must fail closed');
    }

    public function testAppendedGarbageIsMiss(): void
    {
        // unserialize() silently ignores trailing bytes — the length
        // header is what turns an appended blob into a miss (engine-017b
        // mirrored onto the token cache, engine-018).
        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
        $file = $this->onlyBlob();

        file_put_contents($file, 'GARBAGE-GARBAGE', FILE_APPEND);
        $this->assertNull($cache->get($this->sourceFile));
    }

    public function testStaleUnframedBlobIsMiss(): void
    {
        // Migration posture: a pre-framing (v1-style) file holds a bare
        // serialize() payload. Even under a key that matches, the reader
        // must refuse to unserialize it — a miss, not a hit.
        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
        $file = $this->onlyBlob();

        file_put_contents($file, serialize([new Token(\T_STRING, 'poison', 9, 0)]));
        $this->assertNull($cache->get($this->sourceFile), 'unframed legacy blobs must never satisfy the reader');
    }

    public function testTopLevelDisallowedClassIsMiss(): void
    {
        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);

        file_put_contents($this->onlyBlob(), CacheBlob::frame(serialize(new \stdClass())));
        $this->assertNull($cache->get($this->sourceFile));
    }

    public function testIncompleteInTypedSlotIsMissNotFatal(): void
    {
        // engine-018 nested shape #1: a disallowed class planted in the
        // TYPED Token::$pos slot — unserialize() itself raises
        // "Cannot assign __PHP_Incomplete_Class to property PhpToken::$pos
        // of type int". The pre-fix get() let that fatal out of the run.
        $evil = self::spliceSegment(
            self::honestTokenPayload(),
            self::objectMarker(Token::class),
            'O:' . strlen(Token::class) . ':"' . Token::class . '":4:'
                . '{s:2:"id";i:262;s:4:"text";s:3:"foo";s:4:"line";i:1;s:3:"pos";O:8:"stdClass":0:{}}'
        );

        $threw = null;
        try {
            unserialize($evil, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        } catch (\Throwable $e) {
            $threw = $e;
        }
        $this->assertInstanceOf(\TypeError::class, $threw, 'crafted payload must hit the typed-slot decode path');

        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
        file_put_contents($this->onlyBlob(), CacheBlob::frame($evil));
        $this->assertNull($cache->get($this->sourceFile), 'must degrade to a miss, not propagate the TypeError');
    }

    public function testSmuggledDynamicPropertyIsMiss(): void
    {
        // engine-018 nested shape #2: every declared Token property is a
        // typed scalar, so a hostile blob smuggles its object through an
        // UNDECLARED (dynamic) property — unserialize succeeds and the
        // element still is a real Token. Only the scalar-only leaf screen
        // over restored properties catches it (the pre-fix get() had no
        // element check at all and returned the poisoned list as a hit).
        $evil = self::spliceSegment(
            self::honestTokenPayload(),
            self::objectMarker(Token::class),
            'O:' . strlen(Token::class) . ':"' . Token::class . '":5:'
                . '{s:2:"id";i:262;s:4:"text";s:3:"foo";s:4:"line";i:1;s:3:"pos";i:0;s:7:"payload";O:8:"stdClass":0:{}}'
        );

        // Counter-assert the discriminator: the payload decodes to a real
        // Token carrying an __PHP_Incomplete_Class in the smuggled slot —
        // a shape only the leaf screen can reject.
        $decoded = @unserialize($evil, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        $this->assertIsArray($decoded);
        $this->assertInstanceOf(Token::class, $decoded[0]);
        $this->assertFalse(CacheBlob::isScalarOnlyLeaf($decoded[0]), 'smuggled slot must fail the leaf screen');
        $this->assertTrue(
            CacheBlob::isScalarOnlyLeaf(new Token(\T_STRING, 'foo', 1, 0)),
            'honest tokens stay leaf-clean — the false above is caused by the smuggle, not the shape'
        );

        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
        file_put_contents($this->onlyBlob(), CacheBlob::frame($evil));
        $this->assertNull($cache->get($this->sourceFile), 'smuggled dynamic property must fail closed to a miss');
    }

    public function testNestedDisallowedElementIsMiss(): void
    {
        // engine-S23b r1 M-1: the third nested shape — a disallowed class
        // sitting directly in the LIST. It is neither a typed Token slot
        // (so unserialize() survives it) nor smuggled inside a Token (so
        // the leaf screen never reaches into it). stdClass is off the
        // allow-list, so the element restores as a property-less
        // __PHP_Incomplete_Class — and the (array) cast of an incomplete
        // with no properties measures clean to isScalarOnlyLeaf. Only the
        // element instanceof arm distinguishes it; deleting that arm (the
        // review's M6) leaked this exact payload back as a poisoned HIT.
        $payload = serialize([new Token(\T_STRING, 'ok', 1, 0), new \stdClass()]);

        // Counter-assert the discriminator (honest-vs-poison idiom of
        // testSmuggledDynamicPropertyIsMiss): the raw decode succeeds with
        // a real Token plus the incomplete, the leaf screen ACCEPTS the
        // incomplete — and an honest Token measures leaf-clean just the
        // same, so it is the element's class, not its shape, that rejects.
        $decoded = @unserialize($payload, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
        $this->assertIsArray($decoded);
        $this->assertInstanceOf(Token::class, $decoded[0]);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded[1]);
        $this->assertTrue(
            CacheBlob::isScalarOnlyLeaf($decoded[1]),
            'the leaf screen accepts the incomplete element — the instanceof arm is the only screen that rejects it'
        );
        $this->assertTrue(
            CacheBlob::isScalarOnlyLeaf(new Token(\T_STRING, 'ok', 1, 0)),
            'honest tokens stay leaf-clean — the rejection below is caused by the disallowed class, not the shape'
        );

        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);

        // Positive control: an honest [Token, Token] stream must still
        // HIT — the arm guards the gate, it does not void the cache.
        file_put_contents($this->onlyBlob(), CacheBlob::frame(serialize([
            new Token(\T_STRING, 'a', 1, 0),
            new Token(\T_STRING, 'b', 2, 5),
        ])));
        $hit = $cache->get($this->sourceFile);
        $this->assertNotNull($hit, 'two honest tokens must pass the element arm');
        $this->assertCount(2, $hit);

        // The poison: a disallowed class in a list slot must fail closed.
        file_put_contents($this->onlyBlob(), CacheBlob::frame($payload));
        $this->assertNull($cache->get($this->sourceFile), 'disallowed class in a list slot must be a miss, not a leak');
    }

    public function testNonListKeyedBlobIsMiss(): void
    {
        // engine-S23b r1 N-1: the element screens are class-based only —
        // a forged payload with string or sparse-integer keys whose
        // elements are all honest leaf-clean Tokens passed every pre-fix
        // arm intact and was returned to position-addressed consumers
        // (token offsets, stmt indexes) as if it were a list.
        $tokens = [new Token(\T_STRING, 'a', 1, 0), new Token(\T_STRING, 'b', 2, 5)];
        $stringKeyed = serialize(['first' => $tokens[0], 'second' => $tokens[1]]);
        $sparseKeyed = serialize([1 => $tokens[0], 3 => $tokens[1]]);

        // Counter-assert the discriminator: both forgeries decode to real
        // Token elements that pass the whole element-screen battery —
        // only the key shape itself can reject them.
        foreach ([$stringKeyed, $sparseKeyed] as $forgery) {
            $decoded = @unserialize($forgery, ['allowed_classes' => SerializedClassAllowList::parserClasses()]);
            $this->assertIsArray($decoded);
            $this->assertFalse(array_is_list($decoded), 'fixture must stay non-list or the pin proves nothing');
            foreach ($decoded as $element) {
                $this->assertInstanceOf(Token::class, $element);
                $this->assertTrue(CacheBlob::isScalarOnlyLeaf($element));
            }
        }

        $cache = new TokenCache($this->tmp);
        $cache->put($this->sourceFile, $tokens);

        // Positive control: the very same elements under sequential
        // keys must still HIT — the guard rejects forgeries, not lists.
        $this->assertNotNull($cache->get($this->sourceFile), 'honest lists must still hit');

        foreach ([$stringKeyed, $sparseKeyed] as $forgery) {
            file_put_contents($this->onlyBlob(), CacheBlob::frame($forgery));
            $this->assertNull($cache->get($this->sourceFile), 'non-list key shape must fail closed to a miss');
        }
    }

    public function testFreshCacheDirIsPrivateAndBlobsOwnerOnly(): void
    {
        $umask = umask(0o002); // the shared-runner posture engine-020 measured
        $dir = $this->tmp . '/perm-cache';
        try {
            $cache = new TokenCache($dir);
            clearstatcache();
            $this->assertSame(0o700, fileperms($dir) & 0o777, 'fresh cache dir must be private');

            $cache->put($this->sourceFile, [new Token(\T_OPEN_TAG, '<?php ', 1, 0)]);
            $blobs = glob($dir . '/*.tok') ?: [];
            $this->assertCount(1, $blobs);
            clearstatcache();
            $this->assertSame(0o600, fileperms($blobs[0]) & 0o777, 'blobs must be owner-only under umask 002');
        } finally {
            umask($umask);
        }
    }

    // ---- helpers ----

    /** @return list<\PhpParser\Token> */
    private static function honestTokens(): array
    {
        return [new Token(\T_STRING, 'foo', 1, 0)];
    }

    private static function honestTokenPayload(): string
    {
        return serialize(self::honestTokens());
    }

    private function onlyBlob(): string
    {
        $files = glob($this->tmp . '/*.tok') ?: [];
        $this->assertCount(1, $files, 'exactly one token blob must exist for this fixture');
        return $files[0];
    }

    /** Serialized-object prefix for a class, e.g. O:15:"PhpParser\Token". */
    private static function objectMarker(string $fqcn): string
    {
        return 'O:' . strlen($fqcn) . ':"' . $fqcn . '"';
    }

    /**
     * Replace the full serialized segment that starts at $marker (brace
     * balanced) with $replacement. Loudly fails if the marker moves in a
     * future php-parser — the evil payloads must stay byte-valid.
     */
    private static function spliceSegment(string $blob, string $marker, string $replacement): string
    {
        $start = strpos($blob, $marker);
        if ($start === false) {
            self::fail("serialized blob lost its '$marker' segment — update the fixture");
        }
        $depth = 0;
        $end = -1;
        for ($i = $start, $n = strlen($blob); $i < $n; $i++) {
            if ($blob[$i] === '{') {
                $depth++;
            } elseif ($blob[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }
        if ($end <= $start) {
            self::fail("unbalanced segment for '$marker' — update the fixture");
        }
        return substr($blob, 0, $start) . $replacement . substr($blob, $end);
    }
}
