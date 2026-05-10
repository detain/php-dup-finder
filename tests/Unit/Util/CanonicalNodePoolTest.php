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
}
