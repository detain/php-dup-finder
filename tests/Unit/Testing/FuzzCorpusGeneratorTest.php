<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Phpdup\Testing\FuzzCorpusGenerator;

/**
 * Pins the php-dup-finder-output-061 hardening: a fuzz plan is DATA,
 * never code. Template names are whitelisted before any write (path
 * traversal sink closed); hole values land as var_export literals
 * (code-injection sink closed); every generated file stays `php -l`
 * clean regardless of plan content.
 *
 * @see FuzzCorpusGenerator
 */
final class FuzzCorpusGeneratorTest extends TestCase
{
    /** @var list<string> directories to sweep in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s09-' . getmypid() . '-' . uniqid('', true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function unsafeTemplateNames(): array
    {
        return [
            'parent traversal'   => ['../x'],
            'leading dotdot'     => ['..'],
            'single dot'         => ['.'],
            'embedded slash'     => ['a/b'],
            'backslash escape'   => ['a\\b'],
            'path to root'       => ['../../etc/passwd'],
            'space'              => ['foo bar'],
            'dollar interpolation' => ['$evil'],
            'quote breakout'     => ["a'b"],
            'php tag'            => ['<?php'],
            'dot extension'      => ['evil.php'],
        ];
    }

    /**
     * @param array<string, list<array<string,string>>> $plan
     * @return list<array{file:string,template:string,row:int}>
     */
    private function assertParsesAsPhp(string $dir, array $plan): array
    {
        $generator = new FuzzCorpusGenerator(42);
        $manifest  = $generator->generate($dir, $plan);
        self::assertNotEmpty($manifest, 'generator wrote nothing for a valid plan');
        foreach ($manifest as $entry) {
            $file = (string)$entry['file'];
            self::assertFileExists($file);
            self::assertSame(
                0,
                self::lint($file),
                "generated file is not php -l clean:\n" . (string)file_get_contents($file)
            );
        }
        return $manifest;
    }

    private static function lint(string $file): int
    {
        $out = [];
        $rc  = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1',
            $out,
            $rc,
        );
        return $rc;
    }

    /**
     * @dataProvider unsafeTemplateNames
     */
    public function testUnsafeTemplateNamesThrowFailLoud(string $template): void
    {
        $generator = new FuzzCorpusGenerator(42);
        $dir       = $this->tempDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/safe identifier/');

        try {
            $generator->generate($dir, [$template => [['value' => '1']]]);
        } finally {
            // Fail-fast must be atomic: nothing written before the throw.
            self::assertSame(
                [],
                glob($dir . '/*.php') ?: [],
                'a rejected plan must leave no generated files behind',
            );
        }
    }

    /**
     * @dataProvider safeTemplateNames
     */
    public function testSafeTemplateNamesGenerateParsableFiles(string $template): void
    {
        $this->assertParsesAsPhp($this->tempDir(), [
            $template => [
                ['value' => '10', 'threshold' => '20', 'callee' => 'doThing'],
                [], // defaults exercised too
            ],
        ]);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function safeTemplateNames(): array
    {
        return [
            'plain'      => ['guard'],
            'hyphen'     => ['alpha-1'],
            'underscore' => ['A_b'],
            'digit-initial' => ['9lives'],
        ];
    }

    public function testHoleValueIsRenderedAsQuotedLiteralNotCode(): void
    {
        $evil = "'); system('id'); //";
        $dir  = $this->tempDir();

        $this->assertParsesAsPhp($dir, [
            'tpl' => [['value' => $evil]],
        ]);

        $code = (string)file_get_contents($dir . '/tpl_000.php');
        // The payload must appear ONLY in its var_export-escaped literal
        // form — i.e. inert string data, never a breakout.
        self::assertStringContainsString(var_export($evil, true), $code);
        self::assertStringNotContainsString(
            "\$this->doThing({$evil}",
            $code,
            'raw plan text must never be interpolated into the call position',
        );
        // And the file already passed `php -l` inside the helper.
    }

    public function testHostileCalleeAndThresholdAreNeutralised(): void
    {
        $dir = $this->tempDir();
        $this->assertParsesAsPhp($dir, [
            'tpl' => [[
                'value'     => "1); system('id'); //",
                'threshold' => "0 or system('id')",
                'callee'    => "m' ); system('id'); //",
            ]],
        ]);
    }

    public function testManifestShapeAndFileCount(): void
    {
        $dir = $this->tempDir();
        $generator = new FuzzCorpusGenerator(7);
        $manifest = $generator->generate($dir, [
            'guard' => [
                ['value' => '1', 'threshold' => '2', 'callee' => 'doThing'],
                ['value' => '3', 'threshold' => '4', 'callee' => 'doThing'],
            ],
            'retry' => [
                ['value' => '5', 'threshold' => '6', 'callee' => 'doThing'],
            ],
        ]);

        self::assertCount(3, $manifest);
        foreach ($manifest as $entry) {
            self::assertArrayHasKey('file', $entry);
            self::assertArrayHasKey('template', $entry);
            self::assertArrayHasKey('row', $entry);
            self::assertFileExists($entry['file']);
            self::assertIsString($entry['template']);
            self::assertIsInt($entry['row']);
            self::assertSame(
                sprintf('%s/%s_%03d.php', $dir, $entry['template'], $entry['row']),
                $entry['file'],
            );
        }
        self::assertSame(['guard', 'guard', 'retry'], array_column($manifest, 'template'));
        self::assertSame([0, 1, 0], array_column($manifest, 'row'));
    }

    public function testSameSeedIsDeterministicAcrossGenerators(): void
    {
        $plan = ['tpl' => [[], ['value' => '9']]];

        $dirA = $this->tempDir();
        $dirB = $this->tempDir();
        (new FuzzCorpusGenerator(42))->generate($dirA, $plan);
        (new FuzzCorpusGenerator(42))->generate($dirB, $plan);

        $a = (string)file_get_contents($dirA . '/tpl_000.php');
        $b = (string)file_get_contents($dirB . '/tpl_000.php');
        self::assertSame($a, $b, 'a fixed seed must reproduce identical bytes');

        // The digit-only default hole renders as an int literal, proving
        // the var_export path (not a quoted bareword) and the dynamic
        // $this->{'method'}() call shape.
        self::assertMatchesRegularExpression('/\$this->\{\x27doThing\x27\}\(\d+, \$item\)/', $a);
    }
}
