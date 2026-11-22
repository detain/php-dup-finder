<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Util\CanonicalNodePool;

final class CanonicalNodePoolTest extends TestCase
{
    public function testInternsIdenticalLeavesToSameInstance(): void
    {
        $pool = new CanonicalNodePool();
        $a = new Node\Identifier('foo');
        $b = new Node\Identifier('foo');
        $shared1 = $pool->intern($a);
        $shared2 = $pool->intern($b);
        $this->assertSame($shared1, $shared2);
        $this->assertSame(1, $pool->size());
    }

    public function testDifferentLeavesPoolSeparately(): void
    {
        $pool = new CanonicalNodePool();
        $pool->intern(new Node\Identifier('foo'));
        $pool->intern(new Node\Identifier('bar'));
        $this->assertSame(2, $pool->size());
    }

    public function testCompositesAreNotPooled(): void
    {
        $pool = new CanonicalNodePool();
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php function f() { return 1; }');
        $node = $stmts[0];
        $shared = $pool->intern($node);
        $this->assertSame($node, $shared, 'composite returns identity, not interned form');
        $this->assertSame(0, $pool->size());
    }

    public function testClearEmptiesThePool(): void
    {
        $pool = new CanonicalNodePool();
        $pool->intern(new Node\Identifier('x'));
        $pool->clear();
        $this->assertSame(0, $pool->size());
    }

    // -----------------------------------------------------------------
    // engine-008: the leaf key must be line-honest. The old key ignored
    // attributes entirely, so an interned leaf aliased the FIRST-seen
    // node's startLine/endLine/positions — the caveat that made any
    // splicing fix unsafe for per-member JSON/--apply line reporting.
    // -----------------------------------------------------------------

    public function testPositionAttributesKeepDistinctlyPlacedLeavesApart(): void
    {
        $pool = new CanonicalNodePool();
        $a = $pool->intern(new Node\Expr\Variable('x', ['startLine' => 3, 'endLine' => 3]));
        $b = $pool->intern(new Node\Expr\Variable('x', ['startLine' => 9, 'endLine' => 9]));

        $this->assertNotSame($a, $b, 'distinct-line leaves must never share an instance');
        $this->assertSame(2, $pool->size());
        $this->assertSame(3, $a->getStartLine(), 'each leaf still reports its own line');
        $this->assertSame(9, $b->getStartLine());
    }

    public function testIdenticalPositionedLeavesStillShareOneInstance(): void
    {
        $pool  = new CanonicalNodePool();
        $attrs = ['startLine' => 4, 'endLine' => 4, 'startTokenPos' => 11];
        $a = $pool->intern(new Node\Expr\Variable('x', $attrs));
        $b = $pool->intern(new Node\Expr\Variable('x', $attrs));

        $this->assertSame($a, $b, 'fully-equal position + value still dedupes');
        $this->assertSame(1, $pool->size());
    }

    public function testNonLineScalarAttributesParticipateInTheKey(): void
    {
        // Canonicaliser-stamped scalar attributes (the
        // DbOpCanonicalizer::ATTR_OP shape) are value-bearing, not
        // decoration: two leaves differing only in such an attribute
        // must not alias onto one another's stamped payload.
        $pool  = new CanonicalNodePool();
        $read  = $pool->intern(new Node\Identifier('q', ['op' => 'db.read']));
        $write = $pool->intern(new Node\Identifier('q', ['op' => 'db.write']));

        $this->assertNotSame($read, $write);
        $this->assertSame(2, $pool->size());
    }
}
