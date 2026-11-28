<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 7.1: 'text' in a per-directory .phpdup.json is REFUSED, not
 * silently inert — the scanner gate and the harvest read the ROOT config
 * once, so a nested arming roster would lie. PHP extraction kinds stay
 * legitimate per-subtree (the worker honors them), hence the positive
 * control.
 */
final class PerDirTextTierRefusalTest extends TestCase
{
    private string $root = '';

    private function mkRoot(): string
    {
        $this->root = sys_get_temp_dir() . '/phpdup-textrefuse-' . getmypid() . '-' . spl_object_id($this);
        @mkdir($this->root . '/sub', 0777, true);
        file_put_contents($this->root . '/index.php', "<?php\necho 1;\n");
        file_put_contents($this->root . '/sub/widget.js', "function w(){return 1;}\n");
        return $this->root;
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
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
            if (is_dir($this->root)) {
                $rm($this->root);
            }
        }
    }

    public function testNestedTextKindIsRefusedLoudly(): void
    {
        $root = $this->mkRoot();
        file_put_contents($root . '/sub/.phpdup.json', json_encode(['kinds' => ['text']]) . "\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kinds:\'text\' is not a per-directory key');
        (new ConfigLoader(warnOnSkip: false))->load([$root], null);
    }

    public function testNestedAllowedKindsTextIsRefusedToo(): void
    {
        $root = $this->mkRoot();
        file_put_contents($root . '/sub/.phpdup.json', json_encode(['allowed_kinds' => ['method', 'text']]) . "\n");

        $this->expectException(\RuntimeException::class);
        (new ConfigLoader(warnOnSkip: false))->load([$root], null);
    }

    public function testNestedPhpKindStaysLegal(): void
    {
        $root = $this->mkRoot();
        file_put_contents($root . '/sub/.phpdup.json', json_encode(['kinds' => ['method']]) . "\n");

        $config = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        $this->assertNotNull($config);
    }
}
