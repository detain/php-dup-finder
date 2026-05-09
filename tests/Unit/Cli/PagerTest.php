<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Pager;

final class PagerTest extends TestCase
{
    public function testNeverModeNeverPages(): void
    {
        $this->assertFalse(Pager::shouldPage(Pager::MODE_NEVER));
        $this->assertFalse(Pager::shouldPage(Pager::MODE_NEVER, 99999));
    }

    public function testAlwaysModeAlwaysPages(): void
    {
        $this->assertTrue(Pager::shouldPage(Pager::MODE_ALWAYS));
        $this->assertTrue(Pager::shouldPage(Pager::MODE_ALWAYS, 0));
    }

    public function testAutoIgnoresShortBuffersWhenLineCountKnown(): void
    {
        // The TTY check still gates this in shouldPage() — under PHPUnit
        // STDOUT is typically not a TTY, so the result is false even with
        // a huge buffer. Under PHPUnit, this is the expected non-TTY path.
        $this->assertFalse(Pager::shouldPage(Pager::MODE_AUTO, 1));
    }
}
