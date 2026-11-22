<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Command;
use Phpdup\Util\MemoryDebug;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins for the debug/--stats memory gauge (php-dup-finder-engine-045,
 * plan step engine-S35).
 *
 * MemoryDebug used to print memory_get_usage(false) bytes under an
 * "RSS" label, then switch to memory_get_peak_usage(true) for the
 * peak: two metrics in one line, and the "RSS" claim was simply false
 * (emalloc bytes are not kernel-resident pages). The gauge now speaks
 * the real-allocation family — the currency PHP enforces memory_limit
 * in — on every token, labelled emalloc= / peak_alloc=. These tests
 * pin the format so a future edit cannot relabel or re-mix currencies
 * without moving a deliberately-written assertion.
 */
final class CliStatsTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        $this->tmpDir = null;
    }

    public function testGaugeLabelsMatchTheMetricTheyPrint(): void
    {
        $line = MemoryDebug::getMemoryUsage();

        self::assertMatchesRegularExpression(
            '/^emalloc=\d+MB \| peak_alloc=\d+MB \| free (?:unlimited|\d+MB)$/',
            $line,
            'the gauge must read emalloc=/peak_alloc= (real-allocation family), never an "RSS" claim',
        );
        self::assertStringNotContainsString('RSS', $line);
    }

    public function testCurrentAndPeakSpeakTheSameCurrencyAndStayOrdered(): void
    {
        $line = MemoryDebug::getMemoryUsage();

        self::assertSame(1, preg_match('/^emalloc=(\d+)MB \| peak_alloc=(\d+)MB/', $line, $m));
        // Comparability is the point of the re-pin: peak of the SAME
        // family dominates current (truncation-safe — floor is
        // monotonic). The old false/true mix made the pair a
        // coincidence, not a guarantee.
        self::assertGreaterThanOrEqual((int) $m[1], (int) $m[2], $line);
    }

    public function testDebugRunPrintsTheRelabelledGaugeAndNoRssToken(): void
    {
        $tester = $this->commandTester();
        $exit = $tester->execute(
            [
                'paths' => [$this->corpusDir()],
                '--config' => $this->configPath(),
                '--stats' => true,
            ],
            ['verbosity' => OutputInterface::VERBOSITY_DEBUG, 'capture_stderr_separately' => true],
        );

        self::assertSame(0, $exit, 'clean scratch corpus must exit 0: ' . $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression(
            '/preprocess check: emalloc=\d+MB \| peak_alloc=\d+MB \| free (?:unlimited|\d+MB)/',
            $display,
            'the DEBUG gauge line must carry the relabelled, single-currency form',
        );
        self::assertStringNotContainsString('RSS', $display);

        // --stats itself is untouched by the relabel: timings block +
        // workers line still render.
        self::assertStringContainsString('timings (s):', $display);
        self::assertStringContainsString('workers:', $display);
    }

    /** Scratch corpus: two clone-shaped functions, nothing else. */
    private function corpusDir(): string
    {
        $dir = $this->root() . '/corpus';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/a.php', "<?php\nfunction a() { \$x = 1; if (\$x) { echo 'hit'; } }\n");
        file_put_contents($dir . '/b.php', "<?php\nfunction b() { \$y = 1; if (\$y) { echo 'hit'; } }\n");

        return $dir;
    }

    /** Hermetic cache_dir (absolute tmp) so the run never touches the repo. */
    private function configPath(): string
    {
        $path = $this->root() . '/phpdup-s35.json';
        file_put_contents($path, json_encode(['cache_dir' => $this->root() . '/cache']) ?: '{}');

        return $path;
    }

    private function root(): string
    {
        if ($this->tmpDir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-s35-cli-' . getmypid() . '-' . spl_object_id($this);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $this->tmpDir = $dir;
        }
        return $this->tmpDir;
    }

    private function commandTester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());
        return new CommandTester($app->find('analyze'));
    }
}
