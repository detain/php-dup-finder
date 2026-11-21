<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Reporting\FindingId;
use Phpdup\Reporting\Slug;
use Phpdup\Util\LineRange;

final class SlugTest extends TestCase
{
    /**
     * Round-trip law: every id shape the reporters have ever been handed —
     * the current FindingId content ids and the legacy alphanumeric seeds —
     * passes through byte-identically, so no artifact filename moves.
     *
     * @return iterable<string, array{string}>
     */
    public static function unchangedIdCases(): iterable
    {
        yield 'golden F-id'            => ['F72d077a1234abcd'];
        yield 'F-id full hex alphabet' => ['F0123456789abcdef0'];
        yield 'legacy C-seed id'       => ['Cabcdef1234567890'];
        yield 'legacy X-seed id'       => ['X1234'];
        yield 'test-suite ids'         => ['TEST'];
        yield 'short lowercase id'     => ['c1'];
        yield 'underscore id'          => ['THREE_HOLE_TEST'];
        yield 'hyphen and underscore'  => ['a-B_c-9'];
        yield 'empty string'           => [''];
    }

    #[DataProvider('unchangedIdCases')]
    public function testSafeIdsRoundTripByteIdentically(string $id): void
    {
        $this->assertSame($id, Slug::safe($id));
    }

    /**
     * Every byte outside [a-zA-Z0-9_-] collapses to a single underscore —
     * byte-wise, matching the seven retired inline call sites exactly.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function projectionCases(): iterable
    {
        yield 'path with separators'   => ['src/Foo.php:10-20', 'src_Foo_php_10-20'];
        yield 'qualified name'         => ['App\\Service::run', 'App_Service__run'];
        yield 'space'                  => ['x y', 'x_y'];
        yield 'dot only'               => ['a.b.c', 'a_b_c'];
        yield 'colon hash at'          => ['C:1#2@3', 'C_1_2_3'];
        yield 'high byte is two cells' => ['café', 'caf__'];
        yield 'nul byte'               => ["a\0b", 'a_b'];
    }

    #[DataProvider('projectionCases')]
    public function testUnsafeBytesBecomeSingleUnderscores(string $id, string $expected): void
    {
        $this->assertSame($expected, Slug::safe($id));
    }

    public function testProjectionIsIdempotent(): void
    {
        $id = 'src/Foo.php:10-20';
        $once = Slug::safe($id);
        $this->assertSame($once, Slug::safe($once), 'a slug is already a fixed point');
    }

    public function testMintedFindingIdRoundTripsByteIdentically(): void
    {
        $stmts = (new AstParser())->parseCode('<?php $x;');
        $members = [
            new Block(
                file: '/app/a.php', range: new LineRange(1, 5), kind: 'function',
                namespace: null, class: null, name: 'a', ast: $stmts[0],
            ),
            new Block(
                file: '/app/b.php', range: new LineRange(10, 15), kind: 'function',
                namespace: null, class: null, name: 'b', ast: $stmts[0],
            ),
        ];
        $members[0]->structuralHash = 'structural-hash-A';
        $members[1]->structuralHash = 'structural-hash-B';

        $id = FindingId::forMembers($members, []);

        $this->assertMatchesRegularExpression('/^F[0-9a-f]{16}$/', $id, 'current legacy id shape');
        $this->assertSame($id, Slug::safe($id));
    }
}
