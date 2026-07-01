<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;
use Phpdup\Semantic\ControlFlowGraph;

final class ControlFlowGraphTest extends TestCase
{
    public function testBuildFromSimpleFunctionDoesNotFatal(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function f($items) { foreach ($items as $i) { if ($i > 0) { return $i; } } }');
        $this->assertNotNull($stmts);
        $this->assertCount(1, $stmts);

        $cfg = new ControlFlowGraph();
        $result = $cfg->summarize($stmts[0]);

        $this->assertCount(5, $result);
        $this->assertArrayHasKey('branches', $result);
        $this->assertArrayHasKey('loops', $result);
        $this->assertArrayHasKey('returns', $result);
        $this->assertArrayHasKey('throws', $result);
        $this->assertArrayHasKey('catches', $result);
        $this->assertSame(1, $result['loops']);
        $this->assertSame(1, $result['branches']);
        $this->assertSame(1, $result['returns']);
        $this->assertSame(0, $result['throws']);
        $this->assertSame(0, $result['catches']);
    }
}
