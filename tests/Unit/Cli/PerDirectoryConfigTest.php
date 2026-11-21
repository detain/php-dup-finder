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

    /**
     * Review m-1 (item 5.1): scope/score_mode are report-level stances,
     * root-only. Mirrors the fingerprint_id_mode precedent
     * (FingerprintIdModeConfigTest::testPerDirectoryKeyRefusedLoudly): a
     * VALID root key misplaced into a per-directory file must fail the
     * load loudly with the offending path — never evaporate at shaping.
     */
    public static function perDirectoryStanceKeyProvider(): array
    {
        return [
            'scope'      => ['scope', 'single'],
            'score_mode' => ['score_mode', 'typed'],
        ];
    }

    /** @param array<string,string>|string $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('perDirectoryStanceKeyProvider')]
    public function testPerDirectoryStanceKeysRefusedLoudly(string $key, string $value): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-perdir-stance-' . uniqid();
        mkdir($tmp);
        try {
            file_put_contents($tmp . '/.phpdup.json', (string)json_encode([$key => $value]));

            try {
                (new ConfigLoader())->load(paths: [$tmp], configFile: null);
                $this->fail("per-directory {$key} must throw");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
                $this->assertStringContainsString('not a per-directory key', $e->getMessage());
                $this->assertStringContainsString('.phpdup.json', $e->getMessage());
            }
        } finally {
            @unlink($tmp . '/.phpdup.json');
            @rmdir($tmp);
        }
    }

    /**
     * Review m2 pin: an unreadable subtree must be SKIPPED by the
     * discovery walk, not re-thrown. SPL's UnexpectedValueException is a
     * RuntimeException, so escaping here would have been reclassified by
     * the CLI's exit-2 catch as invalid config — an environment error
     * masquerading as user error.
     */
    public function testUnreadableDirectoriesAreSkippedDuringDiscovery(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-perdir-denied-' . uniqid('', true);
        mkdir($tmp);
        mkdir($tmp . '/good');
        mkdir($tmp . '/locked');
        file_put_contents($tmp . '/good/.phpdup.json', (string)json_encode(['min_block_size' => 4]));
        file_put_contents($tmp . '/locked/.phpdup.json', (string)json_encode(['min_block_size' => 6]));
        chmod($tmp . '/locked', 0000);
        clearstatcache(true, $tmp . '/locked');
        try {
            if (is_readable($tmp . '/locked')) {
                $this->markTestSkipped(
                    'cannot produce a real permission denial for this effective uid (root-like); refusing to fake it'
                );
            }

            $cfg = (new ConfigLoader())->load(paths: [$tmp], configFile: null);

            $good = realpath($tmp . '/good');
            $this->assertIsString($good);
            $this->assertArrayHasKey($good, $cfg->perDirectoryOverrides);
            $this->assertSame(4, $cfg->perDirectoryOverrides[$good]['min_block_size']);
            // The locked branch yielded nothing — and its mere existence did
            // not abort discovery of the readable one.
            $this->assertCount(1, $cfg->perDirectoryOverrides);
        } finally {
            chmod($tmp . '/locked', 0755);
            @unlink($tmp . '/locked/.phpdup.json');
            @unlink($tmp . '/good/.phpdup.json');
            @rmdir($tmp . '/locked');
            @rmdir($tmp . '/good');
            @rmdir($tmp);
        }
    }
}
