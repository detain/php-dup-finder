<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;

/**
 * Item 7.1: the tier arms ONLY when a non-empty roster names 'text'.
 * The empty roster means 'every implicit PHP kind' (the historical
 * default) and must NEVER be read as 'all kinds incl. text' — that
 * mis-read would flip the default posture of every run.
 */
final class ConfigTokenTierTest extends TestCase
{
    public function testDefaultConfigNeverArms(): void
    {
        $this->assertFalse(Config::defaults(['/tmp'])->tokenTierArmed());
    }

    public function testEmptyRosterNeverArms(): void
    {
        $cfg = Config::defaults(['/tmp'])->withOverrides(['allowed_kinds' => []]);
        $this->assertFalse($cfg->tokenTierArmed(), 'empty roster = implicit PHP default, not an arming');
    }

    public function testTextRosterArms(): void
    {
        $cfg = Config::defaults(['/tmp'])->withOverrides(['allowed_kinds' => ['text']]);
        $this->assertTrue($cfg->tokenTierArmed());
    }

    public function testMixedRosterArms(): void
    {
        $cfg = Config::defaults(['/tmp'])->withOverrides(['allowed_kinds' => ['method', 'function', 'text']]);
        $this->assertTrue($cfg->tokenTierArmed());
    }

    public function testPhpOnlyRostersStayDisarmed(): void
    {
        foreach ([['method'], ['function', 'class'], ['html_script'], ['script'], ['fragment']] as $roster) {
            $cfg = Config::defaults(['/tmp'])->withOverrides(['allowed_kinds' => $roster]);
            $this->assertFalse($cfg->tokenTierArmed(), implode(',', $roster) . ' must not arm the tier');
        }
    }
}
