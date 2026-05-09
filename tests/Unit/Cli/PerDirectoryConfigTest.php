<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

final class PerDirectoryConfigTest extends TestCase
{
    public function testEffectiveForReturnsSelfWhenNoOverrides(): void
    {
        $cfg = Config::defaults(['/tmp/x']);
        $this->assertSame($cfg, $cfg->effectiveFor('/tmp/x/foo.php'));
    }

    public function testEffectiveForAppliesMatchingOverride(): void
    {
        $cfg = new Config(
            paths: ['/tmp/x'],
            exclude: [],
            minBlockSize: 8,
            perDirectoryOverrides: [
                '/tmp/x' => ['min_block_size' => 4],
            ],
        );
        $eff = $cfg->effectiveFor('/tmp/x/sub/foo.php');
        $this->assertSame(4, $eff->minBlockSize);
        $this->assertSame(8, $cfg->minBlockSize, 'parent config is unchanged');
    }

    public function testEffectiveForLayersDeeperOverridesOverShallow(): void
    {
        $cfg = new Config(
            paths: ['/tmp/x'],
            exclude: [],
            minBlockSize: 8,
            normalizationMode: 'aggressive',
            perDirectoryOverrides: [
                '/tmp/x'         => ['min_block_size' => 4],
                '/tmp/x/sub'     => ['min_block_size' => 2, 'normalization_mode' => 'strict'],
            ],
        );
        $shallow = $cfg->effectiveFor('/tmp/x/foo.php');
        $deep    = $cfg->effectiveFor('/tmp/x/sub/foo.php');

        $this->assertSame(4, $shallow->minBlockSize);
        $this->assertSame('aggressive', $shallow->normalizationMode);

        $this->assertSame(2, $deep->minBlockSize);
        $this->assertSame('strict', $deep->normalizationMode);
    }

    public function testEffectiveForDoesNotMatchSiblingDirectories(): void
    {
        $cfg = new Config(
            paths: ['/tmp'],
            exclude: [],
            minBlockSize: 8,
            perDirectoryOverrides: [
                '/tmp/foo' => ['min_block_size' => 4],
            ],
        );
        // foobar/x.php must NOT pick up /tmp/foo's override (string-prefix
        // bug regression test — the slash check matters).
        $this->assertSame(8, $cfg->effectiveFor('/tmp/foobar/x.php')->minBlockSize);
        $this->assertSame(4, $cfg->effectiveFor('/tmp/foo/x.php')->minBlockSize);
    }

    public function testConfigLoaderDiscoversPerDirectoryFiles(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-perdir-' . uniqid();
        mkdir($tmp);
        mkdir($tmp . '/legacy');
        try {
            file_put_contents(
                $tmp . '/legacy/.phpdup.json',
                (string)json_encode(['min_block_size' => 4, 'kinds' => ['method', 'function']]),
            );

            $cfg = (new ConfigLoader())->load(paths: [$tmp], configFile: null);
            $real = realpath($tmp . '/legacy');

            $this->assertNotEmpty($cfg->perDirectoryOverrides);
            $this->assertArrayHasKey($real, $cfg->perDirectoryOverrides);
            $this->assertSame(4, $cfg->perDirectoryOverrides[$real]['min_block_size']);
            $this->assertSame(['method', 'function'], $cfg->perDirectoryOverrides[$real]['allowed_kinds']);

            $effective = $cfg->effectiveFor($tmp . '/legacy/foo.php');
            $this->assertSame(4, $effective->minBlockSize);
        } finally {
            @unlink($tmp . '/legacy/.phpdup.json');
            @rmdir($tmp . '/legacy');
            @rmdir($tmp);
        }
    }

    public function testConfigLoaderRejectsInvalidPerDirectoryFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-perdir-bad-' . uniqid();
        mkdir($tmp);
        try {
            file_put_contents($tmp . '/.phpdup.json', '{"unknown_key": true}');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Unknown config key/');
            (new ConfigLoader())->load(paths: [$tmp], configFile: null);
        } finally {
            @unlink($tmp . '/.phpdup.json');
            @rmdir($tmp);
        }
    }
}
