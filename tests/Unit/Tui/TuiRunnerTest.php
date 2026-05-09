<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Tui\TuiRunner;
use SugarCraft\Kit\Theme;

final class TuiRunnerTest extends TestCase
{
    public function testKnownThemesIncludesAllNamedPresets(): void
    {
        $this->assertSame(
            ['ansi', 'plain', 'charm', 'dracula', 'nord', 'catppuccin'],
            TuiRunner::knownThemes(),
        );
    }

    public function testResolveThemeReturnsThemeForEachKnownName(): void
    {
        foreach (TuiRunner::knownThemes() as $name) {
            $this->assertInstanceOf(Theme::class, TuiRunner::resolveTheme($name), "theme {$name}");
        }
    }

    public function testResolveThemeFallsBackToAnsiForUnknownName(): void
    {
        $this->assertInstanceOf(Theme::class, TuiRunner::resolveTheme('made-up'));
    }

    public function testResolveThemeIsCaseInsensitive(): void
    {
        $this->assertInstanceOf(Theme::class, TuiRunner::resolveTheme('DRACULA'));
        $this->assertInstanceOf(Theme::class, TuiRunner::resolveTheme('Catppuccin'));
    }
}
