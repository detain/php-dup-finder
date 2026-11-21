<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Scanning\FileScanner;
use PHPUnit\Framework\TestCase;

/**
 * Plan item 7.2(a): the default exclude list no longer carries the
 * `tpl.php` suppression glob, so PHP-in-template files reach extraction by default.
 *
 * Documented boundary (owner-gated, plan §6 #6 — deliberately NOT taken
 * here): plain `.tpl` files still never reach the glob layer because
 * FileScanner::looksLikePhp() only accepts ext php|php8|phtml|inc. This
 * test pins both halves of that truth.
 */
final class ConfigDefaultsExcludesTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            // recursive: the nested-vendor probe creates subdirectories
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        $this->tmpDir = null;
    }

    public function testDefaultExcludesNoLongerSuppressTplPhp(): void
    {
        $exclude = Config::defaults([])->exclude;

        $this->assertNotContains('**/*.tpl.php', $exclude);
        // The sibling entries keep their coverage — item 5.2 promoted them
        // all to the deep `**`/x/** form once FileScanner's compiler became
        // segment-safe (the old root-anchored `vendor/**` missed nested
        // per-package vendor trees; see the CHANGELOG note in that commit).
        $this->assertEqualsCanonicalizing([
            '**/vendor/**', '**/node_modules/**', '**/logs/**', '**/cache/**',
            '**/storage/**', '**/build/**', '**/dist/**',
            '**/.phpdup-cache/**', '**/phpdup-report/**',
        ], $exclude);
    }

    public function testNestedVendorTreeIsExcludedByDefaults(): void
    {
        // The measurable point of the promotion: a vendor tree BELOW the scan
        // root (scripts/bots/MonitorLizzy/vendor/ in the phlix report) is now
        // pruned by the stock list, at any depth, without ever touching a
        // `myvendor/` lookalike.
        $dir = $this->corpus();
        mkdir($dir . '/nested/vendor', 0777, true);
        $php = "<?php\nfunction render_row(\$data) { \$out = []; foreach (\$data as \$row) { \$out[] = trim(\$row); } return implode(',', \$out); }\n";
        file_put_contents($dir . '/nested/vendor/pkg.php', $php);

        $files = iterator_to_array((new FileScanner(Config::defaults([$dir])->exclude))->scan($dir), false);
        $paths = implode("\n", $files);

        $this->assertStringNotContainsString('/nested/vendor/', $paths, 'nested vendor must be pruned by defaults');
    }

    public function testTplPhpFileReachesScanWithDefaultExcludes(): void
    {
        $dir = $this->corpus();

        $files = iterator_to_array((new FileScanner(Config::defaults([$dir])->exclude))->scan($dir));
        $basenames = array_map('basename', $files);

        $this->assertContains('duplicate_view.tpl.php', $basenames, '.tpl.php must reach extraction with defaults');
        // The second wall, pinned as known behavior: plain .tpl is filtered by
        // FileScanner::looksLikePhp() regardless of the exclude list. Widening
        // that gate is owner-gated (plan §6 #6).
        $this->assertNotContains('duplicate_view.tpl', $basenames);
    }

    public function testTplPhpStaysExcludedWhenUserPinsTheOldGlob(): void
    {
        // A bespoke config `exclude` replaces the defaults wholesale
        // (ConfigLoader precedence), so old behavior remains opt-in.
        $dir = $this->corpus();

        $scanner = new FileScanner(['**/*.tpl.php']);
        $basenames = array_map('basename', iterator_to_array($scanner->scan($dir)));

        $this->assertNotContains('duplicate_view.tpl.php', $basenames);
        $this->assertContains('plain_method.php', $basenames);
    }

    private function corpus(): string
    {
        if ($this->tmpDir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-tplflip-' . getmypid() . '-' . spl_object_id($this);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $php = "<?php\nfunction render_row(\$data) { \$out = []; foreach (\$data as \$row) { \$out[] = trim(\$row); } return implode(',', \$out); }\n";
            file_put_contents($dir . '/duplicate_view.tpl.php', $php);
            file_put_contents($dir . '/duplicate_view.tpl', $php);
            file_put_contents($dir . '/plain_method.php', $php);
            $this->tmpDir = $dir;
        }
        return $this->tmpDir;
    }
}
