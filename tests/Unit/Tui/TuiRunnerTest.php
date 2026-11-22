<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Tui;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Tui\PhpdupModel;
use Phpdup\Tui\TuiRunner;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Cli\Theme;

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

    /**
     * once-S07 (php-dup-finder-once-007) full cycle: a live model whose
     * pipeline halts must drive runWithModel() to exit 1 — the old body
     * returned 0 unconditionally, so a crashed TUI scored as success.
     * Driven in-process the way sugar-core's own ProgramTest does:
     * socketpair input, memory output, shared event loop, 'q' after the
     * pump chain has halted, hard stop-timer as the never-hang net.
     */
    public function testRunWithModelReturnsOneAfterPipelineHalt(): void
    {
        $model = $this->driveLiveTui(shouldThrow: true);

        $this->assertNotNull($model->pipelineError, 'halt surfaced on the model');
        $this->assertSame(1, $model->exitCode());
    }

    /**
     * Counterpart arm: a live run that completes (or is quit while healthy)
     * keeps exit 0 — the halt arm above is not a blanket non-zero change.
     */
    public function testRunWithModelReturnsZeroOnCleanRun(): void
    {
        $model = $this->driveLiveTui(shouldThrow: false);

        $this->assertNull($model->pipelineError);
        $this->assertSame(0, $model->exitCode());
    }

    /**
     * Boot the real Program through TuiRunner::runWithModel() with piped IO,
     * let the pump chain run to either a halt or exhaustion, then send `q`.
     * Returns the halted/completed model together with the int status.
     *
     * @return array{0: PhpdupModel, 1: int}
     */
    private function driveLiveTui(bool $shouldThrow): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$input, $inputWriter] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);

        $loop = new StreamSelectLoop();
        $factory = static function () use ($shouldThrow): array {
            $gen = $shouldThrow
                ? (static function (): \Generator {
                    yield Stage::Scanning;
                    throw new \RuntimeException('TED budget blown');
                })()
                : (static function (): \Generator {
                    yield Stage::Scanning;
                })();
            return [$gen, new PipelineState(Config::defaults([__DIR__]))];
        };
        $model = (new TuiRunner())->buildLiveModel('plain', $factory);

        // 0.05s leaves room for the 0.0-delay pump ticks to reach the throw
        // (or exhaustion) before quit; the 5s net guarantees a return.
        $loop->addTimer(0.05, static function () use ($inputWriter): void {
            fwrite($inputWriter, 'q');
        });
        $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });

        try {
            $status = (new TuiRunner())->runWithModel(
                $model,
                useAltScreen: false,
                loop: $loop,
                input: $input,
                output: $output,
            );
        } finally {
            fclose($input);
            fclose($inputWriter);
            fclose($output);
        }

        $this->assertSame($model->exitCode(), $status, 'runner status is the model exit code');
        return [$model, $status];
    }
}
