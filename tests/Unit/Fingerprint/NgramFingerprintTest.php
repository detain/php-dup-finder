<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\JaccardSimilarity;

final class NgramFingerprintTest extends TestCase
{
    public function testJaccardOfIdenticalCodeIsOne(): void
    {
        $bag = $this->bagOf('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $this->assertEqualsWithDelta(1.0, (new JaccardSimilarity())->similarity($bag, $bag), 0.001);
    }

    public function testJaccardOfRenamedVariablesIsHighInAggressiveMode(): void
    {
        $a = $this->bagOf('<?php function f($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }');
        $b = $this->bagOf('<?php function g($entries) { $total = 0; foreach ($entries as $e) { $total += $e; } return $total; }');
        $sim = (new JaccardSimilarity())->similarity($a, $b);
        $this->assertGreaterThanOrEqual(0.95, $sim, "renamed-variable Jaccard was $sim");
    }

    public function testJaccardOfUnrelatedCodeIsLow(): void
    {
        $a = $this->bagOf('<?php function f($x) { return $x * 2; }');
        $b = $this->bagOf('<?php function g($items) { foreach ($items as $i) { echo $i; for ($j = 0; $j < 10; $j++) {} } }');
        $sim = (new JaccardSimilarity())->similarity($a, $b);
        $this->assertLessThan(0.5, $sim, "unrelated Jaccard was $sim");
    }

    /** @return array<string,int> */
    private function bagOf(string $code, string $mode = 'aggressive'): array
    {
        return $this->fingerprintOf($code, new NgramFingerprint(5), $mode);
    }

    /** @return array<string|int,int> */
    private function fingerprintOf(string $code, NgramFingerprint $fp, string $mode = 'aggressive'): array
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode($code);
        $blocks = $extractor->extract('test.php', $stmts);
        (new Normalizer($mode))->normalize($blocks[0]);
        return $fp->fingerprint($blocks[0]->canonical);
    }

    private function canonicalOf(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        self::assertNotEmpty($blocks, 'fixture must yield a block');
        (new Normalizer('aggressive'))->normalize($blocks[0]);
        return $blocks[0]->canonical;
    }

    /**
     * Faithful replica of the pre-engine-S18b build: identical token
     * fold, <EOS> padding and hash-per-incidence loop, but every bag
     * insertion keeps its OWN fresh key string (no master dictionary).
     * This is the byte-identity oracle the interning leg ships against;
     * the /tmp/opencode/engine-S18b-lane parity probe ran the same
     * comparison against the real pre-commit class over 8,614 blocks.
     *
     * @return array<string,int>
     */
    private function legacyBag(\PhpParser\Node $node, int $n = 5): array
    {
        $tokens = \Phpdup\Util\AstSerializer::tokens($node);
        $tokens = array_map([\Phpdup\Util\IdentifierIds::class, 'foldStamps'], $tokens);
        $count = count($tokens);
        if ($count < $n) {
            $tokens = array_pad($tokens, $n, '<EOS>');
            $count = $n;
        }
        $bag   = [];
        $upper = $count - $n;
        for ($i = 0; $i <= $upper; $i++) {
            $key = hash('xxh64', implode(\Phpdup\Util\Delimiters::NGRAM_SEP, array_slice($tokens, $i, $n)));
            $bag[$key] = ($bag[$key] ?? 0) + 1;
        }
        return $bag;
    }

    // ------------------------------------------------------------------
    // engine-S18b — master gram dictionary (php-dup-finder-engine-011)
    // ------------------------------------------------------------------

    private const DICT_CODE_A = '<?php function a($x) { if ($x > 10) { return "a"; } return "b"; }';
    private const DICT_CODE_B = '<?php function b($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }';

    public function testDictionaryBagsAreByteIdenticalToThePreDictionaryBuild(): void
    {
        // The interning leg must not move a single byte of any bag:
        // same key contents, same insertion order, same counts, same
        // numeric-string key species — in all three bagging modes.
        $fp    = new NgramFingerprint(5);
        $fpLow = new NgramFingerprint(5, true);
        foreach ([self::DICT_CODE_A, self::DICT_CODE_B] as $code) {
            $node   = $this->canonicalOf($code);
            $legacy = $this->legacyBag($node);
            $this->assertSame($legacy, $fp->fingerprint($node),
                'normal-mode bag moved under the master gram dictionary');
            $this->assertSame(\Phpdup\Fingerprint\CompactNgramBag::compact($legacy), $fpLow->fingerprint($node),
                'low-memory compact bag moved under the master gram dictionary (engine-042 keyspace is sacred)');
        }
    }

    public function testDictionaryInternsOneCanonicalKeyPerGramAcrossBags(): void
    {
        $fp = new NgramFingerprint(5);
        $bagA = $fp->fingerprint($this->canonicalOf(self::DICT_CODE_A));
        $dictAfterA = $fp->dictionarySize();
        $this->assertCount($dictAfterA, $bagA); // no duplicate gram within one bag beyond counts
        $bagB = $fp->fingerprint($this->canonicalOf(self::DICT_CODE_B));

        // Union of distinct grams across both bags == dictionary size:
        // repeated grams do NOT get a new dictionary slot per incidence.
        $union = count(array_unique(array_merge(array_map('strval', array_keys($bagA)), array_map('strval', array_keys($bagB)))));
        $this->assertSame($union, $fp->dictionarySize(),
            'dictionary grew per-incidence instead of per-distinct-gram');

        // Re-fingerprinting changes nothing (stable keys) and the bag is
        // value-identical to the first pass.
        $bagA2 = $fp->fingerprint($this->canonicalOf(self::DICT_CODE_A));
        $this->assertSame($bagA, $bagA2, 'a second pass over the same tree must produce the identical bag');
        $this->assertSame($union, $fp->dictionarySize(), 're-interning must not grow the dictionary');
    }

    public function testGramIdsAreStableDistinctAndDecodable(): void
    {
        $fp  = new NgramFingerprint(5);
        $bag = $fp->fingerprint($this->canonicalOf(self::DICT_CODE_A));
        $keys = array_map('strval', array_keys($bag));

        $ids = [];
        foreach ($keys as $k) {
            $id = $fp->gramId($k);
            $this->assertIsInt($id, "gramId() lost the id of an already-bagged gram");
            $this->assertGreaterThanOrEqual(0, $id);
            $this->assertSame($id, $fp->gramId($k), 'gramId() must be stable across calls');
            $this->assertSame($k, $fp->gramForKey($id), 'gramForKey() must decode to the canonical digest');
            $ids[] = $id;
        }
        $this->assertCount(count($keys), array_unique($ids), 'distinct grams must carry distinct ids');
        sort($ids);
        $this->rangeFromZero($ids);

        // Never-minted lookups refuse instead of inventing slots.
        $this->assertNull($fp->gramId(str_repeat('f', 16)));
        $this->assertNull($fp->gramForKey(-1));
        $this->assertNull($fp->gramForKey(count($keys) + 7));
    }

    /** ids are assigned in first-interned order: a dense 0..n-1 range */
    private function rangeFromZero(array $sortedIds): void
    {
        for ($i = 0, $c = count($sortedIds); $i < $c; $i++) {
            $this->assertSame($i, $sortedIds[$i], 'gram ids must form a dense range starting at 0');
        }
    }

    public function testDictionaryIsPerFingerprinterInstance(): void
    {
        // Guards the instance-not-static law: a long-lived serve/watch
        // process must not accumulate a cross-run dictionary, and the
        // Clusterer candidate guard builds its own NgramFingerprint per
        // run (engine-023 call site) — independent namespaces required.
        $fpA  = new NgramFingerprint(5);
        $bagA = $fpA->fingerprint($this->canonicalOf(self::DICT_CODE_A));
        $this->assertSame(count($bagA), $fpA->dictionarySize(),
            'a single-bag run must intern exactly the bag\'s distinct grams');

        $fpB  = new NgramFingerprint(5);
        $bagB = $fpB->fingerprint($this->canonicalOf(self::DICT_CODE_B));

        // A gram that only exists in bag A is unknown to the B-only
        // instance: no cross-instance bleed through a shared table.
        $aOnly = array_values(array_diff(array_map('strval', array_keys($bagA)), array_map('strval', array_keys($bagB))));
        $this->assertNotEmpty($aOnly, 'fixture bags must not share their entire gram set for this probe');
        foreach ($aOnly as $gram) {
            $this->assertNull($fpB->gramId($gram), "A-only gram {$gram} leaked into the B instance");
        }

        // Bagging B did not touch A's dictionary either.
        $this->assertSame(count($bagA), $fpA->dictionarySize());
    }
}
