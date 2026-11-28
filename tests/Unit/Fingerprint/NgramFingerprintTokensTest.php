<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PhpParser\Node\Stmt\InlineHTML;
use PHPUnit\Framework\TestCase;
use Phpdup\Util\Delimiters;
use Phpdup\Fingerprint\NgramFingerprint;

/**
 * Item 7.1 one-formula law: fingerprintTokens() must ride the EXACT
 * window byte-algebra fingerprint(Node) uses — same pad, same join,
 * same substr cuts, same master dictionary. These pins fence the token
 * door against any future dialect fork.
 */
final class NgramFingerprintTokensTest extends TestCase
{
    /** @param list<string> $tokens */
    private function legacyWindowKeys(array $tokens, int $n): array
    {
        if (count($tokens) < $n) {
            $tokens = array_pad($tokens, $n, '<EOS>');
        }
        $keys = [];
        for ($i = 0, $up = count($tokens) - $n; $i <= $up; $i++) {
            $keys[] = hash('xxh64', implode(Delimiters::NGRAM_SEP, array_slice($tokens, $i, $n)));
        }
        return $keys;
    }

    public function testWindowsMatchTheSliceImplodeReplica(): void
    {
        $streams = [
            'short-padded' => ['I', 'I'],
            'exact-window' => ['I', '(', 'I', ')', ';'],
            'longer'       => ['I', '(', 'I', ',', 'S', ')', ';', 'N', '+', 'N', ';'],
        ];
        foreach ($streams as $label => $tokens) {
            $this->assertSame(
                $this->legacyWindowKeys($tokens, 5),
                NgramFingerprint::windows($tokens, 5),
                $label,
            );
        }
    }

    public function testWindowsRejectDegenerateWidths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NgramFingerprint::windows(['a', 'b'], 1);
    }

    public function testTokenBagIsTheInteredWindowsMultiset(): void
    {
        $tokens = ['I', '(', 'I', ',', 'S', ')', ';', 'I', '=', 'N', ';'];
        $fp     = new NgramFingerprint(5);
        $bag    = $fp->fingerprintTokens($tokens);
        $keys   = NgramFingerprint::windows($tokens, 5);

        $this->assertSame(array_sum($bag), count($keys), 'every window lands in the bag exactly once');
        $expectedCounts = array_count_values($keys);
        foreach ($bag as $digest => $count) {
            $this->assertIsString((string) $digest, 'normal-mode bags keep xxh64 hex keys (identical to the pre-interning build)');
            $this->assertNotNull($fp->gramId((string) $digest), 'and every key is dictionary-registered');
            $this->assertSame($expectedCounts[$digest], $count);
        }
    }

    public function testNodePathAndTokenPathShareTheDictionaryAndFormula(): void
    {
        $fp = new NgramFingerprint(5);

        // Same stream through both doors: the serializer's token list for a
        // raw-text fold node, replayed verbatim as a token stream.
        $node   = new InlineHTML('I ( I , S ) ;');
        $stream = \Phpdup\Util\AstSerializer::tokens($node);
        $this->assertCount(1, $stream, 'the fold node is one free-text token (T1a identity)');

        $nodeBag  = $fp->fingerprint($node);
        $sizeAfterNode = $fp->dictionarySize();

        $tokenBag = $fp->fingerprintTokens($stream);
        $this->assertSame($nodeBag, $tokenBag, 'the same alphabet mints the same bag through either door');
        $this->assertSame($sizeAfterNode, $fp->dictionarySize(), 'the token path re-interned nothing new');
    }

    public function testLowMemoryPathCompactsTokenBagsToo(): void
    {
        $tokens = [];
        for ($i = 0; $i < 30; $i++) {
            $tokens[] = $i % 2 === 0 ? 'I' : ';';
        }
        $plain  = (new NgramFingerprint(5, false))->fingerprintTokens($tokens);
        $packed = (new NgramFingerprint(5, true))->fingerprintTokens($tokens);

        $this->assertGreaterThan(0, $plain);
        $this->assertSame(array_sum($plain), array_sum($packed), 'same multiplicities survive compaction');
        foreach (array_keys($packed) as $k) {
            $this->assertIsInt($k, 'low-memory bag is int-keyed on both doors');
        }
    }
}
