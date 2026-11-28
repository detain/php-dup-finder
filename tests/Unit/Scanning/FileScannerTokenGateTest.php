<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Scanning\FileScanner;
use Phpdup\Text\LangProfiles;

/**
 * Item 7.1: the scanner's second gate. Empty textExtensions (every
 * default/PHP-only run) must reproduce the historical walk EXACTLY;
 * an armed list admits profile extensions through both doors — the
 * walk and a named root file.
 */
final class FileScannerTokenGateTest extends TestCase
{
    private string $root = '';

    private function tree(): string
    {
        $this->root = sys_get_temp_dir() . '/phpdup-textgate-' . getmypid() . '-' . spl_object_id($this);
        @mkdir($this->root . '/nested', 0777, true);
        file_put_contents($this->root . '/keep.php', "<?php\nclass K {}\n");
        file_put_contents($this->root . '/app.js', "function a(){return 1;}\n");
        file_put_contents($this->root . '/page.tpl', "<div>{% if x %}y{% endif %}</div>\n");
        file_put_contents($this->root . '/notes.txt', "prose is not source\n");
        file_put_contents($this->root . '/data.json', "{\"a\":1}\n");
        file_put_contents($this->root . '/nested/deep.js', "export const z = 2;\n");
        return $this->root;
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $rm = function (string $dir) use (&$rm): void {
                foreach (scandir($dir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $p = $dir . '/' . $entry;
                    is_dir($p) ? $rm($p) : @unlink($p);
                }
                @rmdir($dir);
            };
            $rm($this->root);
        }
    }

    /** @return list<string> basenames, sorted */
    private function names(FileScanner $scanner, string $root): array
    {
        $out = [];
        foreach ($scanner->scan($root) as $f) {
            $out[] = str_replace($root . '/', '', (string) $f);
        }
        sort($out);
        return $out;
    }

    public function testDisarmedWalkIsTheHistoricalSet(): void
    {
        $root = $this->tree();
        $this->assertSame(
            ['keep.php'],
            $this->names(new FileScanner([], false), $root),
            'empty extension list = byte-identical historical walk',
        );
    }

    public function testArmedWalkAddsProfileExtensionsOnly(): void
    {
        $root = $this->tree();
        $scanner = new FileScanner([], false, [], false, LangProfiles::extensions());
        $found = $this->names($scanner, $root);
        foreach (['keep.php', 'app.js', 'page.tpl', 'data.json', 'nested/deep.js'] as $want) {
            $this->assertContains($want, $found);
        }
        $this->assertNotContains('notes.txt', $found, 'prose has no profile and no door');
    }

    public function testNamedRootFilePassesTheSecondGate(): void
    {
        $root = $this->tree();
        $js = $root . '/app.js';

        $armed = new FileScanner([], false, [], false, LangProfiles::extensions());
        $this->assertSame([$js], iterator_to_array($armed->scan($js), false));

        $off = iterator_to_array((new FileScanner([], false))->scan($js), false);
        $this->assertSame([], $off, 'disarmed: a named .js root still cannot in');
    }

    public function testTxtHasNoProfileAnywhere(): void
    {
        $this->assertNull(LangProfiles::profileFor('a.txt'));
        $this->assertNull(LangProfiles::profileFor('README.md'));
    }
}
