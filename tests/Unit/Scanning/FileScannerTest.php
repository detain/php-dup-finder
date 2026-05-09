<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Scanning\FileScanner;

final class FileScannerTest extends TestCase
{
    public function testScansFixtureDirectory(): void
    {
        $scanner = new FileScanner([]);
        $files = iterator_to_array($scanner->scan(__DIR__ . '/../../Fixtures'), false);
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $this->assertStringEndsWith('.php', $f);
        }
    }

    public function testExcludesGlobs(): void
    {
        $scanner = new FileScanner(['**/exact/**']);
        $files = iterator_to_array($scanner->scan(__DIR__ . '/../../Fixtures'), false);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('/exact/', $f);
        }
    }

    public function testIsExcludedMatchesDoubleStar(): void
    {
        $scanner = new FileScanner(['vendor/**', '**/*.tpl.php']);
        $this->assertTrue($scanner->isExcluded('vendor/foo/bar.php'));
        $this->assertTrue($scanner->isExcluded('src/views/header.tpl.php'));
        $this->assertFalse($scanner->isExcluded('src/views/header.php'));
    }
}
