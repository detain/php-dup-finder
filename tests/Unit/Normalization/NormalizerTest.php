<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;

final class NormalizerTest extends TestCase
{
    public function testRenamedVariablesProduceIdenticalCanonicalHash(): void
    {
        $a = '<?php function f($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }';
        $b = '<?php function g($entries) { $total = 0; foreach ($entries as $e) { $total += $e; } return $total; }';

        $hashA = $this->canonicalHash($a);
        $hashB = $this->canonicalHash($b);
        $this->assertSame($hashA, $hashB, 'renamed-variable clones must canonicalize to the same hash');
    }

    public function testDifferentLiteralsCanonicalizeIdenticallyInDefaultMode(): void
    {
        $a = '<?php function f($x) { if ($x > 10) { return "admin"; } return "user"; }';
        $b = '<?php function g($y) { if ($y > 99) { return "moderator"; } return "guest"; }';
        $this->assertSame($this->canonicalHash($a), $this->canonicalHash($b));
    }

    public function testDifferentMethodNamesCollideUnderAggressiveNormalization(): void
    {
        $a = '<?php function f($db) { $rows = $db->query("X"); return $rows->fetch(); }';
        $b = '<?php function g($db) { $items = $db->prepare("Y"); return $items->execute(); }';
        $this->assertSame($this->canonicalHash($a, 'aggressive'), $this->canonicalHash($b, 'aggressive'));
    }

    public function testStrictModeDoesNotCollapseLiterals(): void
    {
        $a = '<?php function f($x) { return 10; }';
        $b = '<?php function g($y) { return 20; }';
        $this->assertNotSame($this->canonicalHash($a, 'strict'), $this->canonicalHash($b, 'strict'));
    }

    public function testKeepsThisDistinct(): void
    {
        $a = '<?php class A { public function m() { $x = $this->a; return $x; } }';
        $b = '<?php class A { public function m() { $x = $other->a; return $x; } }';
        // $other gets renamed but $this is preserved → hashes differ
        $this->assertNotSame($this->canonicalHash($a), $this->canonicalHash($b));
    }

    private function canonicalHash(string $code, string $mode = 'aggressive'): string
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks, 'fixture must produce at least one block');
        $normalizer = new Normalizer($mode);
        $normalizer->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }
}
