<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Delimiters;
use Phpdup\Util\Hash;

/**
 * Pins for the canonical-stream mint and its one-slot handoff
 * (engine-S33, php-dup-finder-engine-034).
 *
 * Two claims, fenced separately:
 *  1. VALUE — every digest this class emits is what the pre-handoff
 *     build emitted, proven by differential against {@see
 *     referenceHash()} (a verbatim replica of the old codepath) over
 *     the fixtures and a seeded generated corpus, plus the absolute
 *     clean-side pin from the engine-S34a baseline.
 *  2. REUSE — the walk behind the hash is handed to the gram side
 *     exactly once, for the identical node object, and never to
 *     anyone else. Observed through {@see SubtreeHasher::hasCanonicalStream()},
 *     which reports the handoff's live state, and through bag equality
 *     against the replica on both the served and the walked path.
 */
final class SubtreeHasherTest extends TestCase
{
    private SubtreeHasher $hasher;
    private AstParser $parser;

    protected function setUp(): void
    {
        $this->hasher = new SubtreeHasher();
        $this->parser = new AstParser();
    }

    /**
     * Faithful replica of the pre-engine-S33 build: its own fresh walk,
     * one implode under TOKEN_JOIN, xxh128. No handoff, no sharing.
     */
    private function referenceHash(Node $node): string
    {
        return Hash::of(implode(Delimiters::TOKEN_JOIN, AstSerializer::tokens($node)));
    }

    /** @return list<Node> every block of $code, normalized in $mode */
    private function treesOf(string $code, string $mode = 'aggressive'): array
    {
        $stmts = $this->parser->parseCode($code);
        self::assertNotNull($stmts, 'corpus code must parse');
        $out = [];
        foreach ((new BlockExtractor(minSize: 1))->extract('t.php', $stmts) as $block) {
            (new Normalizer($mode))->normalize($block);
            $out[] = $block->canonical;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 1. value preservation (acceptance: differential old-vs-new codepath)
    // ------------------------------------------------------------------

    public function testStructuralHashIsByteIdenticalToThePreHandoffBuild(): void
    {
        $checked = 0;
        foreach ($this->corpusNodes() as $node) {
            $this->assertSame(
                $this->referenceHash($node),
                $this->hasher->hash($node),
                'structural hash moved under the single-walk handoff',
            );
            ++$checked;
        }
        // Non-vacuity: the corpus must actually have exercised the leg.
        $this->assertGreaterThan(120, $checked, 'differential corpus collapsed');
    }

    public function testHashIsFreshOnEveryCallEvenBesideAStalePark(): void
    {
        // hash() never rides a park — not even one minted for its own
        // node: the structural hash is the strongest identity in the
        // stores, so if mint and consume fused, a tree mutated after
        // being parked would keep hashing stale bytes. Strict mode keeps
        // the two fixtures' literal difference visible in the stream.
        $nodeA = $this->treesOf('<?php function h($x) { if ($x) { return "a"; } return $x + 1; }', 'strict')[0];
        $nodeB = $this->treesOf('<?php function h($x) { if ($x) { return "b"; } return $x + 1; }', 'strict')[0];

        $hashA = $this->hasher->hash($nodeA);              // parks A's stream
        $this->assertSame($this->referenceHash($nodeA), $hashA);

        $hashB = $this->hasher->hash($nodeB);              // re-parks with B
        $this->assertNotSame($hashA, $hashB, 'differing trees must not converge');
        $this->assertSame($this->referenceHash($nodeB), $hashB);

        // A again, now that B owns the park: fresh walk, own digest.
        $this->assertSame($hashA, $this->hasher->hash($nodeA));
        $this->assertSame($hashA, $this->referenceHash($nodeA));
    }

    public function testCleanCorpusStreamDigestIsAbsolutelyFrozen(): void
    {
        // The ab38-family clean-side pin (engine-S34a baseline probe)
        // read through THIS class: honest code's stream bytes are frozen
        // across the handoff leg exactly as they were across the framing
        // leg. A shift here is a mass cache/golden migration.
        $this->assertSame(
            'ab38abd589f9919e27634084903d1220',
            $this->hasher->hash($this->treesOf(
                '<?php function c() { $s = "notify@x.org"; $n = send($s, 7); return $n > 0 ? "ok" : $s; }',
                'strict',
            )[0]),
        );
    }

    // ------------------------------------------------------------------
    // 2. the handoff contract
    // ------------------------------------------------------------------

    public function testHandoffParksWhatHashMintedAndDrainsOnATakeByThatNode(): void
    {
        $node = $this->treesOf('<?php function d($v) { foreach ($v as $i) { echo $i; } return count($v); }')[0];

        $this->hasher->hash($node);
        $this->assertTrue(SubtreeHasher::hasCanonicalStream(), 'hash() minted a stream but parked nothing');

        // The gram consumer takes the stream it was served — and cannot
        // take it twice (a second fingerprint walks again).
        $fp  = new NgramFingerprint(5);
        $bag = $fp->fingerprint($node);
        $this->assertNotEmpty($bag);
        $this->assertFalse(SubtreeHasher::hasCanonicalStream(), 'the gram side did not consume the walk it was served');

        $this->assertSame($bag, $fp->fingerprint($node), 'a served bag and a walked bag disagree');
    }

    public function testHandoffServesOnlyTheIdenticalNodeAndAMissKeepsIt(): void
    {
        $a = $this->treesOf('<?php function e($x) { return $x * 2; }')[0];
        $b = $this->treesOf('<?php function e($x) { return $x * 2; }')[0]; // same text, different object
        $this->assertNotSame($a, $b, 'the fixture must mint two distinct trees');

        $fp = new NgramFingerprint(5);
        $this->hasher->hash($a);

        // A take for another node misses: it walks its own tree and must
        // leave a still-wanted park standing for its owner.
        $bagB = $fp->fingerprint($b);
        $this->assertSame($bagB, $fp->fingerprint($b), 'bag is not a function of the tree');
        $this->assertTrue(SubtreeHasher::hasCanonicalStream(), 'a miss drained a park another consumer still owns');

        // The owner's take hits and drains.
        $bagA = $fp->fingerprint($a);
        $this->assertFalse(SubtreeHasher::hasCanonicalStream(), 'the owner take did not drain the park');
        $this->assertSame($bagA, $fp->fingerprint($a), 'served and walked bags for the owner diverged');
        $this->assertSame($bagB, $fp->fingerprint($b), 'the missed node must keep its own bag');
    }

    public function testReNormalizedBlockIsServedByItsOwnWalk(): void
    {
        // normalize() mints a fresh canonical object per pass, so a
        // re-normalized block never rides the previous pass's park.
        $code = '<?php function f($q) { $t = []; foreach ($q as $k => $v) { $t[$k] = $v + 1; } return $t; }';
        $old  = $this->treesOf($code)[0];
        $this->hasher->hash($old);

        $fresh = $this->treesOf($code)[0];
        $this->assertNotSame($old, $fresh);

        $fp       = new NgramFingerprint(5);
        $bagFresh = $fp->fingerprint($fresh);   // miss: walks its own tree
        $this->assertTrue(SubtreeHasher::hasCanonicalStream(), 'the miss drained the old park');
        $this->assertSame($bagFresh, $fp->fingerprint($fresh));
        $this->assertTrue(SubtreeHasher::hasCanonicalStream(), 'the second fresh walk drained the old park');

        // The stale park is still exactly that: reachable only by its
        // owner, and drained when the owner takes it.
        $bagOld = $fp->fingerprint($old);
        $this->assertFalse(SubtreeHasher::hasCanonicalStream(), 'the owner never got its parked stream');
        $this->assertSame($bagOld, $bagFresh, 'same text must fingerprint the same, walked or served');
    }

    public function testParkIsReleasedWhenTheParkedNodeDies(): void
    {
        // The handoff holds its node weakly: a stream parked beside an
        // AST the pipeline already dropped must not resurrect it, and it
        // must report itself gone.
        $stmts = $this->parser->parseCode('<?php function w() { return 1 + 2; }');
        self::assertNotNull($stmts);
        $block = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts)[0];
        (new Normalizer('aggressive'))->normalize($block);
        $node = $block->canonical;
        $this->hasher->hash($node);
        $this->assertTrue(SubtreeHasher::hasCanonicalStream());

        unset($block, $node, $stmts);
        gc_collect_cycles();
        $this->assertFalse(SubtreeHasher::hasCanonicalStream(), 'the park outlived its node');
    }

    // ------------------------------------------------------------------
    // corpus
    // ------------------------------------------------------------------

    /**
     * Every canonical block the fixtures and the seeded generated corpus
     * mint, in the three normalization modes the pipeline runs.
     *
     * @return list<Node>
     */
    private function corpusNodes(): array
    {
        $nodes = [];
        foreach (self::corpusSources() as $source) {
            $stmts = $this->parser->parseCode($source);
            if ($stmts === null) {
                continue; // deliberate syntax-error fixtures carry no blocks
            }
            foreach (['strict', 'default', 'aggressive'] as $mode) {
                foreach ((new BlockExtractor(minSize: 1))->extract('t.php', $stmts) as $block) {
                    (new Normalizer($mode))->normalize($block);
                    $nodes[] = $block->canonical;
                }
            }
        }
        return $nodes;
    }

    /**
     * All PHP files under tests/Fixtures plus a deterministic generated
     * corpus of adversarial literals (engine-037 danger bytes, frame
     * headers that imitate clean scalars, invalid UTF-8, emoji, empty
     * strings) woven through the shapes the extractor cares about.
     *
     * @return list<string>
     */
    private static function corpusSources(): array
    {
        $sources = [];
        $root    = dirname(__DIR__, 3) . '/tests/Fixtures';
        $files   = [];
        if (is_dir($root)) {
            $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                    $files[] = $f->getPathname();
                }
            }
            sort($files);
        }
        $thisFiles = 0;
        foreach ($files as $path) {
            $code = file_get_contents($path);
            if ($code === false) {
                continue;
            }
            $sources[]    = $code;
            $thisFiles++;
        }
        self::assertGreaterThanOrEqual(20, $thisFiles, 'the fixture corpus vanished from under the pin');

        foreach (self::generatedSources() as $code) {
            $sources[] = $code;
        }
        return $sources;
    }

    /**
     * Seeded generator: same corpus on every run (deterministic pins are
     * the whole point of a differential).
     *
     * @return list<string>
     */
    private static function generatedSources(): array
    {
        $adversarial = [
            'plain', '', 'a' . "\x1F" . 'b', 'c' . "\x1E" . 'd', 'e' . "\x1D" . 'f',
            'nul' . "\x00" . 'byte', 'semi;colon', 'a;b;c', 'pipe|bar', 'hash#tag',
            '3:lookalike', "\x1D" . '3:frame-like', 'notify@x.org', "w\x1F(\x1Fx",
            "\xC3\x28", "emoji\x{1F3AF}tail", '$dollar', '${brace}', 'back\\\\slash',
            "a\nb", "quote\"inside", "0123456789:_-", str_repeat('x', 300),
        ];
        $out = [];
        mt_srand(20261122);
        for ($i = 0; $i < 48; $i++) {
            $a    = $adversarial[mt_rand(0, count($adversarial) - 1)];
            $b    = $adversarial[mt_rand(0, count($adversarial) - 1)];
            $num  = mt_rand(-9, 4000);
            $litA = self::asPhpLiteral($a);
            $litB = self::asPhpLiteral($b);
            $out[] = <<<PHP
                <?php function gen{$i}(\$items, \$flag) {
                    \$s = {$litA};
                    if (strlen(\$s . {$litB}) > {$num}) { \$s = \$s . {$litB}; }
                    \$t = [];
                    foreach (\$items as \$k => \$v) {
                        \$t[\$k] = (string) (\$v + {$num});
                        if (!\$flag) { continue; }
                        \$t["k{\$k}"] = trim("{$a}x{$b}");
                    }
                    return [\$s, \$t, array_map('intval', \$t)];
                }
                PHP;
            $out[] = <<<PHP
                <?php function other{$i}(\$x) { return $litA === \$x ? {$num} : strlen($litB); }
                PHP;
        }
        return $out;
    }

    /**
     * Render raw bytes as a double-quoted PHP literal whose VALUE is
     * exactly those bytes (hex escapes keep control bytes, invalid UTF-8
     * and quotes out of the source syntax).
     */
    private static function asPhpLiteral(string $raw): string
    {
        $escaped = '';
        $len     = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $byte    = $raw[$i];
            $ordinal = ord($byte);
            if ($byte === '"' || $byte === '\\' || $byte === '$' || $byte === '{') {
                $escaped .= '\\' . $byte;
            } elseif ($ordinal >= 0x20 && $ordinal <= 0x7E) {
                $escaped .= $byte;
            } else {
                $escaped .= '\\x' . strtoupper(bin2hex($byte));
            }
        }
        return '"' . $escaped . '"';
    }
}
