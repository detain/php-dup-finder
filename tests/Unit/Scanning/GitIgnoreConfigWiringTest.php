<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Scanning;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Command;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 5.2's .gitignore leg (W2a) — the train around the scanner: the
 * `honor_gitignore` root key must (a) default OFF, (b) be settable from a
 * root config file, (c) be settable from the CLI override dict, (d) reject
 * non-boolean payloads with the era message voice, (e) be REFUSED in
 * per-directory .phpdup.json files (whole-run stance, the db_aware /
 * window_oversized precedent), (f) survive Config derivation through
 * withOverrides(), and (g) be registered as --honor-gitignore.
 *
 * The refusal-voice and admission rosters are belt-guarded repo-wide
 * (tests/Unit/Cli/PerDirOverrideBeltTest.php, ConfigSchemaParityTest);
 * this file re-pins the honor_gitignore legs in self-contained form so a
 * future key churn cannot silently unhook THIS knob.
 */
final class GitIgnoreConfigWiringTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry) {
                if (is_link($entry) || is_file($entry)) {
                    @unlink($entry);
                } elseif (is_dir($entry)) {
                    @rmdir($entry);
                }
            }
            @rmdir($dir);
        }
    }

    private function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/w2a-cfg-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0777, true) || !is_dir($dir)) {
            $this->fail('could not create scratch dir ' . $dir);
        }
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /** @return array{0:string,1:string} [root, root/a.php placeholder written] */
    private function tree(): array
    {
        $root = $this->tmp();
        file_put_contents($root . '/a.php', "<?php\n\$x = 1;\n");
        return [$root, $root . '/a.php'];
    }

    public function testDefaultIsOff(): void
    {
        [$root] = $this->tree();
        $config = (new ConfigLoader(warnOnSkip: false))->load([$root], null);
        self::assertFalse($config->honorGitignore, 'honor_gitignore must default OFF — goldens depend on inertness');
    }

    public function testRootConfigFileTurnsItOn(): void
    {
        [$root] = $this->tree();
        $file = $root . '/phpdup.json';
        file_put_contents($file, (string) json_encode(['honor_gitignore' => true]));
        $config = (new ConfigLoader(warnOnSkip: false))->load([$root], $file);
        self::assertTrue($config->honorGitignore);
    }

    public function testOverrideDictTurnsItOn(): void
    {
        [$root] = $this->tree();
        $config = (new ConfigLoader(warnOnSkip: false))->load([$root], null, ['honor_gitignore' => true]);
        self::assertTrue($config->honorGitignore);
    }

    public function testNonBooleanPayloadIsRejected(): void
    {
        [$root] = $this->tree();
        $file = $root . '/phpdup.json';
        file_put_contents($file, (string) json_encode(['honor_gitignore' => 'yes']));

        $loader = new ConfigLoader(warnOnSkip: false);
        try {
            $loader->load([$root], $file);
            self::fail('a string honor_gitignore must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('honor_gitignore must be a boolean', $e->getMessage());
        }
    }

    public function testKeyIsRefusedInPerDirectoryFile(): void
    {
        [$root] = $this->tree();
        $sub = $root . '/sub';
        if (!mkdir($sub)) {
            $this->fail('could not create ' . $sub);
        }
        file_put_contents($sub . '/.phpdup.json', (string) json_encode(['honor_gitignore' => true]));

        $loader = new ConfigLoader(warnOnSkip: false);
        try {
            $loader->load([$root], null);
            self::fail('honor_gitignore is a root-only key and must be refused per-directory');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith(
                'honor_gitignore is not a per-directory key (root phpdup.json only): ',
                $e->getMessage(),
                'refusal must keep the era message voice (root-name FIRST, then the file)',
            );
            self::assertStringContainsString($sub . '/.phpdup.json', $e->getMessage());
        }
    }

    public function testFlagSurvivesWithOverridesDerivation(): void
    {
        [$root] = $this->tree();
        $file = $root . '/phpdup.json';
        file_put_contents($file, (string) json_encode(['honor_gitignore' => true]));
        $config = (new ConfigLoader(warnOnSkip: false))->load([$root], $file);

        $derived = $config->withOverrides(['allowed_kinds' => ['method']]);
        self::assertInstanceOf(Config::class, $derived);
        self::assertTrue($derived->honorGitignore, 'withOverrides() must passthrough the honored root flag');
        self::assertSame(['method'], $derived->allowedKinds, 'the unrelated override still applies');
    }

    public function testCliFlagIsRegistered(): void
    {
        $definition = (new Command())->getDefinition();
        self::assertTrue($definition->hasOption('honor-gitignore'));
        self::assertFalse($definition->getOption('honor-gitignore')->isValueRequired());
    }
}
