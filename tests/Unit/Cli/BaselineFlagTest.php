<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * Item 4.4 APPLY half: the root-only "accepted" suppression register —
 * schema validation, loader threading, and per-directory refusal.
 *
 * The register is the config-file half of the accepted-finding workflow
 * (its siblings: the --baseline file's dispositions map and harvested
 * @phpdup-ignore directives); it lists stable finding ids that the team
 * has explicitly decided to keep. The ids drop out of the main report
 * into the baseline appendix; the list is never honored from a
 * per-directory .phpdup.json (attacker-controlled suppression was the
 * exact hazard the per-dir gate refused for the path/ref family).
 */
final class BaselineFlagTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup-baseline-flag-test-' . uniqid();
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            is_file($f) ? unlink($f) : exec('rm -rf ' . escapeshellarg($f));
        }
        @rmdir($this->tmpDir);
    }

    public function testValidateAcceptsListOfStrings(): void
    {
        $loader = new ConfigLoader(warnOnSkip: false);
        $loader->validate(['accepted' => ['Faaa1111222233334', 'Fbbb1111222233334']]);
        $this->addToAssertionCount(1, 'a list of finding ids is a valid accepted register');
    }

    public function testValidateRejectsNonArrayAccepted(): void
    {
        $loader = new ConfigLoader(warnOnSkip: false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('accepted must be an array');
        $loader->validate(['accepted' => 'Faaa1111222233334']);
    }

    public function testValidateRejectsEmptyStringIdInAccepted(): void
    {
        $loader = new ConfigLoader(warnOnSkip: false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('accepted must contain non-empty strings');
        $loader->validate(['accepted' => ['Faaa1111222233334', '']]);
    }

    public function testAcceptedIsNotAConfigSchemaParityMissingKey(): void
    {
        // The accepted key must appear in docs/config-schema.json's
        // properties (belt-adjacent smoke; ConfigSchemaParityTest
        // pin-checks the whole set equality — this test only nails
        // the key landed in KNOWN_KEYS and the schema row).
        $reflection = new \ReflectionClass(ConfigLoader::class);
        $known = $reflection->getConstant('KNOWN_KEYS');
        $this->assertIsArray($known);
        $this->assertContains('accepted', $known, 'item 4.4 admits the accepted key into root config universe');

        $schemaPath = \dirname(__DIR__, 3) . '/docs/config-schema.json';
        $this->assertFileExists($schemaPath);
        $schema = json_decode((string)file_get_contents($schemaPath), true);
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('accepted', $schema['properties'] ?? [], 'the schema row exists');
    }

    public function testLoadThreadRootConfigThroughToConfigProperty(): void
    {
        $path = $this->tmpDir . '/phpdup.json';
        file_put_contents($path, json_encode([
            'paths' => [$this->tmpDir],
            'accepted' => ['Faaa1111222233334', 'Fbbb1111222233334'],
        ]));

        $loader = new ConfigLoader(warnOnSkip: false);
        $config = $loader->load([$this->tmpDir], $path);
        $this->assertInstanceOf(Config::class, $config);
        $this->assertSame(
            ['Faaa1111222233334', 'Fbbb1111222233334'],
            $config->accepted,
            'load() threads the accepted register through the Config property',
        );
    }

    public function testLoadDefaultsEmptyListForAbsentKey(): void
    {
        $loader = new ConfigLoader(warnOnSkip: false);
        $config = $loader->load([$this->tmpDir], null);
        $this->assertSame([], $config->accepted, 'no accepted key -> empty register');
    }

    public function testPerDirectoryAcceptedKeyFailsTheLoad(): void
    {
        // The suppression register is never honored from a scanned subtree
        // (item 4.4: same posture as the path/ref family).
        $root = $this->tmpDir . '/root';
        $sub = $root . '/sub';
        mkdir($sub, 0o775, true);
        file_put_contents($sub . '/.phpdup.json', json_encode(['accepted' => ['Faaa1111222233334']]));

        $loader = new ConfigLoader(warnOnSkip: false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('accepted is not a per-directory key (root phpdup.json only):');
        $loader->load([$root], null);
    }

    public function testConfigAcceptsDefaultsToEmptyList(): void
    {
        $config = new Config(paths: ['/x'], exclude: []);
        $this->assertSame([], $config->accepted, 'the Config property exists and defaults to []');
    }
}
