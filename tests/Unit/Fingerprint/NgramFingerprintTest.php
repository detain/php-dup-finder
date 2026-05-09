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
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode($code);
        $blocks = $extractor->extract('test.php', $stmts);
        (new Normalizer($mode))->normalize($blocks[0]);
        return (new NgramFingerprint(5))->fingerprint($blocks[0]->canonical);
    }
}
