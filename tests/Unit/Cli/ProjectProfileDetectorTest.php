<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ProfileRegistry;
use Phpdup\Cli\ProjectProfileDetector;

final class ProjectProfileDetectorTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            $this->rrmdir($dir);
        }
        $this->cleanup = [];
    }

    public function testLaravelMarkerDetectsLaravel(): void
    {
        $root = $this->mkproject(['artisan' => '#!/usr/bin/env php']);
        $this->assertSame('laravel', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testSymfonyMarkerDetectsSymfony(): void
    {
        $root = $this->mkproject(['bin/console' => '#!/usr/bin/env php']);
        $this->assertSame('symfony', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testDrupalMarkerDetectsDrupal(): void
    {
        $root = $this->mkproject(['core/lib/Drupal.php' => '<?php class Drupal {}']);
        $this->assertSame('drupal', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testWordPressMarkerDetectsWordPress(): void
    {
        $root = $this->mkproject(['wp-config.php' => '<?php // wp']);
        $this->assertSame('wordpress', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testComposerJsonAloneIsGeneric(): void
    {
        $root = $this->mkproject(['composer.json' => '{}']);
        $this->assertSame('generic', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testEmptyDirIsGeneric(): void
    {
        $root = $this->mkproject([]);
        $this->assertSame('generic', (new ProjectProfileDetector())->detect([$root]));
    }

    public function testRegistryLoadsBundledProfiles(): void
    {
        $registry = ProfileRegistry::bundled();
        $available = $registry->listAvailable();
        $this->assertContains('laravel', $available);
        $this->assertContains('symfony', $available);
        $this->assertContains('drupal', $available);
        $this->assertContains('wordpress', $available);
        $this->assertContains('generic', $available);

        $laravel = $registry->load('laravel');
        $this->assertArrayHasKey('exclude', $laravel);
        $this->assertContains('vendor/**', $laravel['exclude']);
        $this->assertArrayNotHasKey('_description', $laravel, 'profile loader strips the human description');
    }

    public function testRegistryRejectsUnknownProfile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown profile/');
        ProfileRegistry::bundled()->load('does-not-exist');
    }

    /** @param array<string,string> $files */
    private function mkproject(array $files): string
    {
        $root = sys_get_temp_dir() . '/phpdup-profile-' . uniqid();
        mkdir($root, 0o775, true);
        foreach ($files as $rel => $contents) {
            $abs = $root . '/' . $rel;
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0o775, true);
            }
            file_put_contents($abs, $contents);
        }
        $this->cleanup[] = $root;
        return $root;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
