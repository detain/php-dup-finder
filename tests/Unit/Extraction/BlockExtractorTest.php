<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;

final class BlockExtractorTest extends TestCase
{
    public function testWithoutFilterAllKindsAreEmitted(): void
    {
        $blocks = $this->extract(<<<'PHP'
        <?php
        function alpha() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        class Repo {
            public function find($id) { if ($id) { return $id; } else { return null; } }
        }
        PHP);

        $kinds = array_unique(array_map(fn($b) => $b->kind, $blocks));
        sort($kinds);
        $this->assertContains('function', $kinds);
        $this->assertContains('method', $kinds);
        $this->assertContains('if', $kinds);
        $this->assertContains('for', $kinds);
    }

    public function testFilterRetainsOnlySpecifiedKinds(): void
    {
        $blocks = $this->extract(<<<'PHP'
        <?php
        function alpha() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        class Repo {
            public function find($id) { if ($id) { return $id; } else { return null; } }
        }
        PHP, allowedKinds: ['method', 'function']);

        $kinds = array_unique(array_map(fn($b) => $b->kind, $blocks));
        $this->assertEqualsCanonicalizing(['function', 'method'], $kinds);
    }

    public function testEmptyFilterListMeansAccept(): void
    {
        $blocks = $this->extract(<<<'PHP'
        <?php
        function f() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        PHP, allowedKinds: []);

        $this->assertNotEmpty($blocks);
    }

    public function testAllKindsConstantCoversEveryClassifyKindOutput(): void
    {
        $expected = [
            'function', 'method', 'closure', 'arrow',
            'if', 'for', 'foreach', 'while', 'do',
            'try', 'switch', 'match',
        ];
        $this->assertEqualsCanonicalizing($expected, BlockExtractor::ALL_KINDS);
    }

    /**
     * @param list<string> $allowedKinds
     * @return list<\Phpdup\Extraction\Block>
     */
    private function extract(string $code, array $allowedKinds = []): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];
        return (new BlockExtractor(minSize: 1, maxSize: 2000, allowedKinds: $allowedKinds))
            ->extract('virtual.php', $ast);
    }
}
