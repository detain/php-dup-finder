<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Architecture\Analyzers;

use PHPUnit\Framework\TestCase;
use Phpdup\Architecture\Analyzers\SolidAnalyzer;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;

final class SolidAnalyzerTest extends TestCase
{
    public function testAnalyzeDoesNotFatal(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function testFn() { }');
        $this->assertNotNull($stmts);

        $block = new Block(
            file: 'virtual.php',
            range: new LineRange(1, 2),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'testFn',
            ast: $stmts[0],
        );

        $cluster = new Cluster('C1', [$block], 0.9, false);

        $analyzer = new SolidAnalyzer();
        $result = $analyzer->analyze($cluster);

        $this->assertCount(0, $result);
    }
}
