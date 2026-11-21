<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Phpdup\Pipeline\DebugLogger;
use ReflectionProperty;

/**
 * Regression pins for php-dup-finder-shell-020: a failed fopen left
 * $handle === false, and the destructor passed that false straight to
 * fclose() — a TypeError during shutdown that turned a completed,
 * successful run into exit 255. The destructor must accept every state
 * of the handle machine (null | resource | false) and null the handle
 * after close.
 */
final class DebugLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    public function testDestructorIsSafeWhenLastOpenFailed(): void
    {
        // Parent directory does not exist: fopen('ab') fails on every platform.
        $logger = new DebugLogger($this->missingDirPath() . '/debug.log');

        // The failed open must surface its warning (PHP's native fopen
        // diagnostic) and park the handle in the false state.
        $warnings = self::captureWarnings(static function () use ($logger): void {
            $logger->append('debug line with an unopenable log path');
        });

        $this->assertNotSame([], $warnings, 'fopen failure warning should surface');
        $this->assertStringContainsString('Failed to open stream', $warnings[0]);
        $this->assertFalse(self::handleState($logger));

        // Pre-fix this raised: TypeError fclose(): Argument #1 ($stream)
        // must be of type resource, false given — during shutdown.
        $logger->__destruct();
        $this->assertNull(self::handleState($logger), 'destructor must null the handle');
    }

    public function testDestructorClosesOpenHandleAndNextAppendReopens(): void
    {
        $path = $this->tmpFile();
        $logger = new DebugLogger($path);

        $logger->append('first');
        $this->assertIsResource(self::handleState($logger));

        // Explicit close: real resource is closed, state returns to null.
        $logger->__destruct();
        $this->assertNull(self::handleState($logger));

        // Total state machine: append after close reopens and keeps writing.
        $logger->append('second');
        $this->assertIsResource(self::handleState($logger));

        unset($logger); // implicit destructor closes the reopened handle.

        $this->assertSame("first\nsecond\n", @file_get_contents($path));
    }

    public function testDestructorIsSafeWhenNeverOpened(): void
    {
        $logger = new DebugLogger($this->tmpFile());
        // No append() call: handle stays null (plain analyze run posture).
        $logger->__destruct();
        $this->assertNull(self::handleState($logger));
    }

    public function testLoggerWithoutPathIsInert(): void
    {
        $logger = new DebugLogger(null);
        $logger->append('ignored');
        $this->assertNull(self::handleState($logger));
        $logger->__destruct(); // double-destruction must also be safe
        $logger->__destruct();
        $this->assertNull(self::handleState($logger));
    }

    private function tmpFile(): string
    {
        $path = sys_get_temp_dir() . '/phpdup-s29-' . bin2hex(random_bytes(6)) . '.log';
        $this->tmpFiles[] = $path;
        return $path;
    }

    private function missingDirPath(): string
    {
        return sys_get_temp_dir() . '/phpdup-s29-missing-' . bin2hex(random_bytes(6));
    }

    /** @return resource|false|null */
    private static function handleState(DebugLogger $logger)
    {
        $prop = new ReflectionProperty(DebugLogger::class, 'handle');
        return $prop->getValue($logger);
    }

    /**
     * Run a callback with PHP warnings collected instead of emitted — keeps
     * the run hermetic under phpunit.xml's failOnWarning="true" while still
     * asserting the warning is raised.
     *
     * @return list<string>
     */
    private static function captureWarnings(callable $fn): array
    {
        $collected = [];
        set_error_handler(static function (int $severity, string $message) use (&$collected): bool {
            $collected[] = $message;
            return true;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $collected;
    }
}
