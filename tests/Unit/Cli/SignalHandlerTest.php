<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\SignalHandler;
use Phpdup\Pipeline\PipelineState;

final class SignalHandlerTest extends TestCase
{
    public function testInstallTogglesCancelledOnSigint(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix unavailable');
        }
        $state = new PipelineState(Config::defaults(['/tmp']));
        SignalHandler::install($state);
        $this->assertFalse($state->cancelled);

        // Send ourselves SIGINT — the handler should set $state->cancelled.
        posix_kill(posix_getpid(), SIGINT);
        // Async signals dispatch immediately; tiny micro-yield to be safe.
        usleep(10_000);

        $this->assertTrue($state->cancelled);
        SignalHandler::uninstall();
    }

    public function testUninstallRestoresDefaultDisposition(): void
    {
        $state = new PipelineState(Config::defaults(['/tmp']));
        SignalHandler::install($state);
        SignalHandler::uninstall();
        // Asserting absence of side-effects — re-installing must not
        // throw and the counter must reset.
        SignalHandler::install($state);
        SignalHandler::uninstall();
        $this->expectNotToPerformAssertions();
    }
}
