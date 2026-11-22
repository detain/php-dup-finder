<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Scanning\FileScanner;
use Symfony\Component\Console\Output\NullOutput;

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

    // ------------------------------------------------------------------
    // shell-S17 — shell-035: per-group memo. The hot callers ask
    // effectiveFor() once per file; every file of one matched override
    // GROUP must reuse one derived Config, so the 60-field withOverrides()
    // rebuild runs once per distinct group (instance census == invocation
    // count), not once per file. Distinct groups still mint distinct
    // instances, and the values each instance carries are untouched.
    // ------------------------------------------------------------------

    public function testEffectiveForReusesOneDerivedConfigPerMatchedGroup(): void
    {
        $cfg = new Config(
            paths: ['/s17-root'],
            exclude: [],
            minBlockSize: 8,
            perDirectoryOverrides: [
                '/s17-root'     => ['min_block_size' => 4],
                '/s17-root/sub' => ['min_block_size' => 2, 'normalization_mode' => 'strict'],
            ],
        );

        $shallow1 = $cfg->effectiveFor('/s17-root/a.php');
        $shallow2 = $cfg->effectiveFor('/s17-root/b.php');
        $again    = $cfg->effectiveFor('/s17-root/a.php');
        $deep1    = $cfg->effectiveFor('/s17-root/sub/c.php');
        $deep2    = $cfg->effectiveFor('/s17-root/sub/d.php');
        // Different DIRECTORY, same matched group as the shallow files: the
        // memo keys on the group, so this is a reuse too (the dir-keyed
        // alternative fix shape would rebuild here and fail this assert).
        $sibling  = $cfg->effectiveFor('/s17-root/other/e.php');
        $unhit    = $cfg->effectiveFor('/elsewhere/x.php');

        $this->assertSame($shallow1, $shallow2, 'same-directory files reuse one derived Config');
        $this->assertSame($shallow1, $again, 'repeat lookups never rebuild');
        $this->assertSame($shallow1, $sibling, 'distinct dirs resolving to one group share the instance');
        $this->assertSame($deep1, $deep2, 'the deep group reuses too');
        $this->assertNotSame($shallow1, $deep1, 'distinct groups mint distinct derived configs');
        $this->assertSame($cfg, $unhit, 'unmatched paths still return the root itself');

        // The instance census IS the withOverrides-invocation count: every
        // derived object effectiveFor can hand out was minted by exactly
        // one withOverrides call; the root arrives via the early return.
        $ids = [];
        foreach ([$shallow1, $shallow2, $again, $deep1, $deep2, $sibling, $unhit] as $eff) {
            $ids[spl_object_id($eff)] = true;
        }
        $this->assertCount(3, $ids, '7 lookups over 2 matched groups + the root mint exactly 3 instances');

        // Values land where they did pre-memo (no behavior delta).
        $this->assertSame(4, $shallow1->minBlockSize);
        $this->assertSame('aggressive', $shallow1->normalizationMode);
        $this->assertSame(2, $deep1->minBlockSize);
        $this->assertSame('strict', $deep1->normalizationMode);
        $this->assertSame(8, $cfg->minBlockSize, 'root config untouched by derivation');
    }

    public function testMemoCannotBleedFileShapedKeyIntoDirectorySiblings(): void
    {
        // Pathological but pinned: discovery only ever registers DIRECTORY
        // keys, yet a hand-built Config may carry a file-shaped one. The
        // match set (and therefore the group identity) stays a per-path
        // computation, so the special file and its siblings sit in
        // different memo slots — the dir-keyed alternative would serve
        // them one instance and silently apply the special override to
        // every sibling.
        $cfg = new Config(
            paths: ['/s17-degen'],
            exclude: [],
            minBlockSize: 8,
            perDirectoryOverrides: [
                '/s17-degen/special.php' => ['min_block_size' => 1],
            ],
        );

        $special = $cfg->effectiveFor('/s17-degen/special.php');
        $repeat  = $cfg->effectiveFor('/s17-degen/special.php');
        $plain   = $cfg->effectiveFor('/s17-degen/other.php');

        $this->assertSame(1, $special->minBlockSize, 'the file-shaped key still binds its own path');
        $this->assertSame($special, $repeat, 'and memoizes for repeats of that same path');
        $this->assertSame($cfg, $plain, 'directory siblings never inherit the special group');
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
     * shell-S22 (php-dup-finder-shell-026, ENGINE-SEC out-of-group 2): the
     * path/ref-bearing family is root-only. baseline/baseline_out name files
     * the run reads and OVERWRITES, debug_log names the log sink, diff_base
     * becomes argv of a git call — keys an attacker-planted subtree config
     * must never choose. Same loud-refusal posture as the stance family
     * above (fingerprint_id_mode precedent), asserted per key.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function perDirectoryPathRefKeyProvider(): array
    {
        return [
            'baseline'     => ['baseline', '/belt/baseline.json'],
            'baseline_out' => ['baseline_out', '/belt/baseline-out.json'],
            'diff_base'    => ['diff_base', 'HEAD~1'],
            'debug_log'    => ['debug_log', '/belt/debug.log'],
        ];
    }

    /**
     * @param array<string,string>|string $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('perDirectoryPathRefKeyProvider')]
    public function testPerDirectoryPathRefKeysRefusedLoudly(string $key, string $value): void
    {
        $tmp = self::tmpRoot('perdir-pathref');
        try {
            file_put_contents($tmp . '/.phpdup.json', (string)json_encode([$key => $value]));

            try {
                (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);
                $this->fail("per-directory {$key} must throw");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
                $this->assertStringContainsString('not a per-directory key', $e->getMessage());
                $this->assertStringContainsString('root phpdup.json only', $e->getMessage());
                $this->assertStringContainsString('.phpdup.json', $e->getMessage(), 'the offending file is named');
            }
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testPathRefRefusalIsNotMaskedByValidAdmittedKeys(): void
    {
        // The refusal must not evaporate behind successful shaping of a
        // well-behaved sibling key in the same subtree file.
        $tmp = self::tmpRoot('perdir-pathref-mask');
        try {
            file_put_contents(
                $tmp . '/.phpdup.json',
                (string)json_encode(['min_block_size' => 4, 'debug_log' => '/belt/debug.log']),
            );

            try {
                (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);
                $this->fail('a valid admitted key must not shelter a root-only one');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('debug_log', $e->getMessage());
                $this->assertStringContainsString('not a per-directory key', $e->getMessage());
            }
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testRootConfigStillAdmitsThePathRefFamily(): void
    {
        // Root-only means root-ALLOWED: the same four keys in the root
        // phpdup.json keep working (flat resolve -> Config constructor),
        // and none of them leaks into the per-directory override maps.
        $tmp = self::tmpRoot('perdir-rootok');
        try {
            mkdir($tmp . '/sub', 0777, true);
            file_put_contents(
                $tmp . '/phpdup.json',
                (string)json_encode([
                    'baseline'     => $tmp . '/b.json',
                    'baseline_out' => $tmp . '/o.json',
                    'diff_base'    => 'HEAD~1',
                    'debug_log'    => $tmp . '/d.log',
                ]),
            );

            $cfg = (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp . '/sub'], configFile: $tmp . '/phpdup.json');

            $this->assertSame($tmp . '/b.json', $cfg->baselineFile);
            $this->assertSame($tmp . '/o.json', $cfg->baselineOutFile);
            $this->assertSame('HEAD~1', $cfg->diffBase);
            $this->assertSame($tmp . '/d.log', $cfg->debugLog);
            foreach ($cfg->perDirectoryOverrides as $dict) {
                foreach (['baseline', 'baseline_out', 'diff_base', 'debug_log'] as $rootOnlyKey) {
                    $this->assertArrayNotHasKey($rootOnlyKey, $dict);
                }
            }
        } finally {
            self::rmTree($tmp);
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

    // ------------------------------------------------------------------
    // once-S28 (php-dup-finder-once-032): the per-directory exclude overlay
    // — nested .phpdup.json exclude/exclude_reset lists are applied at SCAN
    // time, dir-anchored, chained shallow→deep, one-way reset re-roots.
    // ------------------------------------------------------------------

    public function testPerDirectoryExcludeSkipsNestedDirInScanCensus(): void
    {
        $tmp = self::tmpRoot('s28-census');
        try {
            self::writeTree($tmp, [
                'a.php',
                'legacy/b.php',
                'legacy/gen/c.php',
                'other/gen/d.php',
            ]);
            self::writeConfig($tmp . '/legacy', ['exclude' => ['gen/**']]);

            $cfg = (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);
            $real = realpath($tmp . '/legacy');
            $this->assertArrayHasKey($real, $cfg->perDirectoryOverrides);
            $this->assertSame(['gen/**'], $cfg->perDirectoryOverrides[$real]['exclude']);

            $files = self::scanCensus($cfg);

            // The nested config prunes legacy/gen/ — its file never enters
            // the census — while the identically named gen/ OUTSIDE the
            // config's subtree is untouched: the layer's patterns live in
            // ITS OWN directory's frame, not the scan root's.
            $this->assertSame([
                $tmp . '/a.php',
                $tmp . '/legacy/b.php',
                $tmp . '/other/gen/d.php',
            ], $files);
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testPerDirectoryExcludeResetReRootsAncestorExcludedSubtree(): void
    {
        $tmp = self::tmpRoot('s28-reset');
        try {
            self::writeTree($tmp, [
                'sibling.php',
                'gen/gone.php',
                'legacy/keep.php',
                'legacy/gen/deep.php',
            ]);
            // The root-adjacent config excludes both subtrees...
            self::writeConfig($tmp, ['exclude' => ['gen/**', 'legacy/**']]);
            // ...and legacy re-roots its own subtree: ancestors AND the
            // scan-root list are discarded below it (one-way, mirroring
            // Config::effectiveFor's exclude_reset doctrine).
            self::writeConfig($tmp . '/legacy', ['exclude_reset' => true, 'exclude' => []]);

            $cfg = (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);

            $files = self::scanCensus($cfg);

            $this->assertSame([
                $tmp . '/legacy/gen/deep.php',
                $tmp . '/legacy/keep.php',
                $tmp . '/sibling.php',
            ], $files);
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testPerDirectoryExcludeChainAccumulatesAcrossLayers(): void
    {
        $tmp = self::tmpRoot('s28-chain');
        try {
            self::writeTree($tmp, [
                'skipme/x.php',
                'mod/skipme/y.php',
                'mod/tmp/z.php',
                'mod/m.php',
                'tmp/w.php',
            ]);
            self::writeConfig($tmp, ['exclude' => ['skipme/**']]);
            self::writeConfig($tmp . '/mod', ['exclude' => ['tmp/**']]);

            $cfg = (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);

            $files = self::scanCensus($cfg);

            // Both lists are active inside mod/ (append, never replace);
            // each stays anchored to its own directory, so the same-named
            // directories on the other side of each anchor survive.
            $this->assertSame([
                $tmp . '/mod/m.php',
                $tmp . '/mod/skipme/y.php',
                $tmp . '/tmp/w.php',
            ], $files);
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testScanCensusIsUnchangedForConfigsWithoutExcludeKeys(): void
    {
        $tmp = self::tmpRoot('s28-inert');
        try {
            self::writeTree($tmp, ['a.php', 'legacy/b.php']);
            // Knob-only config: admission + effectiveFor stay, the walk
            // must not learn any new frame from it.
            self::writeConfig($tmp . '/legacy', ['min_block_size' => 4]);

            $cfg = (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);

            $files = self::scanCensus($cfg);

            $this->assertSame([$tmp . '/a.php', $tmp . '/legacy/b.php'], $files);
            $this->assertSame(
                4,
                $cfg->effectiveFor($tmp . '/legacy/b.php')->minBlockSize,
                'the knob layer still applies where it always did'
            );
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testOverlayPruningIsSilentAndRootListStillDominatesOutsideSubtrees(): void
    {
        $tmp = self::tmpRoot('s28-silent');
        try {
            self::writeTree($tmp, [
                'vendor/autoload.php',
                'legacy/b.php',
                'legacy/gen/c.php',
            ]);
            self::writeConfig($tmp . '/legacy', ['exclude' => ['gen/**']]);

            // The stock default vendor exclude prunes vendor/ even with the
            // overlay bound — the root chain keeps full force OUTSIDE any
            // resetting subtree.
            $scanner = new FileScanner(
                Config::defaults([$tmp])->exclude,
                warnOnSkip: false,
                perDirExcludes: [
                    realpath($tmp . '/legacy') => ['exclude' => ['gen/**'], 'reset' => false],
                ],
            );
            $files = iterator_to_array($scanner->scan($tmp), false);
            sort($files);

            $this->assertSame([$tmp . '/legacy/b.php'], $files);
            $this->assertSame([], $scanner->skipNotes(), 'excludes prune silently, overlay included');

            // The public root-frame matcher is unchanged by the overlay.
            $this->assertFalse($scanner->isExcluded('legacy/gen/c.php'));
        } finally {
            self::rmTree($tmp);
        }
    }

    public function testResetInsideRootExcludedTreeCannotResurrectItInDiscovery(): void
    {
        $tmp = self::tmpRoot('s28-gate');
        try {
            self::writeTree($tmp, ['vendor/pkg/evil.php', 'a.php']);
            // A vendored package writing its own escape hatch: discovery
            // never reads it (root list prunes vendor/ before descent), so
            // the map — and therefore the scan overlay — stays empty for
            // it. The doctrine of testExcludedTreesAreNeverReadByDiscovery,
            // now also stated from the scan side.
            self::writeConfig($tmp . '/vendor/pkg', ['exclude_reset' => true]);

            $cfg = (new ConfigLoader(warnOnSkip: false))->load(paths: [$tmp], configFile: null);

            $this->assertCount(0, $cfg->perDirectoryOverrides);
            $this->assertSame([$tmp . '/a.php'], self::scanCensus($cfg));
        } finally {
            self::rmTree($tmp);
        }
    }

    /**
     * Drive the real scan stage and return its census (the sorted file list
     * the pipeline hands downstream).
     *
     * @return list<string>
     */
    private static function scanCensus(Config $cfg): array
    {
        $state = new PipelineState($cfg);
        (new ScanningStage())->run($state, new NullOutput());
        return $state->files;
    }

    /**
     * @param list<string> $relPaths slash-separated file paths to create (parents mkdir'd)
     */
    private static function writeTree(string $root, array $relPaths): void
    {
        foreach ($relPaths as $rel) {
            $dir = $root . '/' . dirname($rel);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($root . '/' . $rel, "<?php\n");
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeConfig(string $dir, array $payload): void
    {
        file_put_contents($dir . '/.phpdup.json', (string) json_encode($payload));
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
