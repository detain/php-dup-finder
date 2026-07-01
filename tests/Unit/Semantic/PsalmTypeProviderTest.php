<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use Phpdup\Semantic\PsalmTypeProvider;

final class PsalmTypeProviderTest extends TestCase
{
    public function testConstructorDoesNotFatal(): void
    {
        $provider = new PsalmTypeProvider([]);
        $this->assertSame('psalm', $provider->name());
    }

    public function testFromArrayDoesNotFatal(): void
    {
        $report = [
            [
                'file_path' => '/repo/src/Foo.php',
                'line_from' => 10,
                'message'   => 'Argument 1 of bar expects int, but string provided',
            ],
        ];

        $provider = PsalmTypeProvider::fromArray($report);
        $this->assertSame('psalm', $provider->name());
        $this->assertSame('int', $provider->typeAt('/repo/src/Foo.php', 10));
    }

    public function testTypeAtReturnsNullForUnknownLocation(): void
    {
        $provider = new PsalmTypeProvider([]);
        $this->assertNull($provider->typeAt('nonexistent.php', 1));
    }
}
