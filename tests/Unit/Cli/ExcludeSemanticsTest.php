<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Scanning\FileScanner;
use PHPUnit\Framework\TestCase;

/**
 * Item 5.2 (C-leg): exclude lists APPEND at every layer — stock defaults,
 * profile, config file, programmatic (CLI-dict) overrides, per-directory
 * files — and only the explicit `"exclude_reset": true` opt-out discards
 * what was inherited. Before this fix any single explicit list replaced
 * the whole stack (and $overrides['exclude'] was silently dropped).
 */
final class ExcludeSemanticsTest extends TestCase
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

    private function configFile(array $payload): string
    {
        if ($this->tmpDir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-exclsem-' . getmypid() . '-' . spl_object_id($this);
            mkdir($dir, 0777, true);
            $this->tmpDir = $dir;
        }
        $file = $this->tmpDir . '/phpdup.json';
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $file;
    }

    public function testConfigFileExcludeAppendsToDefaults(): void
    {
        $file = $this->configFile(['exclude' => ['custom/**']]);

        $config = (new ConfigLoader())->load(['src'], $file);

        $this->assertContains('**/vendor/**', $config->exclude, 'stock defaults survive an explicit exclude list');
        $this->assertContains('custom/**', $config->exclude, 'the explicit list is appended');
        $this->assertSame('custom/**', $config->exclude[count($config->exclude) - 1], 'inherited first, explicit last');
    }

    public function testProfileExcludeAppendsToDefaults(): void
    {
        $config = (new ConfigLoader())->load(['src'], null, [], ['from/profile/**']);

        $this->assertContains('**/vendor/**', $config->exclude);
        $this->assertContains('from/profile/**', $config->exclude);
    }

    public function testTripleStackDefaultsProfileConfigDedupes(): void
    {
        $file = $this->configFile(['exclude' => ['**/.phpdup-cache/**', 'from/config/**']]);

        $config = (new ConfigLoader())->load(['src'], $file, [], ['from/profile/**']);

        $this->assertContains('from/profile/**', $config->exclude);
        $this->assertContains('from/config/**', $config->exclude);
        // the re-stated default appears once, order preserved:
        $this->assertSame(
            count($config->exclude),
            count(array_unique($config->exclude)),
            'deduped',
        );
        $expectedOrder = array_values(array_unique(array_merge(
            Config::defaults(['src'])->exclude,
            ['from/profile/**', '**/.phpdup-cache/**', 'from/config/**'],
        )));
        $this->assertSame($expectedOrder, $config->exclude);
    }

    public function testExcludeResetDropsInheritedLists(): void
    {
        $file = $this->configFile(['exclude' => ['only/**'], 'exclude_reset' => true]);

        $config = (new ConfigLoader())->load(['src'], $file, [], ['from/profile/**']);

        $this->assertSame(['only/**'], $config->exclude, 'reset is the documented wholesale-replace opt-out');
    }

    public function testExcludeResetWithoutListScansEverything(): void
    {
        $file = $this->configFile(['exclude_reset' => true]);

        $config = (new ConfigLoader())->load(['src'], $file);

        $this->assertSame([], $config->exclude);
    }

    public function testCliDictExcludeIsHonoredAndResets(): void
    {
        // the overrides dict previously vanished without trace at root level;
        // it now stacks on top of the other layers…
        $config = (new ConfigLoader())->load(['src'], null, ['exclude' => ['dict/**']]);
        $this->assertContains('**/vendor/**', $config->exclude);
        $this->assertContains('dict/**', $config->exclude);

        // …and carries the reset opt-out too.
        $reset = (new ConfigLoader())->load(['src'], null, ['exclude' => ['dict/**'], 'exclude_reset' => true]);
        $this->assertSame(['dict/**'], $reset->exclude);
    }

    public function testValidateRejectsNonBooleanExcludeReset(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exclude_reset must be a boolean');
        (new ConfigLoader())->validate(['exclude_reset' => 'yes']);
    }

    // ── shell-030 (S21 rider): chained-globstar excludes survive the stack ──

    /**
     * End-to-end seatbelt for the ReDoS coalescing: an exclude list carrying
     * adjacent globstar-slash runs flows through ConfigLoader's full stack
     * (defaults + file) into FileScanner's compiler, and the coalesced
     * regexes must exclude exactly what the un-coalesced ones excluded.
     * FileScannerTest owns the compile-level equivalence table; this pins
     * the semantics where users actually write such patterns.
     */
    public function testAdjacentGlobstarExcludeSurvivesConfigStack(): void
    {
        $file = $this->configFile(['exclude' => ['**/**/vendor/**', 'mod/**/**/legacy']]);

        $config = (new ConfigLoader())->load(['src'], $file);

        $this->assertContains('**/vendor/**', $config->exclude, 'stock defaults stay under the chain');
        $this->assertContains('**/**/vendor/**', $config->exclude, 'the chained form is appended verbatim');

        $scanner = new FileScanner($config->exclude, warnOnSkip: false);
        $this->assertTrue($scanner->isExcluded('vendor/x.php'), 'root-level vendor still excluded');
        $this->assertTrue($scanner->isExcluded('a/b/vendor/c.php'), 'deep vendor still excluded');
        $this->assertTrue($scanner->isExcluded('vendor/'), 'directory-prune form still excluded');
        $this->assertFalse($scanner->isExcluded('myvendor/x.php'), 'coalescing must not re-open the over-match');

        $this->assertTrue($scanner->isExcluded('mod/a/b/legacy'), 'mid-pattern chain behaves like one globstar');
        $this->assertFalse($scanner->isExcluded('mod/a/b/legacy.php'), 'anchored tail stays anchored');
        $this->assertSame(PREG_NO_ERROR, preg_last_error(), 'every verdict came from an int preg_match');
    }

    // ── per-directory layer (Config::effectiveFor / withOverrides) ─────────

    public function testNestedPerDirectoryExcludesAccumulate(): void
    {
        $config = $this->rootWithDirs([
            '/phpdup-fake/dir'     => ['exclude' => ['a/**']],
            '/phpdup-fake/dir/sub' => ['exclude' => ['b/**']],
        ]);

        $effective = $config->effectiveFor('/phpdup-fake/dir/sub/file.php');

        $this->assertContains('**/vendor/**', $effective->exclude, 'root defaults stay under per-dir adds');
        $this->assertContains('a/**', $effective->exclude, 'the ancestor list is kept');
        $this->assertContains('b/**', $effective->exclude, 'the deepest list is appended');
    }

    public function testDeepestExcludeResetDiscardsEverythingAbove(): void
    {
        $config = $this->rootWithDirs([
            '/phpdup-fake/dir'     => ['exclude' => ['a/**']],
            '/phpdup-fake/dir/sub' => ['exclude' => ['b/**'], 'exclude_reset' => true],
        ]);

        $effective = $config->effectiveFor('/phpdup-fake/dir/sub/file.php');

        $this->assertSame(['b/**'], $effective->exclude);
    }

    public function testWithOverridesAppendsExclude(): void
    {
        $root = new Config(paths: ['x'], exclude: ['inherited/**']);

        $appended = $root->withOverrides(['exclude' => ['added/**', 'inherited/**']]);
        $this->assertSame(['inherited/**', 'added/**'], $appended->exclude, 'append + dedupe');

        $untouched = $root->withOverrides(['min_block_size' => 9]);
        $this->assertSame(['inherited/**'], $untouched->exclude, 'no exclude key ⇒ inherit verbatim');

        $cleared = $root->withOverrides(['exclude_reset' => true]);
        $this->assertSame([], $cleared->exclude);
    }

    /**
     * @param array<string, array<string,mixed>> $dirs
     */
    private function rootWithDirs(array $dirs): Config
    {
        return new Config(
            paths: ['/phpdup-fake'],
            exclude: ['**/vendor/**'],
            perDirectoryOverrides: $dirs,
        );
    }
}
