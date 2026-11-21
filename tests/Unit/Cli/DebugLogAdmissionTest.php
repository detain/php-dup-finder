<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression net for php-dup-finder-once-079 (plan step once-S69).
 *
 * An unwritable --debug-log path used to degrade the RUN, not the flag:
 * the analysis proceeded, every debug message retried fopen (warning
 * spam), and shutdown could still die with fclose(false). Admission now
 * happens in Command::execute() before the pipeline constructs a
 * DebugLogger: one clear error line, the conventional friendly usage
 * exit 2 — and with DebugLogger's terminal-false handle leg
 * (retry-on-false removed) even a mid-run breakage costs at most one
 * warning instead of one per message.
 */
final class DebugLogAdmissionTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            @rmdir($this->tmpDir);
        }
        $this->tmpDir = null;
    }

    public function testUnwritableDebugLogPathExitsTwoWithOneClearLine(): void
    {
        $deadPath = $this->missingDirPath() . '/debug.log';

        $tester = $this->commandTester();
        $corpus = $this->corpusDir();
        $exit = null;

        // Any warning raised during admission or the (never-started) run
        // would land here; the suppressed probe means it must be empty.
        $warnings = self::captureWarnings(static function () use ($tester, $deadPath, $corpus, &$exit): void {
            $exit = $tester->execute(
                ['paths' => [$corpus], '--debug-log' => $deadPath],
                ['capture_stderr_separately' => true],
            );
        });

        $this->assertSame(2, $exit, 'unwritable debug-log path must exit 2 (usage posture)');
        $this->assertSame([], $warnings, 'admission must not surface a single fopen warning');

        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertStringContainsString('phpdup: --debug-log path is not writable: ' . $deadPath, $combined);

        $lines = array_values(array_filter(
            explode("\n", str_replace("\r", '', $combined)),
            static fn(string $l): bool => $l !== '',
        ));
        $this->assertCount(1, $lines, 'exactly one clear error line, got: ' . $combined);
        $this->assertStringNotContainsString('Uncaught', $combined);
        $this->assertStringNotContainsString('TypeError', $combined);
    }

    public function testUnwritableDebugLogPathFromConfigFileAlsoExitsTwo(): void
    {
        // debug_log is a root-level key (S22 posture); the admission sits on
        // the resolved Config, so the config-file source is gated too.
        $configPath = $this->corpusDir() . '/phpdup.json';
        file_put_contents($configPath, json_encode(['debug_log' => $this->missingDirPath() . '/x.log']));

        $tester = $this->commandTester();
        $exit = $tester->execute(
            ['paths' => [$this->corpusDir()], '--config' => $configPath],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(2, $exit);
        $this->assertStringContainsString(
            '--debug-log path is not writable',
            $tester->getErrorOutput() . $tester->getDisplay(),
        );
    }

    public function testWritableDebugLogPathRunsAndRecords(): void
    {
        $log = $this->corpusDir() . '/debug-ok.log';

        $tester = $this->commandTester();
        $exit = $tester->execute(
            ['paths' => [$this->corpusDir()], '--debug-log' => $log],
            // -vvv: debug() messages flow to output AND the log
            ['verbosity' => OutputInterface::VERBOSITY_DEBUG, 'capture_stderr_separately' => true],
        );

        $this->assertSame(0, $exit, 'a writable debug-log path must not be refused: ' . $tester->getDisplay());
        $this->assertStringNotContainsString('not writable', $tester->getErrorOutput() . $tester->getDisplay());
        $this->assertStringContainsString('preprocess:', (string) @file_get_contents($log), 'log must capture debug lines');
    }

    /** Scratch corpus for paths + config files (kept <8 files: serial path only). */
    private function corpusDir(): string
    {
        if ($this->tmpDir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-s69-cli-' . getmypid() . '-' . spl_object_id($this);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir . '/a.php', "<?php\nfunction a() { \$x = 1; if (\$x) { echo 'hit'; } }\n");
            file_put_contents($dir . '/b.php', "<?php\nfunction b() { \$y = 1; if (\$y) { echo 'hit'; } }\n");
            $this->tmpDir = $dir;
        }
        return $this->tmpDir;
    }

    /** Parent directory does not exist: nothing under it is openable. */
    private function missingDirPath(): string
    {
        return sys_get_temp_dir() . '/phpdup-s69-cli-missing-' . getmypid() . '-' . spl_object_id($this);
    }

    private function commandTester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());
        return new CommandTester($app->find('analyze'));
    }

    /**
     * Collect raised warnings against a strict E_ALL baseline (PHPUnit
     * lowers error_reporting globally), deferring only to EXPRESSION-LEVEL
     * suppression: an @-suppressed diagnostic (the admission probe) is NOT
     * collected, an unsuppressed one is.
     *
     * @return list<string>
     */
    private static function captureWarnings(callable $fn): array
    {
        $collected = [];
        $previousMask = error_reporting(E_ALL); // strict baseline under PHPUnit's lowered mask
        set_error_handler(static function (int $severity, string $message) use (&$collected): bool {
            if (!(error_reporting() & $severity)) {
                return false; // @-suppressed at the call site: nothing surfaces to the user either
            }
            $collected[] = $message;
            return true;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
            error_reporting($previousMask);
        }
        return $collected;
    }
}
