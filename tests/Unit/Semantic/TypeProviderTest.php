<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Semantic\NullTypeProvider;
use Phpdup\Semantic\PsalmTypeProvider;

final class TypeProviderTest extends TestCase
{
    public function testNullProviderReturnsNullForEverything(): void
    {
        $p = new NullTypeProvider();
        $this->assertNull($p->typeAt('any.php', 1));
        $this->assertSame('null', $p->name());
    }

    public function testPsalmProviderReadsExpectedType(): void
    {
        $report = [
            [
                'file_path' => '/repo/src/Foo.php',
                'line_from' => 42,
                'message'   => 'Argument 1 of foo expects int|string, but mixed provided',
            ],
        ];
        $p = PsalmTypeProvider::fromArray($report);
        $this->assertSame('int|string', $p->typeAt('/repo/src/Foo.php', 42));
        $this->assertSame('psalm', $p->name());
    }

    public function testPsalmProviderReadsExpectedReturnType(): void
    {
        $report = [
            [
                'file_path' => 'a.php',
                'line_from' => 7,
                'message'   => 'Cannot return mixed where int is expected',
            ],
        ];
        $p = PsalmTypeProvider::fromArray($report);
        $this->assertSame('int', $p->typeAt('a.php', 7));
    }

    public function testPsalmProviderReturnsNullForUnknownLocations(): void
    {
        $p = PsalmTypeProvider::fromArray([]);
        $this->assertNull($p->typeAt('a.php', 1));
    }
}
