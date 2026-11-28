<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Item 4.2 — the knob route: defaults, the validate doors, the flat
 * resolver carrying both config-file and CLI-override spellings, and the
 * withOverrides copy that keeps the root stance intact through
 * per-directory derivation. The per-directory REFUSAL itself is pinned
 * by PerDirOverrideBeltTest (dataset rows added with this feature).
 */
final class ConfigHistoryRouteTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup_chr_' . uniqid('t', true);
        mkdir($this->tmp . '/proj', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            if (is_link($entry) || is_file($entry)) {
                @unlink($entry);
            } elseif (is_dir($entry)) {
                @rmdir($entry);
            }
        }
        @rmdir($this->tmp);
    }

    /** @param array<string,mixed> $payload */
    private function loadWith(array $payload, array $overrides = []): Config
    {
        $cfgPath = $this->tmp . '/phpdup.json';
        file_put_contents($cfgPath, (string) json_encode($payload));
        return (new ConfigLoader(warnOnSkip: false))
            ->load([$this->tmp . '/proj'], $cfgPath, $overrides);
    }

    public function testDefaultsAreUnarmed(): void
    {
        $config = $this->loadWith([]);
        self::assertFalse($config->history);
        self::assertSame(12, $config->historyWindowMonths);
    }

    public function testConfigFileArmsBothKnobs(): void
    {
        $config = $this->loadWith(['history' => true, 'history_window_months' => 24]);
        self::assertTrue($config->history);
        self::assertSame(24, $config->historyWindowMonths);
    }

    public function testCliFlagOverrideArmsWithoutConfigFile(): void
    {
        // VALUE_NONE flags arrive as overrides['history'] => true exactly
        // like honor_gitignore; the resolver must lift it over the file.
        $config = $this->loadWith(['history' => false], ['history' => true]);
        self::assertTrue($config->history);
    }

    public function testNonBooleanHistoryFailsTheDoor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('history must be a boolean');
        $this->loadWith(['history' => 'yes']);
    }

    public function testWindowBelowOneFailsTheDoor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('history_window_months');
        $this->loadWith(['history_window_months' => 0]);
    }

    public function testWindowNonNumericFailsTheDoor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('history_window_months');
        $this->loadWith(['history_window_months' => 'hot']);
    }

    public function testPerDirectoryOverrideKeepsRootHistoryStance(): void
    {
        // The per-dir belt refuses the keys at discovery; derivation must
        // still COPY the root stance into every effective config.
        $root = new Config(paths: ['/x'], exclude: [], history: true, historyWindowMonths: 3);
        $derived = $root->withOverrides(['min_block_size' => 5]);
        self::assertTrue($derived->history, 'history is a whole-report stance: per-dir derivation copies, never flips');
        self::assertSame(3, $derived->historyWindowMonths);
    }
}
