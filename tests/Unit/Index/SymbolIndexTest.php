<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\SymbolIndex;
use Phpdup\Util\LineRange;

/**
 * Item 3.4 (S4): the duplicate-symbol index contracts. Three routes
 * (name / twin-class / file-basename), each with its own inclusion law,
 * the magic-name stop-list, the determinism ceilings, and — above all —
 * insertion-order independence: the same corpus must mint the same
 * forced-pair list regardless of how blocks arrive (the §2.4 law the F3
 * filter obeys; the forced stream shares it).
 */
final class SymbolIndexTest extends TestCase
{
    private function block(string $id, string $file, ?string $name, ?string $class = null, string $kind = 'function'): Block
    {
        $b = new Block(
            file: $file,
            range: new LineRange(3, 9),
            kind: $kind,
            namespace: null,
            class: $class,
            name: $name,
            ast: new Node\Stmt\Return_(new Node\Scalar\String_($id)),
        );
        $b->id = $id;
        $b->structuralHash = 'h_' . $id;
        $b->ngramBag = ['t_' . $id => 1];
        $b->size = 5;
        return $b;
    }

    /** @param list<Block> $blocks */
    private function indexWith(array $blocks): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        return $index;
    }

    private function build(BlockIndex $index): SymbolIndex
    {
        $symbols = new SymbolIndex();
        $symbols->build($index);
        return $symbols;
    }

    /** @param list<array{0:string,1:string,2:string}> $pairs */
    private function hasPair(array $pairs, string $lo, string $hi, string $reason): bool
    {
        foreach ($pairs as $p) {
            if ($p[0] === $lo && $p[1] === $hi && $p[2] === $reason) {
                return true;
            }
        }
        return false;
    }

    public function testNameRoutePairsAcrossOwnersCanonicalised(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('zzz_id', '/p/vps_create.php', 'create_vps'),
            $this->block('aaa_id', '/q/vps_create.php', 'create_vps'),
        ]));
        $pairs = $symbols->forcedPairs();
        self::assertCount(1, $pairs);
        // canonical lo<hi despite insertion order:
        self::assertSame(['aaa_id', 'zzz_id', SymbolIndex::REASON_NAME], $pairs[0]);
    }

    public function testNameMatchingIsCaseInsensitive(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/x.php', 'Load'),
            $this->block('b', '/q/x.php', 'load'),
        ]));
        self::assertTrue($this->hasPair($symbols->forcedPairs(), 'a', 'b', SymbolIndex::REASON_NAME));
    }

    public function testSameOwnerSameNameNeverPairs(): void
    {
        // One class file declaring `go` twice is one slot, not a family.
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/X.php', 'go', 'K\X', 'method'),
            $this->block('b', '/p/X.php', 'go', 'K\X', 'method'),
        ]));
        self::assertSame([], $symbols->forcedPairs());
    }

    public function testMagicNamesAreStoppedFromNameAndBasenameRoutes(): void
    {
        // Different short class names: the class route cannot fire either
        // (the keys differ) — magic names meet ONLY through twin classes.
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/Alpha.php', '__invoke', 'Alpha\Clock', 'method'),
            $this->block('b', '/q/Beta.php', '__invoke', 'Beta\Logger', 'method'),
        ]));
        self::assertSame([], $symbols->forcedPairs());
    }

    public function testTwinClassRouteCarriesMagicNamesAndRequiresDifferentQualified(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/RateLimitException.php', '__construct', 'Alpha\RateLimitException', 'method'),
            $this->block('b', '/q/RateLimitException.php', '__construct', 'Beta\RateLimitException', 'method'),
            // same qualified class, same method — one slot again:
            $this->block('c', '/r/RateLimitException.php', '__construct', 'Alpha\RateLimitException', 'method'),
        ]));
        $pairs = $symbols->forcedPairs();
        self::assertTrue($this->hasPair($pairs, 'a', 'b', SymbolIndex::REASON_CLASS));
        self::assertFalse($this->hasPair($pairs, 'a', 'c', SymbolIndex::REASON_CLASS), 'same qualified class is one site');
        self::assertTrue($this->hasPair($pairs, 'b', 'c', SymbolIndex::REASON_CLASS), 'Beta vs Alpha qualified: twins again');
    }

    public function testTwinClassShortNameIgnoresNamespaceDepth(): void
    {
        // Magic method name keeps the NAME route out, so any pair here can
        // only come from the class route proving short-name matching across
        // namespace depths.
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/X.php', '__call', 'Deep\A\Widget', 'method'),
            $this->block('b', '/q/X.php', '__call', 'B\Widget', 'method'),
        ]));
        self::assertTrue($this->hasPair($symbols->forcedPairs(), 'a', 'b', SymbolIndex::REASON_CLASS));
    }

    public function testBasenameRoutePairsDifferentSymbolsInSameBasenameFiles(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/x/tools/sync.php', 'fetch_page'),
            $this->block('b', '/y/tools/sync.php', 'push_page'),
        ]));
        self::assertTrue($this->hasPair($symbols->forcedPairs(), 'a', 'b', SymbolIndex::REASON_BASENAME));
    }

    public function testUnnamedBlocksNeverEnter(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/x/sync.php', null, null, 'foreach'),
            $this->block('b', '/y/sync.php', null, null, 'foreach'),
        ]));
        self::assertSame([], $symbols->forcedPairs());
    }

    public function testNameRouteWinsDedupOverBasenameRoute(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/x/tools/vps_create.php', 'create_vps'),
            $this->block('b', '/y/tools/vps_create.php', 'create_vps'),
        ]));
        $pairs = $symbols->forcedPairs();
        self::assertCount(1, $pairs, 'one pair, never two routes double-feeding it');
        self::assertSame(SymbolIndex::REASON_NAME, $pairs[0][2]);
    }

    public function testSiteCapDropsOverCapGroupWhole(): void
    {
        $blocks = [];
        for ($i = 0; $i < 13; $i++) { // 13 > MAX_GROUP_SITES (12)
            $blocks[] = $this->block(sprintf('toarray_%02d', $i), sprintf('/p%d/Thing.php', $i), 'toArray', 'T' . $i, 'method');
        }
        // distinct owners => distinct sites; name route group would be C(13,2)=78
        $symbols = $this->build($this->indexWith($blocks));
        self::assertSame([], $symbols->forcedPairs(), 'an over-cap group is dropped WHOLE, never sliced');
    }

    public function testExactlyCapSitesSurvive(): void
    {
        $blocks = [];
        for ($i = 0; $i < 12; $i++) {
            $blocks[] = $this->block(sprintf('toarray_%02d', $i), sprintf('/p%d/Thing.php', $i), 'toArray', 'T' . $i, 'method');
        }
        $symbols = $this->build($this->indexWith($blocks));
        self::assertSame(66, $symbols->countForcedPairs(), '12 sites => C(12,2) pairs');
    }

    public function testBlockCeilingDropsDenseBasenameGroup(): void
    {
        $blocks = [];
        // 3 files (sites OK) x 11 same-name functions each = 33 pooled blocks > 32.
        for ($f = 0; $f < 3; $f++) {
            for ($i = 0; $i < 11; $i++) {
                $blocks[] = $this->block(sprintf('%d_%02d', $f, $i), sprintf('/p%d/shared.php', $f), 'sync_point', null, 'function');
            }
        }
        $symbols = $this->build($this->indexWith($blocks));
        self::assertSame([], $symbols->forcedPairs(), 'cross-product flood group drops whole (both routes)');
    }

    public function testPairListIsDeterministicAcrossInsertionOrder(): void
    {
        $blocks = [
            $this->block('m1', '/a/tools/sync.php', 'fetch_all'),
            $this->block('m2', '/b/tools/sync.php', 'fetch_all'),
            $this->block('m3', '/c/other.php', 'fetch_all'),
            $this->block('m4', '/a/tools/sync.php', 'push_all'),
            $this->block('m5', '/b/tools/sync.php', 'drain_all'),
        ];
        $forward = $this->build($this->indexWith($blocks))->forcedPairs();
        $reverse = $this->build($this->indexWith(array_reverse($blocks)))->forcedPairs();
        self::assertSame($forward, $reverse, 'insertion order must not move the stream');
        self::assertNotEmpty($forward);
    }

    public function testSortOrderIsReasonThenIds(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('d1', '/z/aaa.php', 'solo_z'),            // basename pair with d2
            $this->block('d2', '/w/aaa.php', 'solo_w'),
            $this->block('c1', '/p/win.php', 'same_name', 'N1', 'method'),
            $this->block('c2', '/q/win.php', 'same_name', 'N2', 'method'),
        ]));
        $pairs = $symbols->forcedPairs();
        $reasons = array_values(array_unique(array_map(static fn(array $p): string => $p[2], $pairs)));
        // sorted globally: 'class' < 'name' by the (reason, lo, hi) usort
        $sorted = $pairs;
        usort($sorted, static fn(array $a, array $b): int => [$a[2], $a[0], $a[1]] <=> [$b[2], $b[0], $b[1]]);
        self::assertSame($sorted, $pairs);
        self::assertContains(SymbolIndex::REASON_BASENAME, $reasons);
        self::assertContains(SymbolIndex::REASON_NAME, $reasons);
    }

    public function testArmedAndCountersAgree(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/x.php', 'load'),
            $this->block('b', '/q/x.php', 'load'),
        ]));
        self::assertTrue($symbols->armed());
        self::assertSame(1, $symbols->countForcedPairs());

        $empty = $this->build($this->indexWith([$this->block('a', '/p/x.php', 'load')]));
        self::assertFalse($empty->armed());
        self::assertSame(0, $empty->countForcedPairs());
    }

    public function testBlocksByIdResolvesEveryForcedEndpoint(): void
    {
        $symbols = $this->build($this->indexWith([
            $this->block('a', '/p/alpha.php', 'load'),
            $this->block('b', '/q/alpha.php', 'load'),
            $this->block('c', '/r/other.php', 'no_partner_here', 'Own\K', 'method'),
        ]));
        $byId = $symbols->blocksById();
        foreach ($symbols->forcedPairs() as [$lo, $hi]) {
            self::assertArrayHasKey($lo, $byId);
            self::assertArrayHasKey($hi, $byId);
        }
        // The map is the full symbol-bearing universe, not just paired
        // endpoints — the analyzer resolves names/locations through it.
        self::assertArrayHasKey('c', $byId);
    }

    public function testRouteLabelVocabulary(): void
    {
        self::assertSame('function-name', SymbolIndex::routeLabel(SymbolIndex::REASON_NAME));
        self::assertSame('twin-class', SymbolIndex::routeLabel(SymbolIndex::REASON_CLASS));
        self::assertSame('file-basename', SymbolIndex::routeLabel(SymbolIndex::REASON_BASENAME));
        self::assertSame('future', SymbolIndex::routeLabel('future'));
    }
}
