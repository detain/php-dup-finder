<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Phpdup\Util\AstSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Item 7.2b (serializer leg of the mission): raw text hashes AS raw text.
 * The identity law is three-fold — (a) an InlineHTML run's bytes ride the
 * token stream verbatim (no HTML parsing, no entity decoding, no
 * whitespace forgiveness); (b) the same value always mints the same
 * stream, in any walk, across runs; (c) near-equals are NOT equals: the
 * escaped form &#104;&#101;&#108;&#108;&#111; never shares a token with
 * <h1> even though a browser renders them alike.
 */
final class AstSerializerRawTextTest extends TestCase
{
    /**
     * @return list<Node\Stmt>
     */
    private function parse(string $code): array
    {
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        self::assertIsArray($stmts);
        return $stmts;
    }

    public function testRawTextBytesRideVerbatimAndDistinguishEscapeForms(): void
    {
        $markup = $this->parse("<h1>Hi</h1>\n<script>go();</script>\n");
        $escaped = $this->parse("<h1>Hi</h1>\n<script>go();</script>\n");
        // Same bytes → same stream (identity across walks).
        self::assertSame(AstSerializer::tokens($markup[0]), AstSerializer::tokens($escaped[0]));

        // Different bytes that RENDER alike → different streams. Build the
        // pair the mission names: '<h1>' vs its decimal-entity form.
        $entity = new Stmt\InlineHTML('&#104;&#101;&#108;&#108;&#111;');
        $plain = new Stmt\InlineHTML('<h1>');
        self::assertNotSame(AstSerializer::tokens($entity), AstSerializer::tokens($plain));
        // And the entity bytes are there verbatim (not decoded away):
        $found = false;
        foreach (AstSerializer::tokens($entity) as $t) {
            if (str_contains($t, '&#104;')) {
                $found = true;
            }
        }
        self::assertTrue($found, 'raw bytes must survive into the token stream');
    }

    public function testWhitespaceIsContent(): void
    {
        $a = new Stmt\InlineHTML("var x = 1;\n");
        $b = new Stmt\InlineHTML("var  x  =  1;\n");
        self::assertNotSame(AstSerializer::tokens($a), AstSerializer::tokens($b), 'JS whitespace is semantic-adjacent text: never folded');
    }

    public function testNodeCountIsStableForRawRuns(): void
    {
        $long = new Stmt\InlineHTML(str_repeat('blob', 5000));
        $short = new Stmt\InlineHTML('blob');
        self::assertSame(AstSerializer::nodeCount($long), AstSerializer::nodeCount($short), 'a text run is ONE node however long — the fold weight is separate (BlockExtractor::sequenceWeight)');
    }
}
