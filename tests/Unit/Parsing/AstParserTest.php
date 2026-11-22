<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parsing;

use PhpParser\Error;
use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;

/**
 * Pins the engine-026 fix: parseFile() records $lastError on read failures
 * (missing, removed-after-discovery, permission-denied) — not only on parse
 * errors — names the path in every such message, and clears $lastError on a
 * successful parse. Callers (PreprocessWorker) report
 * lastError?->getMessage() ?? 'parse error', so the read-failure contract
 * must stay inside the ?Error property type.
 */
final class AstParserTest extends TestCase
{
    private string $tmp;
    private AstParser $parser;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-astparser-' . uniqid();
        mkdir($this->tmp, 0o775, true);
        $this->parser = new AstParser();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tmp . '/*') as $f) {
            if (is_string($f) && is_file($f)) {
                @chmod($f, 0o644);
                @unlink($f);
            }
        }
        @rmdir($this->tmp);
    }

    public function testReadableFileParsesAndCarriesNoError(): void
    {
        $file = $this->writeSource('Ok.php', "<?php\nfunction greet() { echo 'hi'; }\n");

        $stmts = $this->parser->parseFile($file);

        $this->assertNotNull($stmts);
        $this->assertNotEmpty($stmts);
        foreach ($stmts as $stmt) {
            $this->assertInstanceOf(Node\Stmt::class, $stmt);
        }
        $this->assertNull($this->parser->lastError);
    }

    public function testMissingFileReturnsNullWithLastErrorNamingPath(): void
    {
        $missing = $this->tmp . '/never-existed.php';

        $this->assertNull($this->parser->parseFile($missing));
        $this->assertInstanceOf(Error::class, $this->parser->lastError);

        $message = (string) $this->parser->lastError?->getMessage();
        $this->assertStringContainsString('could not read file', $message);
        $this->assertStringContainsString($missing, $message);
        $this->assertStringContainsString('No such file or directory', $message);
    }

    public function testFileRemovedAfterDiscoveryReturnsNullWithLastError(): void
    {
        $file = $this->writeSource('Gone.php', "<?php\nreturn 1;\n");
        unlink($file);

        $this->assertNull($this->parser->parseFile($file));
        $message = (string) $this->parser->lastError?->getMessage();
        $this->assertStringContainsString('could not read file', $message);
        $this->assertStringContainsString($file, $message);
    }

    public function testUnreadableFileReturnsNullWithLastErrorNamingPath(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped(
                'chmod-000 is readable by root (engine-026 acceptance caveat); '
                . 'the missing-file tests above pin the read-failure contract root-safely'
            );
        }

        $file = $this->writeSource('Secret.php', "<?php\nreturn 1;\n");
        chmod($file, 0o000);

        $this->assertNull($this->parser->parseFile($file));
        $message = (string) $this->parser->lastError?->getMessage();
        $this->assertStringContainsString('could not read file', $message);
        $this->assertStringContainsString($file, $message);
        $this->assertStringContainsString('Permission denied', $message);
    }

    public function testSyntaxErrorStillRecordedAsParseError(): void
    {
        $file = $this->writeSource('Broken.php', "<?php\nfunction broken( {\n");

        $this->assertNull($this->parser->parseFile($file));
        $this->assertInstanceOf(Error::class, $this->parser->lastError);
        $this->assertStringContainsString(
            'Syntax error',
            (string) $this->parser->lastError?->getMessage()
        );
    }

    public function testSuccessfulParseClearsStaleReadError(): void
    {
        $this->assertNull($this->parser->parseFile($this->tmp . '/absent.php'));
        $this->assertNotNull($this->parser->lastError);

        $file = $this->writeSource('Fresh.php', "<?php\nreturn 2;\n");
        $stmts = $this->parser->parseFile($file);

        $this->assertNotNull($stmts);
        $this->assertNull($this->parser->lastError, 'success must clear the earlier read error');
    }

    private function writeSource(string $name, string $body): string
    {
        $path = $this->tmp . '/' . $name;
        $written = file_put_contents($path, $body);
        self::assertIsInt($written, "fixture {$name} must be written");

        return $path;
    }
}
