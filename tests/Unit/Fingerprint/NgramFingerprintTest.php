<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\FingerprintIdMode;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Delimiters;

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
     * With $unnumbered the oracle mirrors the live mode knob's strip
     * step (src/Fingerprint/NgramFingerprint.php:140-146 — foldStamps
     * first, then IdentifierIds::strip), so the unnumbered species of
     * the interning pin is fenced here, not only in the /tmp probe
     * (engine-S18b review r1, LOW-1).
     *
     * @return array<string,int>
     */
    private function legacyBag(\PhpParser\Node $node, int $n = 5, bool $unnumbered = false): array
    {
        $tokens = \Phpdup\Util\AstSerializer::tokens($node);
        $tokens = array_map([\Phpdup\Util\IdentifierIds::class, 'foldStamps'], $tokens);
        if ($unnumbered) {
            $tokens = array_map([\Phpdup\Util\IdentifierIds::class, 'strip'], $tokens);
        }
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
        // numeric-string key species — in all four constructor shapes
        // (normal, lowMemory, unnumbered, unnumbered+lowMemory). The
        // unnumbered pair rides here since engine-S18b r1 LOW-1: the
        // live-by-default interning must stay byte-identical through
        // the mode knob's strip step too, not just on the /tmp probe.
        $fp      = new NgramFingerprint(5);
        $fpLow   = new NgramFingerprint(5, true);
        $fpUn    = new NgramFingerprint(5, false, true);
        $fpLowUn = new NgramFingerprint(5, true, true);
        foreach ([self::DICT_CODE_A, self::DICT_CODE_B] as $code) {
            $node     = $this->canonicalOf($code);
            $legacy   = $this->legacyBag($node);
            $legacyUn = $this->legacyBag($node, 5, true);
            $this->assertSame($legacy, $fp->fingerprint($node),
                'normal-mode bag moved under the master gram dictionary');
            $this->assertSame(\Phpdup\Fingerprint\CompactNgramBag::compact($legacy), $fpLow->fingerprint($node),
                'low-memory compact bag moved under the master gram dictionary (engine-042 keyspace is sacred)');
            $this->assertSame($legacyUn, $fpUn->fingerprint($node),
                'unnumbered bag moved under the master gram dictionary');
            $this->assertSame(\Phpdup\Fingerprint\CompactNgramBag::compact($legacyUn), $fpLowUn->fingerprint($node),
                'unnumbered low-memory compact bag moved under the master gram dictionary (engine-042 keyspace is sacred)');
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

    // ------------------------------------------------------------------
    // engine-S34a — prefix-free stream at the gram layer (engine-037)
    // ------------------------------------------------------------------

    private function canonicalStrict(string $code): \PhpParser\Node
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        self::assertNotEmpty($blocks, 'fixture must yield a block');
        (new Normalizer('strict'))->normalize($blocks[0]);
        return $blocks[0]->canonical;
    }

    public function testCleanCorpusGramKeysAreByteFrozen(): void
    {
        // The no-move law at the gram layer: framing only rewrites values
        // carrying the framing bytes, so the clean aggressive-mode corpus
        // bag keeps its exact pre-framing digest sequence. These are the
        // first three gram keys captured from the pre-framing build
        // (engine-S34a baseline probe); a shift here means clean corpora
        // silently re-fingerprinted on this half — goldens/caches included.
        $bag  = $this->bagOf(self::DICT_CODE_A);
        $keys = array_values(array_map('strval', array_keys($bag)));
        $this->assertCount(13, $bag);
        $this->assertSame(
            ['58a4ad1e6104a32c', 'dedb09e8cc91d4d4', 'e5c7210d01184a9f'],
            array_slice($keys, 0, 3)
        );
    }

    public function testForgedLiteralNoLongerSharesGramWindowsWithItsTarget(): void
    {
        // PDDETECT-07 / ENGINE-FUZZ-04 on the n-gram layer: before the
        // framing, a literal carrying \x1E/\x1F bytes forged gram windows
        // byte-identical to windows built from the REAL tokens its text
        // imitated (5 shared grams between the PD-07 pair at baseline,
        // some of them forged). After the framing every gram window that
        // touches the literal contains the frame header, so a forged
        // window cannot exist in the target's bag at all.
        $twoArg = $this->canonicalStrict('<?php function q() { e("a", "b"); return 1; }');
        $forge  = $this->canonicalStrict(
            '<?php function q() { e("a\x1f)\x1fArg#false#false(\x1fScalar_String_|s:b"); return 1; }'
        );

        $fp      = new NgramFingerprint(5);
        $bagReal = $fp->fingerprint($twoArg);
        $bagForge = $fp->fingerprint($forge);
        $this->assertNotSame($bagReal, $bagForge, 'forged block must not fingerprint as its target');

        // Window-level proof: each gram window of the forged block that
        // covers its literal token hashes to bytes that appear nowhere in
        // the target's window material — the splice is inert. Windows are
        // folded exactly as NgramFingerprint folds them (engine-S02a
        // stamp fold, unconditional).
        $fold  = static fn(array $ts): array => array_map([\Phpdup\Util\IdentifierIds::class, 'foldStamps'], $ts);
        $forgeTokens = $fold(\Phpdup\Util\AstSerializer::tokens($forge));
        $literalAt   = null;
        foreach ($forgeTokens as $i => $t) {
            if (str_starts_with($t, 'Scalar_String_|s:' . \Phpdup\Util\AstSerializer::FRAME_MARK)) {
                $literalAt = $i;
                break;
            }
        }
        self::assertNotNull($literalAt, 'the forged literal must ride the stream framed');

        $realGramKeys = array_map('strval', array_keys($bagReal));
        $n = 5;
        $forgeCount = count($forgeTokens);
        for ($start = max(0, $literalAt - $n + 1); $start <= min($forgeCount - $n, $literalAt); $start++) {
            $windowKey = hash('xxh64', implode(
                \Phpdup\Util\Delimiters::NGRAM_SEP,
                array_slice($forgeTokens, $start, $n),
            ));
            $this->assertNotContains(
                $windowKey,
                $realGramKeys,
                "forged gram window at offset {$start} impersonated a real target gram"
            );
        }
    }

    public function testRosterHomeLivesBesideTheFingerprintClasses(): void
    {
        // Phase-5 r1 corrected a phantom roster path (`config/CacheKey/`):
        // the cache-identity roster belongs in src/Fingerprint/, next to
        // the classes whose keyspace it names. This pin keeps the roster
        // from migrating out of the namespace it governs.
        $file = (new \ReflectionClass(FingerprintIdMode::class))->getFileName();
        $this->assertIsString($file);
        $this->assertStringEndsWith(
            '/src/Fingerprint/FingerprintIdMode.php',
            str_replace('\\', '/', $file)
        );
        // The clean gram keyspace above is byte-frozen across the roster
        // event (testCleanCorpusGramKeysAreByteFrozen): the roster names
        // the generation, it does not re-key honest code — so its only
        // job from here is that the store lanes stamp toward it.
        $this->assertStringStartsWith('cachekey-v', FingerprintIdMode::CACHE_IDENTITY);
    }

    // ------------------------------------------------------------------
    // engine-S33 — roll-window gram minting + shared canonical walk
    // (php-dup-finder-engine-034)
    // ------------------------------------------------------------------

    /**
     * Shapes the live fingerprinter runs: n with both numbering modes,
     * plus the low-memory compact key space of engine-042/S32.
     *
     * @return list<array{0:int,1:bool,2:bool}> [n, unnumbered, lowMemory]
     */
    private static function bagShapes(): array
    {
        $shapes = [];
        foreach ([2, 3, 5, 7] as $n) {
            foreach ([false, true] as $unnumbered) {
                foreach ([false, true] as $lowMemory) {
                    $shapes[] = [$n, $unnumbered, $lowMemory];
                }
            }
        }
        return $shapes;
    }

    public function testRollWindowBagsAreByteIdenticalToTheSliceImplodeBuild(): void
    {
        // The acceptance differential: the pre-S33 gram loop (slice +
        // implode per window — replicated in legacyBag(), the very
        // oracle engine-S18b froze) vs the roll-window build, over the
        // fixture corpus and the generated adversarial corpus, in every
        // shape the pipeline constructs. A window cut that drifted by a
        // single byte — the trailing-separator arithmetic, the <EOS>
        // pad edge, a multibyte token — reddens here, long before any
        // golden could notice.
        $checked = 0;
        foreach ($this->s33CorpusNodes() as $node) {
            foreach (self::bagShapes() as [$n, $unnumbered, $lowMemory]) {
                $fp     = new NgramFingerprint($n, $lowMemory, $unnumbered);
                $legacy = $this->legacyBag($node, $n, $unnumbered);
                $want   = $lowMemory ? \Phpdup\Fingerprint\CompactNgramBag::compact($legacy) : $legacy;
                $this->assertSame(
                    $want,
                    $fp->fingerprint($node),
                    "bag moved under the roll-window (n={$n} unnumbered=" . var_export($unnumbered, true)
                        . ' lowMemory=' . var_export($lowMemory, true) . ')',
                );
                ++$checked;
            }
        }
        $this->assertGreaterThan(400, $checked, 'differential corpus collapsed');
    }

    public function testHandoffServedBagsMatchWalkedBagsAcrossTheCorpus(): void
    {
        // Same differential, but with the preprocess hot order exercised:
        // hash() first parks the walk, fingerprint() takes it. The bag
        // the served path mints must be identical to the bag the walked
        // path mints for the same tree (and to the slice/implode
        // oracle), so sharing the walk cannot change a fingerprint.
        $checked = 0;
        foreach ($this->s33CorpusNodes() as $node) {
            foreach ([[5, false], [3, true]] as [$n, $unnumbered]) {
                $hasher = new SubtreeHasher();
                $hasher->hash($node);                       // mint + park
                $served = (new NgramFingerprint($n, false, $unnumbered))->fingerprint($node);
                $this->assertSame(
                    $this->legacyBag($node, $n, $unnumbered),
                    $served,
                    'a handoff-served bag differs from the walked build',
                );
                // The park is drained: this second call walks on its own
                // and must land on the very same bag.
                $walked = (new NgramFingerprint($n, false, $unnumbered))->fingerprint($node);
                $this->assertSame($served, $walked, 'served and walked bags diverged');
                ++$checked;
            }
        }
        $this->assertGreaterThan(200, $checked);
    }

    public function testWindowByteAlgebraHoldsForAnyTokenContent(): void
    {
        // The roll-window rewrite rides one identity: cutting the
        // pre-joined stream at token byte offsets yields the same bytes
        // as joining the slice. Seeded fuzz over adversarial token lists
        // (control bytes, frame headers, invalid UTF-8, empties, a
        // multi-byte tail) replaying fingerprint()'s own pad step — the
        // offsets are byte-based, so the equivalence cannot depend on
        // the tokens being well-formed text.
        $sep    = Delimiters::NGRAM_SEP;
        $sepLen = strlen($sep);
        $pool   = [
            'a', '', 'notify@x.org', 'x' . $sep . 'y', 'x' . "\x1F" . 'y',
            'x' . "\x00" . 'y', AstSerializer::FRAME_MARK . '3:q', "\xC3\x28",
            str_repeat('t', 70), 'i:7', 'id:__V2', '<EOS>', '🌯tail',
        ];
        mt_srand(20261122);
        for ($case = 0; $case < 400; $case++) {
            $tokens = [];
            $len    = mt_rand(1, 12);
            for ($k = 0; $k < $len; $k++) {
                $tokens[] = $pool[mt_rand(0, count($pool) - 1)];
            }
            foreach ([2, 3, 5, 7] as $n) {
                $windowed = $tokens;
                $count    = count($windowed);
                if ($count < $n) {
                    $windowed = array_pad($windowed, $n, '<EOS>');
                    $count    = $n;
                }
                $stream = implode($sep, $windowed);
                $start  = [];
                $offset = 0;
                foreach ($windowed as $token) {
                    $start[]  = $offset;
                    $offset  += strlen($token) + $sepLen;
                }
                $start[] = $offset;
                for ($i = 0; $i <= $count - $n; $i++) {
                    $this->assertSame(
                        implode($sep, array_slice($windowed, $i, $n)),
                        substr($stream, $start[$i], $start[$i + $n] - $start[$i] - $sepLen),
                        'window byte algebra broke',
                    );
                }
            }
        }
    }

    /**
     * Canonical blocks for the S33 differential: the fixture trees
     * (three normalizer modes) plus the seeded adversarial generator.
     *
     * @return list<\PhpParser\Node>
     */
    private function s33CorpusNodes(): array
    {
        $nodes = [];
        $root  = dirname(__DIR__, 3) . '/tests/Fixtures';
        $files = [];
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                    $files[] = $f->getPathname();
                }
            }
            sort($files);
        }
        self::assertGreaterThanOrEqual(20, count($files), 'the fixture corpus vanished');
        foreach ($files as $path) {
            $stmts = (new AstParser())->parseFile($path);
            if ($stmts === null) {
                continue;
            }
            foreach (['strict', 'aggressive'] as $mode) {
                foreach ((new BlockExtractor(minSize: 1))->extract($path, $stmts) as $block) {
                    (new Normalizer($mode))->normalize($block);
                    $nodes[] = $block->canonical;
                }
            }
        }
        foreach ([
            '<?php function tiny() { return 1; }',
            '<?php function pad() { $a = ""; }',
            '<?php function forged() { return "a\x1f)\x1fArg#false#false(\x1fScalar_String_|s:b"; }',
            '<?php function framed($v) { return preg_replace(["/a/", "/b/"], [$v, "x{$v}y"], "semi;colon"); }',
            '<?php function nested($x) { return array_map(fn($y) => $y + self::CONST_A, ($x ? ["k" => "\\u{1F3AF}"] : [])); }',
        ] as $code) {
            foreach (['strict', 'default', 'aggressive'] as $mode) {
                foreach ($this->treesInMode($code, $mode) as $node) {
                    $nodes[] = $node;
                }
            }
        }
        return $nodes;
    }

    /** @return list<\PhpParser\Node> */
    private function treesInMode(string $code, string $mode): array
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $out = [];
        foreach ((new BlockExtractor(minSize: 1))->extract('t.php', $stmts) as $block) {
            (new Normalizer($mode))->normalize($block);
            $out[] = $block->canonical;
        }
        return $out;
    }
}
