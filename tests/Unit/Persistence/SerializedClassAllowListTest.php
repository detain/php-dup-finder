<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Persistence\SerializedClassAllowList;

final class SerializedClassAllowListTest extends TestCase
{
    public function testBlockObjectClassesContainsExpectedTypes(): void
    {
        $list = SerializedClassAllowList::blockObjectClasses();
        self::assertContains(\Phpdup\Clustering\Cluster::class, $list);
        self::assertContains(\Phpdup\Extraction\Block::class, $list);
        self::assertContains(\Phpdup\Refactor\Hole::class, $list);
        self::assertContains(\Phpdup\Util\LineRange::class, $list);
    }

    public function testParserClassesIncludesNodeClasses(): void
    {
        $list = SerializedClassAllowList::parserClasses();
        // Sanity-check that the enumeration found at least the most
        // common node classes — without these the cache load path
        // would always miss.
        self::assertNotEmpty($list);
        self::assertContains(\PhpParser\Node\Stmt\Function_::class, $list);
        self::assertContains(\PhpParser\Node\Expr\Variable::class, $list);
    }

    /**
     * engine-S23b interplay: TokenCache decodes its blob through
     * CacheBlob::decode(..., parserClasses(), Token::class). The element
     * class MUST be a member of the allow-list — if the enumeration ever
     * stops admitting it, every put() silently voids the cache (each
     * get() rejects its own honest payload at the instance screen).
     */
    public function testParserClassesAdmitsTokenCacheElementClass(): void
    {
        $list = SerializedClassAllowList::parserClasses();
        self::assertContains(\PhpParser\Token::class, $list);

        // Round-trip over the real decode path with the parser list:
        // an honest token stream survives screening.
        $payload = serialize([new \PhpParser\Token(T_OPEN_TAG, '<?php ', 1, 0)]);
        $decoded = \Phpdup\Parsing\CacheBlob::decode($payload, $list, \PhpParser\Token::class);
        self::assertNotNull($decoded, 'allow-list and TokenCache element contract must agree');
        self::assertInstanceOf(\PhpParser\Token::class, $decoded[0]);
    }

    public function testBlockCacheClassesIsUnion(): void
    {
        $list = SerializedClassAllowList::blockCacheClasses();
        self::assertContains(\Phpdup\Clustering\Cluster::class, $list);
        self::assertContains(\PhpParser\Node\Stmt\Function_::class, $list);
    }

    public function testUnserializeRejectsDisallowedClassAsIncomplete(): void
    {
        $allow = SerializedClassAllowList::blockObjectClasses();
        // Serialize an arbitrary class that is NOT in the allow-list.
        $blob = serialize(new \stdClass());
        $decoded = unserialize($blob, ['allowed_classes' => $allow]);
        self::assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
    }
}
