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
        [$model, $status] = $this->driveLiveTui(shouldThrow: true);

        $this->assertNotNull($model->pipelineError, 'halt surfaced on the model');
        $this->assertSame('TED budget blown', $model->pipelineError);
        $this->assertSame(1, $model->exitCode());
        $this->assertSame(1, $status, 'runWithModel propagates the halt as exit 1');
    }

    /**
     * Counterpart arm: a live run that completes (or is quit while healthy)
     * keeps exit 0 — the halt arm above is not a blanket non-zero change.
     */
    public function testRunWithModelReturnsZeroOnCleanRun(): void
    {
        [$model, $status] = $this->driveLiveTui(shouldThrow: false);

        $this->assertNull($model->pipelineError);
        $this->assertSame(0, $model->exitCode());
        $this->assertSame(0, $status);
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

    /**
     * once-S72 A-21 acceptance (host half): booting a live model through
     * buildLiveModel costs EXACTLY ONE iterator-factory call — the seed pair
     * is adopted, never thrown away.
     */
    public function testBuildLiveModelConstructsTheIteratorExactlyOnce(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array {
            $calls++;
            return [
                (static function (): \Generator { yield \Phpdup\Pipeline\Stage::Scanning; })(),
                new \Phpdup\Pipeline\PipelineState(\Phpdup\Cli\Config::defaults([__DIR__])),
            ];
        };

        $model = (new TuiRunner())->buildLiveModel('plain', $factory);
        $model->init();

        $this->assertSame(1, $calls, 'one factory call for the whole boot (PP-1 leg)');
    }
    // ── once-S98 (php-dup-finder-once-123, TEST-014): the REAL run() boot ───

    /** @var list<string> */
    private array $s98Scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->s98Scratch as $dir) {
            $this->removeTree($dir);
        }
        $this->s98Scratch = [];
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testRunBootsTheRealRuntimeAndRestoresAltScreenViaSubprocess(): void
    {
        // TEST-014: driveLiveTui (S07 era) pumps runWithModel through an
        // injected loop and streams — but run() itself, the post-hoc
        // alt-screen-on construction with the default Loop::get()/STDIN
        // path, was never executed anywhere. It cannot run in-process
        // (one STDIN per test run), so it rides a child process: a real
        // `phpdup analyze` TUI boot over piped stdio, quit with 'q',
        // whose byte stream mechanically proves the enter-then-restore
        // ORDER of the alternate screen — the terminal-restoration
        // invariant the finding asks to pin.
        $dir = sys_get_temp_dir() . '/phpdup-tui-s98-' . uniqid('', true);
        mkdir($dir, 0700);
        $this->s98Scratch[] = $dir;
        $root = dirname(__DIR__, 3);

        $child = '<?php' . "\n"
            . 'declare(strict_types=1);' . "\n"
            . 'require ' . var_export($root . '/vendor/autoload.php', true) . ';' . "\n"
            . '$state = new Phpdup\\Pipeline\\PipelineState(' . "\n"
            . '    Phpdup\\Cli\\Config::defaults([' . var_export($dir, true) . '])' . "\n"
            . ');' . "\n"
            . 'exit((new Phpdup\\Tui\\TuiRunner())->run($state, ' . var_export('plain', true) . ', true));' . "\n";
        file_put_contents($dir . '/tui-child.php', $child);

        $proc = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=512M', $dir . '/tui-child.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        $this->assertIsResource($proc, 'child boot must spawn');

        fwrite($pipes[0], 'q');
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc, SIGKILL);
            $this->fail('real TUI boot did not settle within 15s — teardown path is broken');
        }

        // Draining close BEFORE proc_close: a still-open child-output pipe
        // makes proc_close block forever (the repo's bounded-reap lesson).
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, 'run() must exit clean on quit; stderr: ' . substr($stderr, 0, 200));
        $this->assertSame('', $stderr, 'the real boot must not whisper warnings');
        $on = chr(27) . '[?1049h';
        $off = chr(27) . '[?1049l';
        $this->assertSame(1, substr_count($stdout, $on), 'exactly one alternate-screen enter');
        $this->assertSame(1, substr_count($stdout, $off), 'exactly one alternate-screen restore');
        $this->assertLessThan(
            strpos($stdout, $off),
            strpos($stdout, $on),
            'enter must precede restore — the terminal is left as found, in order'
        );
    }
}
