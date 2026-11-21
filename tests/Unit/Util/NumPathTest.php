<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Util\Num;
use Phpdup\Util\PathCanonical;

/**
 * phpver-fix hermeticity utilities: version-invariant JSON numbers and
 * machine-portable scan-relative paths.
 */
final class NumPathTest extends TestCase
{
    public function testJsonCollapsesWholeValuesToIntBytes(): void
    {
        $this->assertSame(1, Num::json(1.0));
        $this->assertSame(1, Num::json(0.99999));      // rounds to whole
        $this->assertSame(0, Num::json(-0.00001));     // negative whole
        $this->assertSame(0, Num::json(-0.0));
    }

    public function testJsonKeepsNonWholeValuesFloat(): void
    {
        $this->assertSame(0.8333, Num::json(0.8333333));
        $this->assertSame(0.95, Num::json(0.95));
    }

    public function testJsonClampsNonFinite(): void
    {
        // NAN has no order, so it clamps to the neutral 0, not INT_MAX.
        $this->assertSame(0, Num::json(NAN));
        $this->assertSame(PHP_INT_MAX, Num::json(INF));
        $this->assertSame(PHP_INT_MIN, Num::json(-INF));
    }

    public function testPathCanonicalStripsLongestMatchingRoot(): void
    {
        $this->assertSame('Util/a.php', PathCanonical::underRoots('/proj/src/Util/a.php', ['/proj', '/proj/src']));
        // Root order must not matter — the longest match wins.
        $this->assertSame('Util/a.php', PathCanonical::underRoots('/proj/src/Util/a.php', ['/proj/src', '/proj']));
    }

    public function testPathCanonicalIsMachinePortable(): void
    {
        $laptop = PathCanonical::underRoots('/home/sites/proj/src/A.php', ['/home/sites/proj']);
        $runner = PathCanonical::underRoots('/home/runner/work/proj/proj/src/A.php', ['/home/runner/work/proj/proj']);
        $this->assertSame($laptop, $runner);
        $this->assertSame('src/A.php', $laptop);
    }

    public function testPathCanonicalHandlesSingleFileRootAndUnrooted(): void
    {
        $this->assertSame('A.php', PathCanonical::underRoots('/proj/A.php', ['/proj/A.php']));
        // Unrooted (synthetic/unmatched) paths keep their bytes verbatim.
        $this->assertSame('bare.php', PathCanonical::underRoots('bare.php', []));
        $this->assertSame('/elsewhere/x/Foo.php', PathCanonical::underRoots('/elsewhere/x/Foo.php', ['/proj']));
    }
}
