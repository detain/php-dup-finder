<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\FindingId;
use Phpdup\Util\LineRange;

/**
 * Item 4.4 — the DECIDED canonical finding identity ([f11]§4.4-design,
 * plan §5e): 'F' + first 16 hex of sha256(implode('|', sorted per-member
 * sha256(file . '|' . structuralHash))).
 *
 * The contract that makes ids safe for caches, baselines and MCP
 * anchoring (hazard-4): id = f(content multiset) — invariant under
 * member order, block ids, line ranges; volatile under content edits
 * and member-set growth (fail-open resurfacing).
 */
final class FindingIdTest extends TestCase
{
    private function block(string $file, string $hash, string $id = 'b1', int $start = 1, int $end = 5): Block
    {
        $b = new Block(
            file: $file,
            range: new LineRange($start, $end),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );
        $b->id = $id;
        $b->structuralHash = $hash;
        return $b;
    }

    public function testMatchesTheDecidedRecipeVector(): void
    {
        // Independently re-derived from the recipe text — a permanent pin
        // so an accidental ingredient change (separators, digest order,
        // truncation) fails loudly.
        $members = [
            $this->block('a.php', 'hash-a'),
            $this->block('b.php', 'hash-b'),
        ];

        $perMember = [
            hash('sha256', 'a.php|hash-a'),
            hash('sha256', 'b.php|hash-b'),
        ];
        sort($perMember, SORT_STRING);
        $expected = 'F' . substr(hash('sha256', implode('|', $perMember)), 0, 16);

        $this->assertSame($expected, FindingId::forMembers($members));
    }

    public function testShapeIsFPlusSixteenHex(): void
    {
        $id = FindingId::forMembers([$this->block('a.php', 'h')]);
        $this->assertMatchesRegularExpression('/^F[0-9a-f]{16}$/', $id);
    }

    public function testInvariantUnderMemberOrder(): void
    {
        $members = [
            $this->block('a.php', 'hash-a', 'b001'),
            $this->block('b.php', 'hash-b', 'b002'),
            $this->block('c.php', 'hash-c', 'b003'),
        ];
        $shuffled = [$members[2], $members[0], $members[1]];

        $this->assertSame(
            FindingId::forMembers($members),
            FindingId::forMembers($shuffled),
            'arrival order of members must never move the id',
        );
    }

    public function testInvariantUnderBlockIdRenumberingAndLineShifts(): void
    {
        $first = $this->block('a.php', 'hash-a', 'b001', 10, 20);
        $second = $this->block('a.php', 'hash-a', 'b001', 10, 20);
        $renumbered = $this->block('a.php', 'hash-a', 'b900', 345, 355);

        $this->assertSame(
            FindingId::forMembers([$first, $second]),
            FindingId::forMembers([$renumbered, $second]),
            'scan-index block ids and positions are not identity ingredients',
        );
    }

    public function testVolatileUnderContentEdit(): void
    {
        $members = [$this->block('a.php', 'hash-a'), $this->block('b.php', 'hash-b')];
        $before = FindingId::forMembers($members);

        // Blocks are mutable objects: capture first, then edit in place.
        $members[1]->structuralHash = 'hash-b-v2';

        $this->assertNotSame($before, FindingId::forMembers($members));
    }

    public function testVolatileUnderMemberSetGrowth(): void
    {
        $members = [$this->block('a.php', 'hash-a'), $this->block('b.php', 'hash-b')];
        $grown = $members;
        $grown[] = $this->block('c.php', 'hash-c');

        $this->assertNotSame(
            FindingId::forMembers($members),
            FindingId::forMembers($grown),
            'a changed member set resurfaces: acceptance must not ride along (fail-open)',
        );
    }

    public function testMultisetSemanticsDuplicateMembersCount(): void
    {
        // Two identical clones in one file share file|hash tokens; the id
        // must distinguish a 2-member from a 3-member multiset.
        $pair = [$this->block('a.php', 'h'), $this->block('a.php', 'h')];
        $triple = [$this->block('a.php', 'h'), $this->block('a.php', 'h'), $this->block('a.php', 'h')];

        $this->assertNotSame(FindingId::forMembers($pair), FindingId::forMembers($triple));
    }

    public function testForClusterDelegatesToMemberSet(): void
    {
        $members = [$this->block('a.php', 'hash-a'), $this->block('b.php', 'hash-b')];
        $cluster = new Cluster('legacy-id', $members, 0.9, false);

        $this->assertSame(FindingId::forMembers($members), FindingId::for($cluster));
    }

    public function testScanRootsMakeIdsMachinePortable(): void
    {
        // phpver-fix: the same cluster seen through two checkout prefixes
        // must mint ONE id once the scan roots are threaded — and the old
        // host binding must be exactly what the roots remove.
        $laptop = [
            $this->block('/home/sites/proj/src/Util/a.php', 'h1'),
            $this->block('/home/sites/proj/src/Util/b.php', 'h2'),
        ];
        $runner = [
            $this->block('/home/runner/work/proj/proj/src/Util/a.php', 'h1'),
            $this->block('/home/runner/work/proj/proj/src/Util/b.php', 'h2'),
        ];

        $this->assertNotSame(
            FindingId::forMembers($laptop),
            FindingId::forMembers($runner),
            'without roots the pre-fix host binding persists (guard against silent fallback)',
        );
        $this->assertSame(
            FindingId::forMembers($laptop, ['/home/sites/proj']),
            FindingId::forMembers($runner, ['/home/runner/work/proj/proj']),
        );
        // Root-order independence (longest match wins) must not move ids.
        $this->assertSame(
            FindingId::forMembers($laptop, ['/home/sites/proj']),
            FindingId::forMembers($laptop, ['/other', '/home/sites/proj']),
        );
    }
}
