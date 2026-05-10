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
