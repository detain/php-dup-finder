<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Util\ControlText;

/**
 * output-011/047 boundary: reject/quote each control byte of the forging
 * class (\0, \n, \r, \x1b). Tabs and spaces are legal filename bytes and
 * must pass through both arms untouched.
 */
final class ControlTextTest extends TestCase
{
    /** @return array<string,array{string,int}> */
    public static function forgeByteProvider(): array
    {
        return [
            'NUL'        => ["dir\x00/file.php", 0x00],
            'newline'    => ["dir\n/file.php", 0x0A],
            'carriage'   => ["dir\r/file.php", 0x0D],
            'escape'     => ["dir\x1b[31m.php", 0x1B],
        ];
    }

    /** @dataProvider forgeByteProvider */
    public function testDetectsEachForgeByte(string $evil, int $ord): void
    {
        $this->assertTrue(ControlText::containsForgeBytes($evil));
        $this->assertSame($ord, ControlText::firstForgeByte($evil));
    }

    /** @dataProvider forgeByteProvider */
    public function testRejectThrowsNamedDiagnosticPerByte(string $evil, int $ord): void
    {
        try {
            ControlText::reject($evil, 'TestSurface', 'member file path');
            $this->fail('expected a named refusal for ' . dechex($ord));
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringStartsWith('phpdup: TestSurface', $message);
            $this->assertStringContainsString('member file path', $message);
            $this->assertStringContainsString(strtoupper(dechex($ord)), $message, 'names the offending byte');
            $this->assertStringContainsString(ControlText::forgeByteName($ord), $message);
            $this->assertStringNotContainsString("\n", $message, 'the diagnostic itself must not carry a raw forge byte');
        }
    }

    public function testCleanPathsPass(): void
    {
        foreach ([
            '',
            'ordinary/path.php',
            'with space/file name.php',
            "with\ttab/file.php",
            'ünïcode/файл.php',
        ] as $safe) {
            ControlText::reject($safe, 'TestSurface', 'member file path');
            $this->assertNull(ControlText::firstForgeByte($safe));
            $this->assertFalse(ControlText::containsForgeBytes($safe));
            $this->assertSame($safe, ControlText::substitute($safe), 'clean text is byte-identical through the scrub');
        }
    }

    /** @dataProvider forgeByteProvider */
    public function testSubstituteNeutralizesEveryForgeByte(string $evil, int $ord): void
    {
        $scrubbed = ControlText::substitute($evil);
        $this->assertFalse(ControlText::containsForgeBytes($scrubbed));
        $this->assertStringContainsString(ControlText::SUBSTITUTE, $scrubbed);
        $this->assertSame(
            strlen($evil) + 2,
            strlen($scrubbed),
            'one 3-byte marker replaces the single offender byte, nothing else moves',
        );
    }

    public function testFirstForgeByteReportsTheEarliestOffender(): void
    {
        $this->assertSame(0x0D, ControlText::firstForgeByte("a\rb\nc\x00"));
    }

    public function testVisibleRendersForgeBytesAsPrintableHex(): void
    {
        $this->assertSame('dir\x0Afile', ControlText::visible("dir\nfile"));
        $this->assertSame('keep space and	tab', ControlText::visible("keep space and\ttab"));
    }
}
