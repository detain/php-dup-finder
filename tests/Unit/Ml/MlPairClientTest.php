<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Ml\MlPairClient;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;

final class MlPairClientTest extends TestCase
{
    public function testEmptyBaseUrlReturnsNull(): void
    {
        $client = new MlPairClient('');
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    public function testInvalidUrlReturnsNull(): void
    {
        $client = new MlPairClient('file:///etc/passwd');
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    /**
     * @dataProvider rejectedUrlProvider
     */
    public function testRejectsUnsafeProtocols(string $url): void
    {
        $client = new MlPairClient($url);
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    /** @return array<string,array{0:string}> */
    public static function rejectedUrlProvider(): array
    {
        return [
            'file scheme'   => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'ftp scheme'    => ['ftp://example.com/foo'],
            'no host'       => ['http:///score-pair'],
            '0.0.0.0 host'  => ['http://0.0.0.0:8000/score-pair'],
        ];
    }

    public function testUnreachableServerReturnsNull(): void
    {
        // 127.0.0.1:1 is essentially guaranteed to be closed; the
        // client should return null rather than throw.
        $client = new MlPairClient('http://127.0.0.1:1', timeoutSec: 1);
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    private function makeBlock(): Block
    {
        $stmts = (new AstParser())->parseCode('<?php function f() { return 1; }');
        $block = new Block(
            file: 'test.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'f',
            ast: $stmts[0],
        );
        // Normalize so $canonical is initialised before feature
        // extraction runs against it.
        (new \Phpdup\Normalization\Normalizer(mode: 'aggressive'))->normalize($block);
        return $block;
    }

    /**
     * @dataProvider httpCodeProvider
     * @param list<string> $headers
     */
    public function testParseStatusCode(array $headers, int $expectedCode): void
    {
        $client = new MlPairClient('http://example.com');
        $r = new \ReflectionMethod($client, 'parseStatusCode');
        $r->setAccessible(true);
        self::assertSame($expectedCode, $r->invoke($client, $headers));
    }

    /** @return array<string,array{0:list<string>,1:int}> */
    public static function httpCodeProvider(): array
    {
        return [
            '200 OK'           => [['HTTP/1.1 200 OK', 'Content-Type: application/json'], 200],
            '201 Created'      => [['HTTP/1.1 201 Created', 'Content-Type: application/json'], 201],
            '299 OK'           => [['HTTP/1.1 299 OK'], 299],
            '400 Bad Request'  => [['HTTP/1.1 400 Bad Request'], 400],
            '500 Internal Err' => [['HTTP/1.1 500 Internal Server Error'], 500],
            '502 Bad Gateway'  => [['HTTP/1.1 502 Bad Gateway'], 502],
            'no headers'       => [[], 0],
            'no HTTP prefix'   => [['Content-Type: application/json'], 0],
            'HTTP/2 200'       => [['HTTP/2 200 OK'], 200],
        ];
    }

    /**
     * @group network
     */
    public function testNon2xxResponseReturnsNull(): void
    {
        $client = new MlPairClient('https://httpbin.org/status/500', timeoutSec: 10);
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }
}
