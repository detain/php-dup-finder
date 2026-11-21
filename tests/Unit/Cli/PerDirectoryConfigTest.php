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

    // ------------------------------------------------------------------
    // shell-S26 — shell-010 (excludes honoured, unusable files degrade)
    //              + shell-029 (size cap, throwing JSON) on the discovery
    //              leg. The malformed-file posture change (was: throw →
    //              exit 2; now: note + continue) is disclosed in the step
    //              report for the S24/S31 doc rows.
    // ------------------------------------------------------------------

    public function testExcludedTreesAreNeverReadByDiscovery(): void
    {
        $tmp = self::tmpRoot('perdir-excl');
        try {
            mkdir($tmp . '/vendor/deep', 0777, true);
            mkdir($tmp . '/a/b/vendor', 0777, true);
            mkdir($tmp . '/node_modules', 0777, true);
            // Poison in every shape a third-party package could ship: broken
            // JSON, an unknown (stale) key, and a schema-valid value that
            // would change detection. None may be read under the stock
            // `**/vendor/**` / `**/node_modules/**` excludes.
            file_put_contents($tmp . '/vendor/.phpdup.json', '{oops');
            file_put_contents($tmp . '/vendor/deep/.phpdup.json', '{"unknown_key": true}');
            file_put_contents($tmp . '/a/b/vendor/.phpdup.json', '{"stale": 1}');
            file_put_contents($tmp . '/node_modules/.phpdup.json', '{"min_block_size": 99999}');

            $loader = new ConfigLoader(warnOnSkip: false);
            $cfg = $loader->load(paths: [$tmp], configFile: null);

            $this->assertCount(0, $cfg->perDirectoryOverrides, 'excluded configs are not consumed');
            $this->assertCount(0, $loader->skipNotes(), 'never read means never noted: pruning precedes reading');
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testExplicitExcludesAreHonouredAndIncludedTreesStillDiscovered(): void
    {
        $tmp = self::tmpRoot('perdir-excl2');
        try {
            mkdir($tmp . '/ignored', 0777, true);
            mkdir($tmp . '/incl', 0777, true);
            file_put_contents($tmp . '/ignored/.phpdup.json', '{oops');
            file_put_contents($tmp . '/incl/.phpdup.json', (string)json_encode(['min_block_size' => 4]));

            $loader = new ConfigLoader(warnOnSkip: false);
            // The dict exclude layer APPENDS to defaults (item 5.2) and the
            // discovery walk must honour exactly the resolved list the
            // scanner will later apply.
            $cfg = $loader->load(paths: [$tmp], configFile: null, overrides: ['exclude' => ['ignored/**']]);

            $incl = realpath($tmp . '/incl');
            $this->assertIsString($incl);
            $this->assertArrayHasKey($incl, $cfg->perDirectoryOverrides, 'non-excluded subtree still discovered');
            $this->assertSame(4, $cfg->perDirectoryOverrides[$incl]['min_block_size']);
            $this->assertCount(0, $loader->skipNotes(), 'the poisoned ignored dir was never opened');
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testMalformedIncludedConfigDegradesToSkipNoteAndRunContinues(): void
    {
        $tmp = self::tmpRoot('perdir-broken');
        try {
            mkdir($tmp . '/pkg', 0777, true);
            file_put_contents($tmp . '/pkg/.phpdup.json', '{oops');

            $loader = new ConfigLoader(warnOnSkip: false);
            $cfg = $loader->load(paths: [$tmp], configFile: null);

            $notes = $loader->skipNotes();
            $this->assertCount(1, $notes);
            $this->assertStringContainsString('skipped unusable per-directory config', $notes[0]);
            $this->assertStringContainsString('/pkg/.phpdup.json', $notes[0]);
            $this->assertStringContainsString('is not valid JSON (Syntax error)', $notes[0]);
            $this->assertCount(0, $cfg->perDirectoryOverrides, 'the broken file contributes nothing');
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testOversizedIncludedConfigDegradesToSkipNote(): void
    {
        $tmp = self::tmpRoot('perdir-big');
        try {
            mkdir($tmp . '/pkg', 0777, true);
            file_put_contents($tmp . '/pkg/.phpdup.json', str_repeat(' ', 2 * 1048576) . '{}');

            $loader = new ConfigLoader(warnOnSkip: false);
            $cfg = $loader->load(paths: [$tmp], configFile: null);

            $notes = $loader->skipNotes();
            $this->assertCount(1, $notes);
            $this->assertStringContainsString('exceeds the size cap of 1048576 bytes', $notes[0]);
            $this->assertCount(0, $cfg->perDirectoryOverrides);
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testDeepNestedIncludedConfigNamesTheRealJsonException(): void
    {
        $tmp = self::tmpRoot('perdir-deep');
        try {
            mkdir($tmp . '/pkg', 0777, true);
            file_put_contents($tmp . '/pkg/.phpdup.json', str_repeat('[', 700) . str_repeat(']', 700));

            $loader = new ConfigLoader(warnOnSkip: false);
            $loader->load(paths: [$tmp], configFile: null);

            $notes = $loader->skipNotes();
            $this->assertCount(1, $notes);
            $this->assertStringContainsString('is not valid JSON (Maximum stack depth exceeded)', $notes[0]);
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testUnreadableIncludedConfigIsNotedNotFatal(): void
    {
        $tmp = self::tmpRoot('perdir-unreadable');
        mkdir($tmp . '/pkg', 0777, true);
        mkdir($tmp . '/good', 0777, true);
        file_put_contents($tmp . '/pkg/.phpdup.json', (string)json_encode(['min_block_size' => 4]));
        file_put_contents($tmp . '/good/.phpdup.json', (string)json_encode(['min_block_size' => 5]));
        chmod($tmp . '/pkg/.phpdup.json', 0000);
        clearstatcache(true, $tmp . '/pkg/.phpdup.json');
        try {
            if (is_readable($tmp . '/pkg/.phpdup.json')) {
                $this->markTestSkipped(
                    'cannot produce a real permission denial for this effective uid (root-like); refusing to fake it'
                );
            }

            $loader = new ConfigLoader(warnOnSkip: false);
            $cfg = $loader->load(paths: [$tmp], configFile: null);

            $notes = $loader->skipNotes();
            $this->assertCount(1, $notes);
            $this->assertStringContainsString('/pkg/.phpdup.json', $notes[0]);
            $this->assertStringContainsString('could not be read', $notes[0]);

            $good = realpath($tmp . '/good');
            $this->assertIsString($good);
            $this->assertArrayHasKey($good, $cfg->perDirectoryOverrides, 'the sibling config is still discovered');
        } finally {
            @chmod($tmp . '/pkg/.phpdup.json', 0644);
            self::rmTree($tmp);
        }
    }

    public function testMisconfiguredButReadableIncludedFileStillThrows(): void
    {
        // Posture pin (shell-010 boundary): only UNUSABLE files degrade to a
        // note. A readable file with config ERRORS (unknown key) keeps
        // throwing — this is what shell-S22's root-only refusals lean on.
        $tmp = self::tmpRoot('perdir-throwing');
        try {
            mkdir($tmp . '/pkg', 0777, true);
            file_put_contents($tmp . '/pkg/.phpdup.json', '{"unknown_key": true}');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Unknown config key/');
            (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testSkipNoteReachesStderrByDefault(): void
    {
        // The default (warnOnSkip: true) user-visible channel, proven in a
        // child process so phpunit's own STDERR stays clean: note line on
        // STDERR + the load CALL returns (run continues, exit status 0).
        $tmp = self::tmpRoot('perdir-stderr');
        try {
            mkdir($tmp . '/pkg', 0777, true);
            file_put_contents($tmp . '/pkg/.phpdup.json', '{oops');

            $script = $tmp . '/child.php';
            file_put_contents($script, <<<'CODE'
                <?php
                require $argv[1] . '/vendor/autoload.php';
                $loader = new \Phpdup\Cli\ConfigLoader();
                $cfg = $loader->load([$argv[2]], null);
                echo 'OVERRIDES=' . count($cfg->perDirectoryOverrides) . PHP_EOL;
                echo 'NOTES=' . count($loader->skipNotes()) . PHP_EOL;
                CODE);

            $repoRoot = dirname(__DIR__, 3);
            $proc = proc_open(
                [PHP_BINARY, $script, $repoRoot, $tmp],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($proc)) {
                $this->markTestSkipped('cannot spawn a PHP child process on this runner');
            }
            fclose($pipes[0]);
            $stdout = (string)stream_get_contents($pipes[1]);
            $stderr = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            $this->assertSame(0, $exit, 'the run continues past the unusable config: ' . $stderr);
            $this->assertStringContainsString('OVERRIDES=0', $stdout);
            $this->assertStringContainsString('NOTES=1', $stdout);
            $this->assertStringContainsString('phpdup: warning: skipped unusable per-directory config', $stderr);
            $this->assertStringContainsString('/pkg/.phpdup.json', $stderr);
            $this->assertStringContainsString('is not valid JSON', $stderr);
        } finally {
            self::rmTree($tmp);
        }
    }

    private static function tmpRoot(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-' . $tag . '-' . uniqid('', true);
        mkdir($dir, 0777, true);
        return $dir;
    }

    private static function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                @chmod($path, 0755);
                self::rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
