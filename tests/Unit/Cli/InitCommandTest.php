<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\InitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * once-S98 (php-dup-finder-once-123, QA-L5b): the exit-posture belt for
 * `phpdup init`. Before this lane, an init over an existing phpdup.json
 * exited 1 (<error> tag) — the acceptance line "init with nothing to do
 * → 0" pins it as an honest no-op instead, while every genuinely bad
 * state (unknown profile, unwritable target) keeps the loud exit-1 door.
 *
 * The command reads getcwd(), so each drive chdirs into a tracked
 * scratch dir and restores the previous cwd in finally — and the
 * scratch dirs themselves are pruned in tearDown (QA-L3 posture
 * dogfooded, not just preached).
 */
final class InitCommandTest extends TestCase
{
    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            self::removeTree($dir);
        }
        $this->scratchDirs = [];
        parent::tearDown();
    }

    public function testFreshInitWritesValidJsonAndExitsZero(): void
    {
        $dir = $this->makeScratchDir();
        [$exit, $display] = $this->runInit($dir);

        self::assertSame(0, $exit, 'fresh scaffold must succeed: ' . $display);
        self::assertFileExists($dir . '/phpdup.json');
        self::assertStringContainsString('config written', $display);

        $decoded = json_decode((string) file_get_contents($dir . '/phpdup.json'), true);
        self::assertIsArray($decoded, 'generated config must be valid JSON object');
    }

    public function testExistingConfigWithoutForceIsNothingWrittenNoOpAndExitsZero(): void
    {
        $dir  = $this->makeScratchDir();
        $seed = '{"exclude": ["keep-me-marker"]}';
        file_put_contents($dir . '/phpdup.json', $seed);

        [$exit, $display] = $this->runInit($dir);

        self::assertSame(0, $exit, 'nothing-to-do init is an honest no-op, not a failure');
        self::assertStringContainsString('nothing written', $display);
        self::assertStringNotContainsString('config written', $display);
        self::assertSame($seed, file_get_contents($dir . '/phpdup.json'), 'bytes untouched');
    }

    public function testForceOverwritesTheExistingFileAndExitsZero(): void
    {
        $dir = $this->makeScratchDir();
        file_put_contents($dir . '/phpdup.json', '{"stale": true}');

        [$exit, $display] = $this->runInit($dir, ['--force' => true]);

        self::assertSame(0, $exit, '--force must still succeed: ' . $display);
        self::assertStringContainsString('config written', $display);
        $decoded = json_decode((string) file_get_contents($dir . '/phpdup.json'), true);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('stale', $decoded, 'seeded content replaced under --force');
    }

    public function testUnknownProfileStillFailsLoudlyAtExitOne(): void
    {
        $dir = $this->makeScratchDir();
        [$exit, $display] = $this->runInit($dir, ['--profile' => 'nope-not-real']);

        self::assertSame(1, $exit, 'a bad profile is a genuine failure, not the cured no-op');
        self::assertStringContainsString('unknown profile', $display);
        self::assertStringContainsString('generic', $display, 'the door names what is available');
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: int, 1: string}
     */
    private function runInit(string $dir, array $options = []): array
    {
        $app = new Application('phpdup', '0.1.0');
        $app->setAutoExit(false);
        $app->add(new InitCommand());
        $tester = new CommandTester($app->find('init'));

        $previous = getcwd();
        chdir($dir);
        try {
            $exit = $tester->execute($options);
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }

        return [$exit, $tester->getDisplay()];
    }

    private function makeScratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-init-s98-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        $this->scratchDirs[] = $dir;
        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
